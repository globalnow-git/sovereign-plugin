FROM wordpress:latest

# Install PHP extensions and tools Sovereign Builder needs
RUN apt-get update && apt-get install -y \
    unzip \
    curl \
    git \
    less \
    mariadb-client \
    && docker-php-ext-install \
    pdo_mysql \
    mysqli \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable WP_DEBUG log directory
RUN mkdir -p /var/www/html/wp-content/debug-logs \
    && chown -R www-data:www-data /var/www/html/wp-content/debug-logs

# php.ini tuning for a heavy plugin
RUN echo "upload_max_filesize = 64M" >> /usr/local/etc/php/conf.d/sovereign.ini \
    && echo "post_max_size = 64M" >> /usr/local/etc/php/conf.d/sovereign.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/sovereign.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/sovereign.ini \
    && echo "max_input_vars = 5000" >> /usr/local/etc/php/conf.d/sovereign.ini

EXPOSE 80
