#!/bin/sh
if [ "$RUN_MIGRATIONS" != "false" ]; then php artisan migrate --force; fi
if [ "$RUN_SEED" = "true" ]; then php artisan db:seed --force; fi
if [ "$RUN_SCHEDULER" = "true" ]; then php artisan schedule:work & fi
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"