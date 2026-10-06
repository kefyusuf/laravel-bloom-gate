FROM php:8.4-cli-bookworm@sha256:f1d32fb402fffba0b3dd8ba8c0aca474c9e9f04395fa846eedea77c503257dee
RUN apt-get update && apt-get install -y --no-install-recommends libssl-dev libzip-dev libonig-dev unzip git \
    && docker-php-ext-install -j2 pdo_mysql pcntl sockets zip mbstring bcmath \
    && pecl install -D 'enable-sockets="yes" enable-openssl="yes" enable-http2="no" enable-mysqlnd="yes" enable-hook-curl="no" enable-cares="no" with-postgres="no"' openswoole-26.2.0 \
    && pecl install redis-6.3.0 apcu-5.1.28 \
    && docker-php-ext-enable openswoole redis apcu \
    && mv /usr/local/etc/php/conf.d/docker-php-ext-openswoole.ini /usr/local/etc/php/conf.d/zz-openswoole.ini \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2@sha256:9715c7f69044da2a212a5fbde29ee7da24e364d426560ae6367b060236f847d7 /usr/bin/composer /usr/local/bin/composer
RUN printf 'apc.enable_cli=1\nmemory_limit=1G\n' > /usr/local/etc/php/conf.d/demo.ini
WORKDIR /experiment
RUN apt-get update && apt-get install -y --no-install-recommends procps \
    && rm -rf /var/lib/apt/lists/*
