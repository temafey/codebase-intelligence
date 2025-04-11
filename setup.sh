#!/bin/bash

# Главный скрипт для создания и запуска всех скриптов установки

# Проверка наличия аргумента - пути для установки
if [ $# -eq 0 ]; then
    TARGET_DIR="./codebase-intelligence"
else
    TARGET_DIR="$1"
fi

# Создаем абсолютный путь
TARGET_DIR=$(realpath "$TARGET_DIR")

# Проверяем наличие директории scripts
SCRIPT_PATH=$(dirname "$(realpath "$0")")
SCRIPTS_DIR="$SCRIPT_PATH/scripts"

if [ ! -d "$SCRIPTS_DIR" ]; then
    # Если директории scripts нет, создаем временную
    SCRIPTS_DIR=$(mktemp -d)
    TEMP_SCRIPTS=true

    echo "Создаем временную директорию для скриптов: $SCRIPTS_DIR"

    # Создаем все скрипты во временной директории
    mkdir -p "$SCRIPTS_DIR"

    # Создаем все необходимые скрипты
    cat > "$SCRIPTS_DIR/create_directories.sh" << 'EOT'
#!/bin/bash
# Скрипт для создания структуры директорий проекта
TARGET_DIR="$1"
echo "Создание структуры директорий в $TARGET_DIR..."
DIRECTORIES=(
    "bin"
    "config"
    "public"
    "src/API"
    "src/Analysis"
    "src/Budget"
    "src/Cache"
    "src/Command"
    "src/Session"
    "src/Utils"
    "tests/unit"
    "tests/integration"
)
for DIR in "${DIRECTORIES[@]}"; do
    mkdir -p "$TARGET_DIR/$DIR"
    echo "  Создана директория: $DIR"
done
echo "Создание структуры директорий завершено."
EOT
    chmod +x "$SCRIPTS_DIR/create_directories.sh"

    # Добавьте сюда все остальные скрипты из артефактов
    # Например:
    cat > "$SCRIPTS_DIR/create_composer.sh" << 'EOT'
#!/bin/bash
# Скрипт для создания файла composer.json
TARGET_DIR="$1"
echo "Создание composer.json в $TARGET_DIR..."
cat > "$TARGET_DIR/composer.json" << 'COMPOSER'
{
    "name": "codebase-intelligence/claude-integration",
    "description": "A tool for integrating Claude 3.7 Sonnet with your codebase",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.4",
        "symfony/console": "^7.0",
        "symfony/dotenv": "^7.0",
        "guzzlehttp/guzzle": "^7.8",
        "monolog/monolog": "^3.5",
        "symfony/filesystem": "^7.0",
        "symfony/finder": "^7.0",
        "symfony/process": "^7.0",
        "symfony/yaml": "^7.0",
        "symfony/cache": "^7.0",
        "symfony/dependency-injection": "^7.0",
        "symfony/config": "^7.0",
        "amphp/parallel": "^2.0",
        "ext-json": "*",
        "ext-fileinfo": "*",
        "php-di/php-di": "^7.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "phpstan/phpstan": "^1.10",
        "symfony/var-dumper": "^7.0"
    },
    "autoload": {
        "psr-4": {
            "CodebaseIntelligence\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "CodebaseIntelligence\\Tests\\": "tests/"
        }
    },
    "bin": [
        "bin/code-intelligence"
    ],
    "scripts": {
        "test": "phpunit",
        "analyze": "phpstan analyze",
        "cs-fix": "php-cs-fixer fix"
    },
    "config": {
        "sort-packages": true,
        "optimize-autoloader": true
    },
    "minimum-stability": "stable"
}
COMPOSER
echo "Создание composer.json завершено."
EOT
    chmod +x "$SCRIPTS_DIR/create_composer.sh"

    # Создайте остальные скрипты из артефактов аналогичным образом
    # ...

    # Создаем install.sh скрипт
    cat > "$SCRIPTS_DIR/install.sh" << 'EOT'
#!/bin/bash
# Главный скрипт установки Codebase Intelligence для Claude 3.7 Sonnet
set -e  # Скрипт останавливается при ошибке
TARGET_DIR="$1"
if [ -z "$TARGET_DIR" ]; then
    TARGET_DIR="./codebase-intelligence"
fi
TARGET_DIR=$(realpath "$TARGET_DIR")
mkdir -p "$TARGET_DIR"
SCRIPT_DIR=$(dirname "$(realpath "$0")")

echo "Установка Codebase Intelligence в директорию: $TARGET_DIR"
echo "--------------------------------------------"

echo "Шаг 1: Создание структуры директорий..."
"$SCRIPT_DIR/create_directories.sh" "$TARGET_DIR"

echo "Шаг 2: Создание файлов композера..."
"$SCRIPT_DIR/create_composer.sh" "$TARGET_DIR"

echo "Шаг 3: Создание Docker-конфигурации..."
"$SCRIPT_DIR/create_docker.sh" "$TARGET_DIR"

echo "Шаг 4: Создание Makefile..."
"$SCRIPT_DIR/create_makefile.sh" "$TARGET_DIR"

echo "Шаг 5: Создание README..."
"$SCRIPT_DIR/create_readme.sh" "$TARGET_DIR"

echo "Шаг 6: Создание API модуля..."
"$SCRIPT_DIR/create_api.sh" "$TARGET_DIR"

echo "Шаг 7: Создание модуля анализа..."
"$SCRIPT_DIR/create_analysis.sh" "$TARGET_DIR"

echo "Шаг 8: Создание модуля кеширования..."
"$SCRIPT_DIR/create_cache.sh" "$TARGET_DIR"

echo "Шаг 9: Создание модуля метрик..."
"$SCRIPT_DIR/create_metrics.sh" "$TARGET_DIR"

echo "Шаг 10: Создание модуля сессий..."
"$SCRIPT_DIR/create_session.sh" "$TARGET_DIR"

echo "Шаг 11: Создание командных скриптов..."
"$SCRIPT_DIR/create_commands.sh" "$TARGET_DIR"

echo "Шаг 12: Создание утилит..."
"$SCRIPT_DIR/create_utils.sh" "$TARGET_DIR"

echo "Шаг 13: Создание исполняемых файлов..."
"$SCRIPT_DIR/create_bin.sh" "$TARGET_DIR"

echo "Шаг 14: Создание конфигурационных файлов..."
"$SCRIPT_DIR/create_env.sh" "$TARGET_DIR"

echo ""
echo "Установка завершена!"
echo "--------------------------------------------"
echo "Проект создан в директории: $TARGET_DIR"
echo ""
echo "Следующие шаги:"
echo "1. cd $TARGET_DIR"
echo "2. composer install"
echo "3. php bin/code-intelligence init --codebase-path=/путь/к/вашему/проекту"
echo ""
echo "Подробная информация доступна в файле README.md"
EOT
    chmod +x "$SCRIPTS_DIR/install.sh"

else
    TEMP_SCRIPTS=false
    echo "Используем существующие скрипты в директории: $SCRIPTS_DIR"
fi

# Запускаем главный скрипт установки
"$SCRIPTS_DIR/install.sh" "$TARGET_DIR"

# Очищаем временные файлы, если они были созданы
if [ "$TEMP_SCRIPTS" = true ]; then
    echo "Удаляем временную директорию со скриптами: $SCRIPTS_DIR"
    rm -rf "$SCRIPTS_DIR"
fi

echo "Установка успешно завершена!"