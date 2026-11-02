FROM php:8.4-cli-alpine
USER root
RUN apk add --no-cache bash procps iproute2 python3 py3-pip git unzip lsof \
        libstdc++ \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install -j2 pcntl \
    && pecl install openswoole-26.2.0 \
    && docker-php-ext-enable openswoole \
    && apk del .build-deps \
    && python3 -m venv /opt/locust
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY benchmark/locust/requirements.txt /tmp/locust-requirements.txt
RUN /opt/locust/bin/pip install --no-cache-dir -r /tmp/locust-requirements.txt
ENV PATH="/opt/locust/bin:${PATH}"
WORKDIR /workspace
CMD ["bash"]
