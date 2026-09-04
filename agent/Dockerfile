FROM php:8.4-alpine

RUN apk add --no-cache linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install pcntl sockets \
    && apk del linux-headers $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /watchtower-agent
COPY version.txt LICENSE.md ./
COPY agent/composer.json agent/composer.lock ./agent/

WORKDIR /watchtower-agent/agent
RUN composer install --no-dev --no-interaction --no-autoloader --no-scripts

COPY agent/src ./src
COPY agent/helpers.php agent/watchtower-status ./
COPY agent/docker/entrypoint.sh ./entrypoint.sh
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && printf '' > /watchtower-agent/agent/signature.txt \
    && chmod +x entrypoint.sh watchtower-status

ENV WATCHTOWER_INGEST_URI=0.0.0.0:2407

HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 CMD ["php", "watchtower-status"]
ENTRYPOINT ["./entrypoint.sh"]
