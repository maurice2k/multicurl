.PHONY: help build up down test unit integration clean logs

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Build Docker images
	docker-compose build --with-dependencies

up: ## Start services (HTTP server)
	docker-compose up -d httpbin

down: ## Stop all services
	docker-compose down

test: up unit integration ## Run all tests
	
unit: ## Run unit tests only
	docker-compose run --rm --remove-orphans unit-tests

integration: up ## Run integration tests only
	docker-compose run --rm integration-tests
	docker-compose down --remove-orphans

	
logs: ## Show logs from HTTP server
	docker-compose logs -f httpbin

clean: down ## Clean up everything
	docker-compose down -v --remove-orphans
	docker system prune -f
