.PHONY: help build up down test test-all test-8.1 test-8.2 test-8.3 test-8.4 unit unit-8.1 unit-8.2 unit-8.3 unit-8.4 integration integration-8.1 integration-8.2 integration-8.3 integration-8.4 clean logs

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Build Docker images
	docker-compose build --with-dependencies

up: ## Start services (HTTP server)
	docker-compose up -d httpbin mcp-everything

down: ## Stop all services
	docker-compose down

test: test-8.4 ## Run all tests with PHP 8.4 (default)
	
test-all: test-8.1 test-8.2 test-8.3 test-8.4 ## Run tests with PHP 8.1, 8.2, 8.3 and 8.4

test-8.1: up unit-8.1 integration-8.1 ## Run all tests with PHP 8.1
	
test-8.2: up unit-8.2 integration-8.2 ## Run all tests with PHP 8.2
	
test-8.3: up unit-8.3 integration-8.3 ## Run all tests with PHP 8.3
	
test-8.4: up unit-8.4 integration-8.4 ## Run all tests with PHP 8.4
	
unit: unit-8.4 ## Run unit tests only with PHP 8.4 (default)

unit-8.1: up ## Run unit tests only with PHP 8.1
	docker-compose run --build --rm php81 vendor/bin/phpunit --exclude-group integration

unit-8.2: up ## Run unit tests only with PHP 8.2
	docker-compose run --build --rm php82 vendor/bin/phpunit --exclude-group integration

unit-8.3: up ## Run unit tests only with PHP 8.3
	docker-compose run --build --rm php83 vendor/bin/phpunit --exclude-group integration

unit-8.4: up ## Run unit tests only with PHP 8.4
	docker-compose run --build --rm php84 vendor/bin/phpunit --exclude-group integration

integration: integration-8.4 ## Run integration tests only with PHP 8.4 (default)

integration-8.1: up ## Run integration tests only with PHP 8.1
	docker-compose run --build --rm php81 vendor/bin/phpunit --group integration

integration-8.2: up ## Run integration tests only with PHP 8.2
	docker-compose run --build --rm php82 vendor/bin/phpunit --group integration

integration-8.3: up ## Run integration tests only with PHP 8.3
	docker-compose run --build --rm php83 vendor/bin/phpunit --group integration

integration-8.4: up ## Run integration tests only with PHP 8.4
	docker-compose run --build --rm php84 vendor/bin/phpunit --group integration
	
logs: ## Show logs from HTTP server
	docker-compose logs -f httpbin

clean: down ## Clean up everything
	docker-compose down -v --remove-orphans
	docker system prune -f
