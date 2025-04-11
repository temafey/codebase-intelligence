#!/bin/bash

# Скрипт для создания README.md

# Принимаем путь для установки
TARGET_DIR="$1"

echo "Создание README.md в $TARGET_DIR..."

# Создаем README.md
cat > "$TARGET_DIR/README.md" << 'EOT'
# Codebase Intelligence для Claude 3.7 Sonnet

Система для интеграции кодовой базы с Claude 3.7 Sonnet, обеспечивающая эффективное использование возможностей искусственного интеллекта для разработки, анализа и поддержки кода.

## Основные возможности

- 🧠 **Полное понимание кодовой базы** - Claude изучает всю структуру проекта для контекстно-зависимых ответов
- 💰 **Оптимизация затрат** - Семантическое разбиение кода, дифференциальные обновления и интеллектуальное кеширование
- 🔄 **Автоматизация рабочих процессов** - Утренняя синхронизация, автоматический анализ кода и помощь в код-ревью
- 📊 **Расчет бюджета** - Точные оценки стоимости использования Claude для вашей команды
- 🔌 **Легкая интеграция** - Работает с PHP, Python и Golang проектами через единый интерфейс

## Требования

- PHP 8.4+
- Ключ API Claude (модель claude-3-7-sonnet-20250219)
- Git (опционально, для улучшенной интеграции)
- Docker и Docker Compose (для контейнеризованного использования)
- Redis (опционально, для улучшенного кеширования)

## Установка

### С помощью Composer

```bash
composer require codebase-intelligence/claude-integration
```

### Через клонирование репозитория

```bash
git clone https://github.com/yourusername/codebase-intelligence.git
cd codebase-intelligence
composer install
```

### С использованием Docker

```bash
git clone https://github.com/yourusername/codebase-intelligence.git
cd codebase-intelligence
cp .env.example .env
# Отредактируйте .env файл и укажите ваш API-ключ Claude
make build
```

## Быстрый старт

1. **Инициализация проекта**

```bash
bin/code-intelligence init --codebase-path=/путь/к/вашему/проекту
```

2. **Настройка переменных окружения**

Отредактируйте созданный файл `.claude/.env` и укажите ваш API-ключ Claude:

```
CLAUDE_API_KEY=your_api_key_here
```

3. **Инициализация кодовой базы**

```bash
bin/code-intelligence ingest --codebase-path=/путь/к/вашему/проекту
```

4. **Проверка стоимости использования**

```bash
bin/code-intelligence budget --team-size=3 --changes-per-day=25
```

5. **Ежедневное обновление**

```bash
bin/code-intelligence update
```

6. **Интерактивный режим**

```bash
bin/code-intelligence shell
```

## Использование с Docker

Проект включает в себя полноценную Docker-конфигурацию для удобного запуска в контейнерах.

```bash
# Инициализация проекта
make init CODEBASE_PATH=/путь/к/вашему/проекту

# Загрузка кодовой базы в Claude
make ingest

# Расчет стоимости
make budget

# Ежедневное обновление
make update-claude

# Интерактивный режим
make interactive
```

## Архитектура решения

### Основные компоненты

- **AIModelClient** - Абстрактный интерфейс для работы с моделями ИИ
- **SemanticChunker** - Интеллектуальное разбиение кодовой базы для оптимизации токенов
- **DifferentialUpdater** - Создание эффективных дифференциальных обновлений
- **SessionManager** - Управление сессиями Claude с учетом оптимизации бюджета
- **ResponseCache** - Кеширование ответов для снижения затрат
- **CostEstimator** - Точные оценки затрат на использование Claude
- **LanguageAnalyzer** - Модульная система для анализа разных языков программирования
- **UsageMetrics** - Система отслеживания использования и оптимизации

### Расширенные возможности

- **Параллельная обработка** - Ускоренный анализ больших проектов
- **Интеграция с Git** - Оптимизация работы через Git hooks
- **Интерактивный режим** - Удобная консоль для работы с Claude
- **Многоуровневое кеширование** - Поддержка Redis для высокопроизводительного кеширования
- **Адаптивные бюджеты** - Интеллектуальное управление токенами
- **Анализ паттернов использования** - Автоматическая оптимизация на основе использования

## Документация

Полная документация доступна в директории `docs/` или на [нашем сайте](https://example.com/docs).

## Лицензия

MIT License
EOT

echo "Создание README.md завершено."