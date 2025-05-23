FROM php:8.4-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    curl unzip libcurl4-openssl-dev \
    --no-install-recommends \
    && docker-php-ext-install curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory to root for relative paths
WORKDIR /multicurl

# Copy composer files first for better layer caching
COPY composer.json composer.lock ./

# Install dependencies
RUN composer install --no-scripts --no-autoloader

# Copy source code
COPY . .

# Generate autoloader
RUN composer dump-autoload --optimize

# Default command
CMD ["vendor/bin/phpunit"]