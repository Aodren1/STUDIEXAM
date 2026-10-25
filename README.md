# Plateforme Data AbAssurance × AssurePlus — Bloc 2

Examen Bachelor Concepteur Développeur Big Data, **Bloc 2 : gérer itérativement le cycle de vie d’une solution digitale**.
Ce dépôt contient le logiciel de validation spécifié au Bloc 1, sa campagne de tests automatisés, l’intégration continue et les sources du dossier.

- Backlog Scrum (EPICs, User Stories, DoR, DoD, anomalies) : https://trello.com/b/N4SHAra8/exam2-studi
- Intégration continue : onglet **Actions** de ce dépôt

## Contenu

| Dossier | Rôle |
|---|---|
| `app/` | Logiciel de validation : sources simulées (Oracle `AB_*`, SQL Server `AP_*`), pipeline bronze → silver → gold (rôles Talend / Kafka / Spark / HDFS), tableau de bord |
| `app/tests/` | Campagne de tests : unitaires (PHP, JS), intégration, API et sécurité, E2E (Chrome headless), charge |
| `reports/` | Rapports des campagnes : `run-1-preprod` (41/52) et `run-2-correctifs` (59/59) |
| `.github/workflows/ci.yml` | CI : analyse statique → campagne complète → recette sur l’image Docker de production |
| `docs/` | Sources du dossier (HTML → PDF), backlog Trello (`docs/trello/*.json`), captures |
| `infra/` | Configuration cible de la plateforme (Kafka Connect CDC, SQL des sources, Spark) |

## Lancer le logiciel et les tests

Prérequis : PHP 8.3 (avec `pdo_sqlite`), Node 22 ou plus, Chrome ou Edge.

```bash
cd app
node tests/run-all.mjs --label local
```

L’orchestrateur génère les données, exécute le pipeline, démarre un serveur local puis lance les 6 suites. Il écrit `reports/local/rapport.json` et les captures d’écran.

Tableau de bord seul :

```bash
cd app && php bin/seed.php && php bin/pipeline.php
php -S 127.0.0.1:8766 -t public bin/serve.php
```

Image de production : `docker compose up -d --build`, puis http://localhost:8095

Variables utiles : `APP_REF_DATE` (date de référence des indicateurs, défaut : date du jour) et `SEED_SCALE` (facteur de volume pour les tests de volumétrie).

## Résultats

| Campagne | Réussis | Détail |
|---|---|---|
| 1 — code livré au sprint 5 | 41 / 52 | 11 anomalies (dont une bloquante RGPD), voir `docs/trello/cartes.json` |
| 2 — après correctifs | 59 / 59 | p95 de l’ouverture du tableau de bord : 238 ms sous 20 utilisateurs |

Auteur : Aodren Floride.
