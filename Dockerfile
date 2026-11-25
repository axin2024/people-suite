FROM php:8.4-apache

RUN docker-php-ext-install pdo_mysql \
    && php -r 'exit(extension_loaded("mbstring") ? 0 : 1);' \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /srv/people-suite
COPY deploy/apache-site.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/people-suite.ini
COPY bin/ bin/
COPY database/ database/
COPY public/ public/
COPY src/ src/
COPY LICENSE NOTICE ./
