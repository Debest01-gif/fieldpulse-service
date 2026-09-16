FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    mariadb-server \
    mariadb-client \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html/
RUN rm -rf /var/www/html/payload

RUN mkdir -p /var/www/html/assets/uploads \
    && chown -R www-data:www-data /var/www/html/assets/uploads \
    && chmod 775 /var/www/html/assets/uploads

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]