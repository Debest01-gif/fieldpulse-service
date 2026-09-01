#!/usr/bin/env bash
set -euo pipefail

PORT="${PORT:-10000}"

# Render provides PORT at runtime; Apache's Debian image defaults to port 80.
sed -ri "s!Listen [0-9]+!Listen ${PORT}!g" /etc/apache2/ports.conf
sed -ri "s!<VirtualHost \*:[0-9]+>!<VirtualHost *:${PORT}>!g" /etc/apache2/sites-available/000-default.conf

mkdir -p /var/www/html/assets/uploads
chown -R www-data:www-data /var/www/html/assets/uploads

exec "$@"