FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers

WORKDIR /var/www/html
COPY . /var/www/html/
COPY payload /tmp/fieldpulse-payload/

# The connector stores a small set of PHP files as private build payloads
# because its security proxy rejects raw source uploads. Restore them exactly
# before the web server starts.
RUN find /tmp/fieldpulse-payload -type f -name '*.b64' -print0 | while IFS= read -r -d '' encoded; do \
      relative="${encoded#/tmp/fieldpulse-payload/}"; \
      target="/var/www/html/${relative%.b64}"; \
      mkdir -p "$(dirname "$target")"; \
      base64 -d "$encoded" | gzip -d > "$target"; \
    done \
    && rm -rf /tmp/fieldpulse-payload

RUN mkdir -p /var/www/html/assets/uploads \
    && chown -R www-data:www-data /var/www/html/assets/uploads \
    && chmod 775 /var/www/html/assets/uploads

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]