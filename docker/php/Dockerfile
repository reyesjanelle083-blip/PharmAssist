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

# Optional OCR engine. Only the integration-service sets INSTALL_TESSERACT=true (see docker-compose.yml);
# every other service keeps the small image: Tesseract (English data) for the OCR binary and PHP's GD extension
# for the image preprocessing.
ARG INSTALL_TESSERACT=false
RUN if [ "$INSTALL_TESSERACT" = "true" ]; then \
        apt-get update \
        && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-eng libpng-dev libjpeg-dev libfreetype6-dev \
        && docker-php-ext-configure gd --with-jpeg --with-freetype \
        && docker-php-ext-install gd \
        && rm -rf /var/lib/apt/lists/*; \
    fi

# PHP's default upload limit is 2 MB; the prescription OCR accepts images up to 5 MB (the gateway receives them first).
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# The shared package must sit next to the app so the relative path-repository
# URLs (../packages/... or ../../packages/...) resolve.
COPY packages /app/packages
COPY ${APP_PATH} /app/${APP_PATH}

WORKDIR /app/${APP_PATH}

# No .env is copied (see .dockerignore); configuration comes from environment variables.
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY docker/php/aiven-ca.pem /etc/ssl/certs/aiven-ca.pem

EXPOSE 8000
CMD ["php","artisan","serve","--host=0.0.0.0","--port=8000"]
