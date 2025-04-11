#!/bin/bash

# Главный скрипт установки Codebase Intelligence для Claude 3.7 Sonnet

set -e  # Скрипт останавливается при ошибке

# Путь, куда будет установлен проект
TARGET_DIR="$1"
if [ -z "$TARGET_DIR" ]; then
    TARGET_DIR="./codebase-intelligence"
fi

# Создаем абсолютный путь
TARGET_DIR=$(realpath "$TARGET_DIR")

# Создаем директорию проекта, если не существует
mkdir -p "$TARGET_DIR"

# Создаем временную директорию для скриптов установки
SCRIPT_DIR="$TARGET_DIR/install_scripts"
mkdir -p "$SCRIPT_DIR"

echo "Установка Codebase Intelligence в директорию: $TARGET_DIR"
echo "--------------------------------------------"

# Скопируем все скрипты установки в директорию установки
cp -f scripts/*.sh "$SCRIPT_DIR/"

# Делаем скрипты исполняемыми
chmod +x "$SCRIPT_DIR"/*.sh

# Запускаем скрипты установки по порядку
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

echo "Шаг 15: Создание файлов проекта..."
"$SCRIPT_DIR/create_php_classes.sh" "$TARGET_DIR"

# Удаляем временную директорию со скриптами
rm -rf "$SCRIPT_DIR"

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