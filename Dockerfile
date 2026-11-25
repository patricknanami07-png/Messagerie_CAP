# Image de production : Symfony 6.4 + Apache sur Render.
# Le code source d'origine est le ZIP du dépôt ; les corrections sont ajoutées par-dessus
# (fichiers PHP/YAML et templates.patch, à la racine du dépôt). PHP 8.3 : doctrine/dbal 4.5 exige PHP >= 8.2.
FROM php:8.3-apache

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    PORT=10000 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip patch libpq-dev libicu-dev libzip-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip opcache \
 && a2enmod rewrite headers \
 && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache écoute sur $PORT (fourni par Render) et sert uniquement le dossier public/.
COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY apache-vhost.conf /etc/apache2/sites-available/000-default.conf
RUN sed -i 's/Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf

WORKDIR /var/www/html

# 1) Code source d'origine
COPY messagerie_github_source.zip /tmp/source.zip
RUN unzip -q /tmp/source.zip -d /var/www/html && rm /tmp/source.zip

# 2) Corrections : fichiers PHP/YAML remplacés, puis patch des 4 templates
COPY HealthController.php MessagesController.php RegistrationController.php /var/www/html/src/Controller/
COPY MessagesRepository.php /var/www/html/src/Repository/
COPY security.yaml /var/www/html/config/packages/security.yaml
COPY templates.patch /tmp/templates.patch
RUN patch -p1 --no-backup-if-mismatch < /tmp/templates.patch && rm /tmp/templates.patch

# Symfony Dotenv exige qu'un fichier .env existe. Il reste VIDE dans l'image :
# les vraies valeurs (secrets inclus) viennent des variables d'environnement Render.
RUN test -f .env || echo "# Valeurs reelles fournies par les variables d'environnement" > .env

RUN composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader --classmap-authoritative

# Assets (base.html.twig utilise importmap('app')) puis cache de production préchauffé.
# Valeurs factices UNIQUEMENT pour ces commandes de build ; les vraies sont lues au démarrage.
RUN export APP_SECRET=valeur-de-build-non-secrete \
           DATABASE_URL="postgresql://build:build@localhost:5432/build?serverVersion=18" \
           MESSENGER_TRANSPORT_DSN="doctrine://default?auto_setup=0" \
           MAILER_DSN="null://null" \
 && php bin/console importmap:install --no-interaction \
 && php bin/console asset-map:compile --no-interaction \
 && php bin/console cache:warmup --env=prod --no-debug \
 && mkdir -p var/uploads var/log \
 && chown -R www-data:www-data var

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 10000
ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
