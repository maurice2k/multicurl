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

# Install dependencies
COPY composer.json ./
RUN composer install --no-scripts --no-autoloader
RUN composer dump-autoload --optimize

# Copy source code (without vendor directory)
COPY . .

# Default command
CMD ["vendor/bin/phpunit"]