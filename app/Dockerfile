# Logiciel de validation — image autonome (sources simulées + pipeline + dashboard).
# Les données sont générées et le pipeline exécuté au build : l'image démarre prête à l'emploi.
FROM php:8.3-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/app/public
# Données de démonstration générées pour cette date ; en production (données réelles), ne pas définir : date du jour.
ENV APP_REF_DATE=2026-10-25
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && a2enmod headers deflate \
 && printf 'memory_limit=1G\nexpose_php=Off\ndisplay_errors=Off\nlog_errors=On\n' > /usr/local/etc/php/conf.d/app.ini
# En-têtes des fichiers statiques ; les réponses PHP envoient les leurs (src/bootstrap.php, security_headers()).
COPY docker/securite.conf /etc/apache2/conf-enabled/securite.conf

WORKDIR /var/www/app
COPY src ./src
COPY bin ./bin
COPY tests ./tests
COPY public ./public

RUN php tests/run.php \
 && php bin/seed.php \
 && php bin/pipeline.php \
 && php tests/integration.php \
 && rm -rf data/bus \
 && chown -R www-data:www-data data

EXPOSE 80
HEALTHCHECK --interval=60s --timeout=5s CMD php -r 'exit(@file_get_contents("http://127.0.0.1/api.php?f=pipeline") ? 0 : 1);'
