# VestLedger — perintah pengembangan
#
# Catatan: `php` default di mesin ini adalah PHP 5.6, sehingga seluruh perintah
# PHP di sini menunjuk langsung ke Homebrew PHP 8.3.

PHP  := /opt/homebrew/opt/php@8.3/bin/php
NVM  := . $$HOME/.nvm/nvm.sh && nvm use 20 >/dev/null &&
PORT := 8123

.PHONY: help setup serve dev build test migrate rollback fresh seed user-create version release hooks

help:
	@grep -E '^[a-z-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

setup: ## Install dependency, aktifkan git hooks, build aset, dan jalankan migrasi
	composer install
	$(NVM) npm install
	$(MAKE) hooks
	$(MAKE) build
	$(MAKE) migrate

hooks: ## Aktifkan git hooks proyek (menjaga versi naik setiap push)
	git config core.hooksPath .githooks
	@echo "git hooks aktif dari .githooks/"

serve: build ## Build aset lalu jalankan development server di http://localhost:8123
	@# Aset di-build lebih dulu: class Tailwind baru yang ditambahkan di view
	@# tidak akan berlaku sampai CSS dikompilasi ulang.
	$(PHP) spark serve --host localhost --port $(PORT)

dev: ## Tailwind watch mode (jalankan di terminal terpisah dari `make serve`)
	$(NVM) npm run dev

build: ## Build CSS Tailwind/DaisyUI, salin Alpine.js, dan catat metadata build
	$(NVM) npm run build
	@bash bin/write-build-info.sh

version: ## Tampilkan versi aplikasi saat ini
	@printf 'v%s' "$$(cat VERSION)"; \
	 test -f writable/build.json && printf ' · %s' "$$(sed -n 's/.*"commit": "\(.......\).*/\1/p' writable/build.json)"; \
	 echo

release: ## Naikkan versi, commit, lalu push. PART=patch|minor|major (default patch)
	@test -z "$$(git status --porcelain --untracked-files=no)" \
		|| (echo "Ada perubahan yang belum di-commit. Commit dulu, baru jalankan make release." && exit 1)
	@bash bin/bump-version.sh $${PART:-patch}
	@bash bin/write-build-info.sh
	git add VERSION writable/build.json
	git commit -m "Rilis v$$(cat VERSION)"
	git push origin $$(git rev-parse --abbrev-ref HEAD)

test: ## Jalankan seluruh test
	$(PHP) vendor/bin/phpunit --colors=always

migrate: ## Jalankan seluruh migrasi (termasuk migrasi Shield)
	$(PHP) spark migrate --all

rollback: ## Batalkan batch migrasi terakhir
	$(PHP) spark migrate:rollback

fresh: ## HATI-HATI: hapus seluruh tabel lalu migrasi ulang
	$(PHP) spark migrate:refresh --all

seed: ## Isi master data awal (CoA, sekuritas, saham, periode tahun berjalan)
	$(PHP) spark db:seed InitialSeeder

user-create: ## Buat akun owner. Contoh: make user-create NAME=bambang EMAIL=bambang@example.com
	@test -n "$(NAME)"  || (echo "NAME wajib diisi. Contoh: make user-create NAME=bambang EMAIL=..." && exit 1)
	@test -n "$(EMAIL)" || (echo "EMAIL wajib diisi." && exit 1)
	$(PHP) spark shield:user create -n $(NAME) -e $(EMAIL) -g owner
