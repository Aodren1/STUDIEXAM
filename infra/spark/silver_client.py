"""
Job Spark Structured Streaming — topics src.ab.client + src.ap.client -> /lake/silver/client
Transposition à l'échelle de la règle implémentée dans app/src/Stage/Harmonisation.php.

spark-submit --master spark://abf-traitement-spark-master:7077 \
  --packages org.apache.spark:spark-sql-kafka-0-10_2.12:3.5.1 /opt/abf/jobs/silver_client.py
"""
from pyspark.sql import SparkSession, functions as F

spark = SparkSession.builder.appName("abf-silver-client").getOrCreate()

raw = (spark.readStream.format("kafka")
       .option("kafka.bootstrap.servers", "abf-flux-kafka:9092")
       .option("subscribe", "src.ab.client,src.ap.client")
       .option("startingOffsets", "earliest")
       .load()
       .select(F.col("topic"), F.col("value").cast("string").alias("json")))

after = F.get_json_object
ab = (raw.filter("topic = 'src.ab.client'").select(
    F.concat(F.lit("AB-"), after("json", "$.payload.after.AB_CLIENT_ID")).alias("client_uid"),
    F.lit("AB").alias("source"),
    F.upper(after("json", "$.payload.after.AB_NOM")).alias("nom"),
    F.initcap(after("json", "$.payload.after.AB_PRENOM")).alias("prenom"),
    F.to_date(after("json", "$.payload.after.AB_DATE_NAISSANCE")).alias("date_naissance"),
    F.lower(F.trim(after("json", "$.payload.after.AB_EMAIL"))).alias("email"),
    after("json", "$.payload.after.AB_CODE_POSTAL").alias("code_postal"),
    after("json", "$.payload.after.AB_STATUT_CLIENT").alias("statut")))

full = after("json", "$.payload.after.AP_FULL_NAME")
birth = after("json", "$.payload.after.AP_BIRTH_DATE")
ap = (raw.filter("topic = 'src.ap.client'").select(
    F.concat(F.lit("AP-"), after("json", "$.payload.after.AP_USER_ID")).alias("client_uid"),
    F.lit("AP").alias("source"),
    # mots en capitales = nom de famille (règle DQ04)
    F.upper(F.regexp_extract(full, r"((?:\b[A-ZÀ-Ý][A-ZÀ-Ý'\-]+\b\s?)+)", 1)).alias("nom"),
    F.initcap(F.trim(F.regexp_replace(full, r"\b[A-ZÀ-Ý][A-ZÀ-Ý'\-]+\b", ""))).alias("prenom"),
    # VARCHAR(10) multi-formats (règles DQ01 / DQ02)
    F.coalesce(F.to_date(birth, "yyyy-MM-dd"), F.to_date(birth, "dd/MM/yyyy"), F.to_date(birth, "yyyy/MM/dd")).alias("date_naissance"),
    F.lower(F.trim(after("json", "$.payload.after.AP_MAIL_ADDRESS"))).alias("email"),
    after("json", "$.payload.after.AP_ZIP_CODE").alias("code_postal"),
    F.upper(after("json", "$.payload.after.AP_CUSTOMER_STATUS")).alias("statut_brut")))

statut_ap = F.create_map(*[F.lit(x) for kv in {"ACTIVE": "ACTIF", "INACTIVE": "INACTIF", "CHURNED": "RESILIE", "LEAD": "PROSPECT"}.items() for x in kv])
ap = ap.withColumn("statut", statut_ap[F.col("statut_brut")]).drop("statut_brut")

(ab.unionByName(ap)
   .withColumn("date_ingestion", F.current_date())
   .writeStream.format("parquet")
   .option("path", "hdfs://abf-stockage-namenode:9000/lake/silver/client")
   .option("checkpointLocation", "hdfs://abf-stockage-namenode:9000/lake/_checkpoints/silver_client")
   .partitionBy("source", "date_ingestion")
   .trigger(processingTime="1 minute")
   .start()
   .awaitTermination())
