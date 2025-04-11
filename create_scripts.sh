#!/bin/bash

# Создание структуры скриптов для Codebase Intelligence

# Список скриптов для создания
SCRIPTS=(
    "setup.sh"
    "scripts/install.sh"
    "scripts/create_directories.sh"
    "scripts/create_composer.sh"
    "scripts/create_docker.sh"
    "scripts/create_makefile.sh"
    "scripts/create_readme.sh"
    "scripts/create_api.sh"
    "scripts/create_analysis.sh"
    "scripts/create_cache.sh"
    "scripts/create_metrics.sh"
    "scripts/create_session.sh"
    "scripts/create_commands.sh"
    "scripts/create_utils.sh"
    "scripts/create_bin.sh"
    "scripts/create_env.sh"
)

# Создаем директорию scripts
mkdir -p scripts

# Создаем каждый скрипт
for script in "${SCRIPTS[@]}"; do
    # Формируем название для заголовка (преобразуем create_something.sh в Create Something)
    title=$(basename "$script" | sed 's/.sh$//' | tr '_' ' ' | sed 's/\b\(.\)/\u\1/g')
    
    # Создаем скрипт с базовой структурой
    cat > "$script" << EOF2
#!/bin/bash

# $title script

# Принимаем путь для установки
TARGET_DIR="\$1"

echo "Выполняется $(basename $script)..."

# Ваш код здесь

echo "Скрипт $(basename $script) завершен."
EOF2

    # Делаем скрипт исполняемым
    chmod +x "$script"
    echo "Создан скрипт: $script"
done

echo "Все скрипты успешно созданы!"
