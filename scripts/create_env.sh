#!/bin/bash

# Скрипт для создания конфигурационных файлов (.env)

# Принимаем путь для установки
TARGET_DIR="$1"

echo "Создание конфигурационных файлов в $TARGET_DIR..."

# Создаем .env.example
cat > "$TARGET_DIR/.env.example" << 'EOT'
# API Configuration
CLAUDE_API_KEY=your_api_key_here
CLAUDE_API_URL=https://api.anthropic.com/v1
CLAUDE_MODEL=claude-3-7-sonnet-20250219

# Application Settings
APP_DEBUG=false
APP_LOG_LEVEL=info
APP_TIMEZONE=UTC

# Codebase Settings
CODEBASE_PATH=/path/to/your/codebase
CODEBASE_LANGUAGE=php # Options: php, python, golang
CODEBASE_INCLUDE_PATTERNS=*.php,*.js,*.html,*.css,*.md
CODEBASE_EXCLUDE_PATTERNS=vendor/*,node_modules/*,tests/*,storage/*

# Caching
CACHE_ENABLED=true
CACHE_TTL=604800 # 1 week in seconds
CACHE_STORAGE=filesystem # Options: filesystem, redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Cost Optimization
TOKEN_BUDGET_DAILY=50000
SESSION_TTL=28800 # 8 hours in seconds
OFF_PEAK_DISCOUNT=0.7 # 30% discount

# Team Settings
TEAM_SIZE=2
DAILY_CHANGES_AVERAGE=20 # Average number of file changes per day

# Session Management
SESSION_PREFIX=project-name
EOT

# Создаем копию .env.example как .env
cp "$TARGET_DIR/.env.example" "$TARGET_DIR/.env"

echo "Создание конфигурационных файлов завершено."