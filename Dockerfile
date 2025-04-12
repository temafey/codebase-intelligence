FROM php:8.4-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    zip \
    unzip \
    libzip-dev \
    oniguruma-dev \
    linux-headers \
    $PHPIZE_DEPS

# Install PHP extensions
RUN docker-php-ext-install \
    zip \
    fileinfo \
    mbstring \
    pcntl

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install and configure Xdebug
RUN pecl install xdebug && docker-php-ext-enable xdebug
RUN echo "xdebug.mode=develop,debug,coverage" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_port=9003" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Add directory for application storage
RUN mkdir -p /storage && chmod 777 /storage

# Use non-root user for better security
RUN addgroup -g 1000 claude && \
    adduser -u 1000 -G claude -h /app -D claude && \
    chown -R claude:claude /app /storage

USER claude
