
FROM php:8.3-apache

ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV APP_SECRET=build-secret-change-at-runtime
ENV DATABASE_URL="postgresql://app:app@127.0.0.1:5432/app?serverVersion=16&charset=utf8"

RUN apt-get update && apt-get install -y \
    unzip \
    libpq-dev \
    libicu-dev \
    && docker-php-ext-install pdo_pgsql intl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY messagerie_github_source.zip /tmp/source.zip

RUN unzip /tmp/source.zip -d /var/www/html \
    && rm /tmp/source.zip

RUN a2enmod rewrite \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\nAllowOverride None\nRequire all granted\nFallbackResource /index.php\n</Directory>\n' > /etc/apache2/conf-available/symfony.conf \
    && a2enconf symfony

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader

RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf

EXPOSE 10000

CMD ["apache2-foreground"]
