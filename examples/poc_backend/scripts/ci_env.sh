#!/usr/bin/env bash
# Writes .env for CI from .env.example using the job's DB_* variables.
# `php artisan serve` does not pass most environment variables to its worker,
# so the database settings must be in .env itself. Synthetic CI values only.
set -euo pipefail

cp .env.example .env
for key in DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD; do
  value="${!key:-}"
  [ -n "$value" ] || continue
  # Drop existing (or commented) lines for the key, then append the CI value.
  sed -i -E "/^#? ?${key}=/d" .env
  echo "${key}=${value}" >> .env
done
php artisan key:generate --no-interaction
