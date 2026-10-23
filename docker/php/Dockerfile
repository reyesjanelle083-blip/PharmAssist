# One image recipe for the gateway and all four Laravel services.
# Build from the repo root:
#   docker build -f docker/php/Dockerfile --build-arg APP_PATH=services/identity-service .
FROM php:8.3-cli

ARG APP_PATH

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl unzip git \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# The shared package must sit next to the app so the relative path-repository
# URLs (../packages/... or ../../packages/...) resolve.
COPY packages /app/packages
COPY ${APP_PATH} /app/${APP_PATH}

WORKDIR /app/${APP_PATH}

# No .env is copied (see .dockerignore); configuration comes from environment variables.
RUN composer install --no-dev --optimize-autoloader --no-interaction

EXPOSE 8000
CMD ["php","artisan","serve","--host=0.0.0.0","--port=8000"]
