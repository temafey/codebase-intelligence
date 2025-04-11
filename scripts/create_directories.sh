#!/bin/bash

# Скрипт для создания структуры директорий проекта

# Принимаем путь для установки
TARGET_DIR="$1"

echo "Создание структуры директорий в $TARGET_DIR..."

# Список директорий, которые нужно создать
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

# Создаем каждую директорию
for DIR in "${DIRECTORIES[@]}"; do
    mkdir -p "$TARGET_DIR/$DIR"
    echo "  Создана директория: $DIR"
done

echo "Создание структуры директорий завершено."