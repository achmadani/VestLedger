# VestLedger — perintah pengembangan
#
# Catatan: `php` default di mesin ini adalah PHP 5.6, sehingga seluruh perintah
# PHP di sini menunjuk langsung ke Homebrew PHP 8.3.

PHP  := /opt/homebrew/opt/php@8.3/bin/php
NVM  := . $$HOME/.nvm/nvm.sh && nvm use 20 >/dev/null &&
PORT := 8123

.PHONY: help setup serve dev build test migrate rollback fresh user-create fmt

help:
	@grep -E '^[a-z-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

setup: ## Install dependency, build aset, dan jalankan migrasi
	composer install
	$(NVM) npm install
	$(MAKE) build
	$(MAKE) migrate

serve: ## Jalankan development server di http://localhost:8123
	$(PHP) spark serve --host localhost --port $(PORT)

dev: ## Tailwind watch mode (jalankan di terminal terpisah dari `make serve`)
	$(NVM) npm run dev

build: ## Build CSS Tailwind/DaisyUI dan salin Alpine.js ke public/assets
	$(NVM) npm run build

test: ## Jalankan seluruh test
	$(PHP) vendor/bin/phpunit --colors=always

migrate: ## Jalankan seluruh migrasi (termasuk migrasi Shield)
	$(PHP) spark migrate --all

rollback: ## Batalkan batch migrasi terakhir
	$(PHP) spark migrate:rollback

fresh: ## HATI-HATI: hapus seluruh tabel lalu migrasi ulang
	$(PHP) spark migrate:refresh --all

user-create: ## Buat akun owner. Contoh: make user-create NAME=bambang EMAIL=bambang@example.com
	@test -n "$(NAME)"  || (echo "NAME wajib diisi. Contoh: make user-create NAME=bambang EMAIL=..." && exit 1)
	@test -n "$(EMAIL)" || (echo "EMAIL wajib diisi." && exit 1)
	$(PHP) spark shield:user create -n $(NAME) -e $(EMAIL) -g owner
