.PHONY: help build up down sh install update test analyze ingest budget

# Main configuration
CODEBASE_PATH ?= $(shell pwd)
STORAGE_DIR ?= $(CODEBASE_PATH)/.claude
LANGUAGE ?= php
DOCKER_COMPOSE = docker compose

# Command aliases
PHP = $(DOCKER_COMPOSE) run --rm php
EXEC = $(DOCKER_COMPOSE) exec codebase-intelligence

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
	$(PHP) sh

# Development commands
install:
	$(PHP) composer install

update:
	$(PHP) composer update

test:
	$(PHP) composer test

analyze:
	$(PHP) composer analyze

# Initialization command (must be run before other Claude commands)
init:
	@echo "Initializing Claude integration..."
	@mkdir -p $(STORAGE_DIR)
	$(PHP) php -r "if (!file_exists('/app/vendor/autoload.php')) { echo \"Installing dependencies first...\n\"; shell_exec('composer install'); }"
	$(PHP) php bin/code-intelligence init \
		--codebase-path=/codebase \
		--storage-dir=/storage \
		--language=$(LANGUAGE)

# Claude integration commands - all run with dependency check
ingest:
	@echo "Ingesting codebase into Claude..."
	$(PHP) php -r "if (!file_exists('/app/vendor/autoload.php')) { echo \"Installing dependencies first...\n\"; shell_exec('composer install'); }"
	$(PHP) php bin/code-intelligence ingest \
		--codebase-path=/codebase \
		--storage-dir=/storage

analyze-code:
	@echo "Analyzing codebase with Claude..."
	$(PHP) php -r "if (!file_exists('/app/vendor/autoload.php')) { echo \"Installing dependencies first...\n\"; shell_exec('composer install'); }"
	$(PHP) php bin/code-intelligence analyze \
		--codebase-path=/codebase \
		--storage-dir=/storage

update-claude:
	@echo "Updating Claude with recent changes..."
	$(PHP) php -r "if (!file_exists('/app/vendor/autoload.php')) { echo \"Installing dependencies first...\n\"; shell_exec('composer install'); }"
	$(PHP) php bin/code-intelligence update \
		--codebase-path=/codebase \
		--storage-dir=/storage

budget:
	@echo "Estimating costs for Claude integration..."
	$(PHP) php -r "if (!file_exists('/app/vendor/autoload.php')) { echo \"Installing dependencies first...\n\"; shell_exec('composer install'); }"
	$(PHP) php bin/code-intelligence budget \
		--codebase-path=/codebase \
		--storage-dir=/storage

daily-sync:
	@echo "Running daily synchronization with Claude..."
	$(PHP) php -r "if (!file_exists('/app/vendor/autoload.php')) { echo \"Installing dependencies first...\n\"; shell_exec('composer install'); }"
	$(PHP) php bin/code-intelligence sync \
		--codebase-path=/codebase \
		--storage-dir=/storage \
		--mode=daily

interactive:
	@echo "Starting interactive Claude shell..."
	$(PHP) php -r "if (!file_exists('/app/vendor/autoload.php')) { echo \"Installing dependencies first...\n\"; shell_exec('composer install'); }"
	$(PHP) php bin/code-intelligence shell

metrics:
	@echo "Generating usage metrics report..."
	$(PHP) php -r "if (!file_exists('/app/vendor/autoload.php')) { echo \"Installing dependencies first...\n\"; shell_exec('composer install'); }"
	$(PHP) php bin/code-intelligence metrics

# Cleanup commands
clean:
	@echo "Cleaning up temporary files..."
	$(PHP) rm -rf /app/vendor
	$(PHP) rm -rf /app/var/cache/*

purge: clean
	@echo "Purging all Claude data..."
	rm -rf $(STORAGE_DIR)

# Cache management
clear-cache:
	@echo "Clearing response cache..."
	$(PHP) php -r "if (!file_exists('/app/vendor/autoload.php')) { echo \"Installing dependencies first...\n\"; shell_exec('composer install'); }"
	$(PHP) php bin/code-intelligence cache:clear

optimize-cache:
	@echo "Optimizing cache..."
	$(PHP) php -r "if (!file_exists('/app/vendor/autoload.php')) { echo \"Installing dependencies first...\n\"; shell_exec('composer install'); }"
	$(PHP) php bin/code-intelligence cache:optimize