ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    curl unzip libcurl4-openssl-dev \
    --no-install-recommends \
    && docker-php-ext-install curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Set working directory to root for relative paths
WORKDIR /multicurl

# Copy source code
COPY . .

# Install dependencies
RUN composer install --no-scripts --no-autoloader

# Generate autoloader
RUN composer dump-autoload --optimize

# Default command
CMD ["vendor/bin/phpunit"]