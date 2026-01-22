# POC backend: Laravel + stateless JWT auth + Tool Support bootstrap verifier

FROM composer:2 AS composer_stage

FROM php:8.2-cli

# System deps
RUN apt-get update \
  && apt-get install -y --no-install-recommends \
     git unzip sqlite3 libsqlite3-dev curl ca-certificates \
  && docker-php-ext-install pdo pdo_sqlite \
  && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer_stage /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/entrypoint.sh /entrypoint.sh
#COPY poc-overrides /opt/poc

RUN chmod +x /entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["/entrypoint.sh"]
