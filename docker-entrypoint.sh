#!/usr/bin/env bash
set -e

PORT="${PORT:-10000}"

# Configure Apache port
sed -ri "s!Listen [0-9]+!Listen ${PORT}!g" /etc/apache2/ports.conf
sed -ri "s!<VirtualHost \*:[0-9]+>!<VirtualHost *:${PORT}>!g" /etc/apache2/sites-available/000-default.conf

mkdir -p /var/www/html/assets/uploads
chown -R www-data:www-data /var/www/html/assets/uploads /var/www/html

# If DB_HOST is localhost or 127.0.0.1 or empty, use built-in MariaDB
DB_HOST="${DB_HOST:-localhost}"
if [ "$DB_HOST" = "localhost" ] || [ "$DB_HOST" = "127.0.0.1" ]; then
    echo "[FieldPulse] Starting embedded MariaDB service..."
    service mariadb start || /etc/init.d/mariadb start

    DB_NAME="${DB_NAME:-field_service_db}"
    echo "[FieldPulse] Ensuring database '$DB_NAME' exists..."
    mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS 'root'@'localhost' IDENTIFIED BY '';"
    mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO 'root'@'localhost'; FLUSH PRIVILEGES;"

    TABLE_COUNT=$(mysql -N -s -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME';" 2>/dev/null || echo "0")
    if [ "$TABLE_COUNT" -eq 0 ]; then
        echo "[FieldPulse] Initializing database schema from database.sql..."
        mysql "$DB_NAME" < /var/www/html/database.sql
        echo "[FieldPulse] Seeding initial data..."
        php /var/www/html/setup.php || true
    fi
    echo "[FieldPulse] Embedded MariaDB is ready."
else
    echo "[FieldPulse] Using external database at $DB_HOST."
    # Run setup against external database if needed
    php /var/www/html/setup.php || true
fi

echo "[FieldPulse] Starting Apache web server on port ${PORT}..."
exec "$@"