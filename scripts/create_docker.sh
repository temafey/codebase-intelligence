#!/bin/bash

# Скрипт для создания Docker-конфигурации

# Принимаем путь для установки
TARGET_DIR="$1"

echo "Создание Docker-конфигурации в $TARGET_DIR..."

# Создаем Dockerfile
cat > "$TARGET_DIR/Dockerfile" << 'EOT'
FROM php:8.4-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    zip \
    unzip \
    libzip-dev \
    oniguruma-dev \
    $PHPIZE_DEPS

# Install PHP extensions
RUN docker-php-ext-install \
    zip \
    fileinfo \
    mbstring \
    pcntl

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install parallel extension for better performance
RUN pecl install parallel && docker-php-ext-enable parallel

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files
COPY composer.json composer.lock* ./

# Install dependencies without scripts for better caching
RUN composer install --no-scripts --no-autoloader --no-dev

# Copy remaining application code
COPY . .

# Generate optimized autoloader
RUN composer dump-autoload --optimize

# Add directory for application storage
RUN mkdir -p /storage && chmod 777 /storage

# Make bin file executable
RUN chmod +x bin/code-intelligence

# Use non-root user for better security
RUN addgroup -g 1000 claude && \
    adduser -u 1000 -G claude -h /app -D claude && \
    chown -R claude:claude /app /storage

USER claude

ENTRYPOINT ["php", "/app/bin/code-intelligence"]
CMD ["--help"]
EOT

# Создаем docker-compose.yml
cat > "$TARGET_DIR/docker-compose.yml" << 'EOT'
version: '3.8'

services:
  codebase-intelligence:
    build:
      context: .
      dockerfile: Dockerfile
    volumes:
      - .:/app
      - ${CODEBASE_PATH:-./}:/codebase
      - ${STORAGE_DIR:-./storage}:/storage
    env_file:
      - .env
    environment:
      - CODEBASE_PATH=/codebase
      - STORAGE_DIR=/storage
    command: ["php", "/app/bin/code-intelligence"]
    tty: true

  codebase-updater:
    build:
      context: .
      dockerfile: Dockerfile
    volumes:
      - .:/app
      - ${CODEBASE_PATH:-./}:/codebase
      - ${STORAGE_DIR:-./storage}:/storage
    env_file:
      - .env
    environment:
      - CODEBASE_PATH=/codebase
      - STORAGE_DIR=/storage
    command: ["php", "/app/bin/code-intelligence", "update", "--schedule", "daily"]

  redis:
    image: redis:alpine
    ports:
      - "6379:6379"
    volumes:
      - redis-data:/data
    command: redis-server --appendonly yes

  php:
    build:
      context: .
      dockerfile: Dockerfile
    volumes:
      - .:/app
      - ${CODEBASE_PATH:-./}:/codebase
      - ${STORAGE_DIR:-./storage}:/storage
    env_file:
      - .env
    working_dir: /app
    command: ["php", "-a"]
    tty: true

volumes:
  redis-data:
EOT

echo "Создание Docker-конфигурации завершено."