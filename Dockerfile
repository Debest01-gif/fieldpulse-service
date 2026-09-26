FROM php:8.2-apache

# Install dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    mariadb-server \
    mariadb-client \
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite \
    && a2enmod rewrite headers expires deflate \
    && rm -rf /var/lib/apt/lists/*

# Set ServerName to suppress warning
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . /var/www/html/

# Remove sensitive files from image
RUN rm -rf /var/www/html/payload \
    /var/www/html/.env \
    /var/www/html/XAMPP_INSTALLATION.txt

# Create writable directories
RUN mkdir -p /var/www/html/assets/uploads \
             /var/www/html/assets/icons \
    && chown -R www-data:www-data /var/www/html \
    && chmod 775 /var/www/html/assets/uploads \
    && chmod -R 755 /var/www/html

# SQLite database directory must be writable
RUN mkdir -p /var/sqlite-data \
    && chown -R www-data:www-data /var/sqlite-data

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]