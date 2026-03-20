.PHONY: up down build install migrate fixtures test shell logs

## Start all containers
up:
	docker compose up -d

## Stop all containers
down:
	docker compose down

## Build/rebuild containers
build:
	docker compose build

## Install dependencies
install:
	docker compose exec app composer install

## Run database migrations
migrate:
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

## Create test database and run migrations
migrate-test:
	docker compose exec db mysql -u root -proot -e "GRANT ALL PRIVILEGES ON \`paysera_test\`.* TO 'paysera'@'%'; FLUSH PRIVILEGES;"
	docker compose exec app php bin/console doctrine:database:create --env=test --if-not-exists
	docker compose exec app php bin/console doctrine:migrations:migrate --env=test --no-interaction

## Load fixtures
fixtures:
	docker compose exec app php bin/console doctrine:fixtures:load --no-interaction

## Run tests
test: migrate-test
	docker compose exec app php bin/phpunit

## Open shell in PHP container
shell:
	docker compose exec app bash

## View logs
logs:
	docker compose logs -f

## Full setup: build, start, install, migrate, load fixtures
setup: build up install migrate fixtures
	@echo "Setup complete! API available at http://localhost:8080"
