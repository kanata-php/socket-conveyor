# syntax=docker/dockerfile:1
# PHP 8.5.12 is the current stable security release (checked 2026-11-03).
FROM php:8.5.12-cli-alpine@sha256:e899a75c775804e5466dbd9c96b31f8b6c6ae2ddb053a8466223a0c32992ff8e AS php-runtime

RUN apk upgrade --no-cache \
    && apk add --no-cache libstdc++ \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers openssl-dev \
    && docker-php-ext-install -j2 pcntl \
    && pecl install --configureoptions 'enable-openssl="yes"' openswoole-26.2.0 \
    && docker-php-ext-enable openswoole \
    && apk del .build-deps \
    && rm -rf /tmp/pear /root/.cache \
    && php --ri openswoole

FROM php-runtime AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /opt/conveyor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-plugins --prefer-dist --no-interaction --no-progress --classmap-authoritative
COPY src ./src
RUN composer dump-autoload --no-dev --no-scripts --no-plugins --classmap-authoritative \
    && composer check-platform-reqs --no-dev \
    && composer audit --locked --no-dev

FROM php-runtime AS conveyor
LABEL org.opencontainers.image.title="Socket Conveyor" \
      org.opencontainers.image.source="https://github.com/kanata-php/socket-conveyor" \
      org.opencontainers.image.licenses="MIT"
WORKDIR /opt/conveyor
COPY --from=dependencies /opt/conveyor/vendor ./vendor
COPY src ./src
COPY docker/server.php ./docker/server.php
COPY LICENSE.md ./LICENSE.md
RUN addgroup -S -g 10001 conveyor && adduser -S -D -H -u 10001 -G conveyor conveyor
USER conveyor
ENV CONVEYOR_HOST=0.0.0.0 CONVEYOR_PORT=8080
EXPOSE 8080
STOPSIGNAL SIGTERM
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD php -r '$s = @fsockopen("127.0.0.1", (int) getenv("CONVEYOR_PORT"), $errno, $error, 2); if (!$s) { exit(1); } fclose($s);'
CMD ["php", "/opt/conveyor/docker/server.php"]
