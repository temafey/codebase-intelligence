#!/bin/bash

# Скрипт для создания Makefile

# Принимаем путь для установки
TARGET_DIR="$1"

echo "Создание Makefile в $TARGET_DIR..."

# Создаем Makefile
cat > "$TARGET_DIR/Makefile" << 'EOT'
.PHONY: help build up down sh install update test analyze ingest budget

# Main configuration
CODEBASE_PATH ?= $(shell pwd)
STORAGE_DIR ?= $(CODEBASE_PATH)/.claude
LANGUAGE ?= php
DOCKER_COMPOSE = docker compose

# Command aliases
EXEC = $(DOCKER_COMPOSE) exec codebase-intelligence
PHP = $(DOCKER_COMPOSE) run --rm php

# Help command
help:
	@echo "CodebaseIntelligence - Claude 3.7 Sonnet Integration"
	@echo ""
	@echo "Usage:"
	@echo "  make build             Build Docker images"
	@echo "  make up                Start services"
	@echo "  make down              Stop services"
	@echo "  make sh                Open shell in container"
	@echo "  make install           Install dependencies"
	@echo "  make update            Update dependencies"
	@echo "  make test              Run tests"
	@echo "  make analyze           Analyze code quality"
	@echo ""
	@echo "Claude integration commands:"
	@echo "  make init              Initialize codebase integration"
	@echo "  make ingest            Ingest codebase into Claude"
	@echo "  make analyze-code      Send codebase for analysis"
	@echo "  make update-claude     Update Claude with recent changes"
	@echo "  make budget            Estimate costs for Claude integration"
	@echo "  make daily-sync        Run daily synchronization"
	@echo "  make interactive       Start interactive Claude shell"
	@echo "  make metrics           Generate usage metrics report"
	@echo ""
	@echo "Environment variables:"
	@echo "  CODEBASE_PATH          Path to your codebase (default: current directory)"
	@echo "  STORAGE_DIR            Path to store Claude data (default: CODEBASE_PATH/.claude)"
	@echo "  LANGUAGE               Primary language (php, python, golang)"

# Docker commands
build:
	$(DOCKER_COMPOSE) build

up:
	$(DOCKER_COMPOSE) up -d

down:
	$(DOCKER_COMPOSE) down

sh:
	$(EXEC) sh

# Development commands
install:
	composer install

update:
	composer update

test:
	composer test

analyze:
	composer analyze

# Claude integration commands
init:
	@echo "Initializing Claude integration..."
	@mkdir -p $(STORAGE_DIR)
	$(PHP) php bin/code-intelligence init \
		--codebase-path=$(CODEBASE_PATH) \
		--storage-dir=$(STORAGE_DIR) \
		--language=$(LANGUAGE)

ingest:
	@echo "Ingesting codebase into Claude..."
	$(PHP) php bin/code-intelligence ingest \
		--codebase-path=$(CODEBASE_PATH) \
		--storage-dir=$(STORAGE_DIR)

analyze-code:
	@echo "Analyzing codebase with Claude..."
	$(PHP) php bin/code-intelligence analyze \
		--codebase-path=$(CODEBASE_PATH) \
		--storage-dir=$(STORAGE_DIR)

update-claude:
	@echo "Updating Claude with recent changes..."
	$(PHP) php bin/code-intelligence update \
		--codebase-path=$(CODEBASE_PATH) \
		--storage-dir=$(STORAGE_DIR)

budget:
	@echo "Estimating costs for Claude integration..."
	$(PHP) php bin/code-intelligence budget \
		--codebase-path=$(CODEBASE_PATH) \
		--storage-dir=$(STORAGE_DIR)

daily-sync:
	@echo "Running daily synchronization with Claude..."
	$(PHP) php bin/code-intelligence sync \
		--codebase-path=$(CODEBASE_PATH) \
		--storage-dir=$(STORAGE_DIR) \
		--mode=daily

interactive:
	@echo "Starting interactive Claude shell..."
	$(PHP) php bin/code-intelligence shell

metrics:
	@echo "Generating usage metrics report..."
	$(PHP) php bin/code-intelligence metrics

# Cleanup commands
clean:
	@echo "Cleaning up temporary files..."
	@rm -rf vendor
	@rm -rf var/cache/*

purge: clean
	@echo "Purging all Claude data..."
	@rm -rf $(STORAGE_DIR)

# Cache management
clear-cache:
	@echo "Clearing response cache..."
	$(PHP) php bin/code-intelligence cache:clear

optimize-cache:
	@echo "Optimizing cache..."
	$(PHP) php bin/code-intelligence cache:optimize
EOT

echo "Создание Makefile завершено."