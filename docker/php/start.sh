#!/bin/sh
if [ "$RUN_MIGRATIONS" != "false" ]; then php artisan migrate --force; fi
if [ "$RUN_SEED" = "true" ]; then
  if [ -n "$SEED_CLASS" ]; then php artisan db:seed --class="$SEED_CLASS" --force; else php artisan db:seed --force; fi
fi
if [ "$RUN_SCHEDULER" = "true" ]; then php artisan schedule:work & fi
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"