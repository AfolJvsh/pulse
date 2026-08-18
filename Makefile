.PHONY: setup up down test lint format build
setup:
	cp .env.example .env || true
	docker compose build
	docker compose run --rm app composer install
	docker compose run --rm app php artisan key:generate
	docker compose run --rm app php artisan migrate --seed
up:
	docker compose up -d
down:
	docker compose down
test:
	docker compose run --rm app php artisan test
lint:
	docker compose run --rm app sh -lc "find app bootstrap config database routes tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l"
format:
	docker compose run --rm app vendor/bin/pint
build:
	docker compose run --rm app npm ci && npm run build
