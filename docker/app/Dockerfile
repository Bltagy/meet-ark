FROM php:8.4-cli-alpine

RUN apk add --no-cache \
    bash \
    curl \
    git \
    postgresql-client \
    postgresql-dev \
    libzip-dev \
    nodejs \
    npm \
    icu-dev \
    linux-headers

RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    bcmath \
    zip \
    pcntl \
    intl \
    opcache

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 8088

COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8088"]
