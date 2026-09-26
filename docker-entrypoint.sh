#!/usr/bin/env bash
set -e

PORT="${PORT:-10000}"

# Configure Apache port
sed -ri "s!Listen [0-9]+!Listen ${PORT}!g" /etc/apache2/ports.conf
sed -ri "s!<VirtualHost \*:[0-9]+>!<VirtualHost *:${PORT}>!g" /etc/apache2/sites-available/000-default.conf

mkdir -p /var/www/html/assets/uploads /var/www/html/assets/icons
chown -R www-data:www-data /var/www/html

DB_DRIVER="${DB_DRIVER:-auto}"

# If explicitly set to SQLite, skip MariaDB entirely
if [ "$DB_DRIVER" = "sqlite" ]; then
    echo "[FieldPulse] Using SQLite database (zero-dependency mode)."
    # SQLite file lives in /var/www/html (www-data writable)
    SQLITE_FILE="/var/www/html/field_service.sqlite"
    if [ ! -f "$SQLITE_FILE" ] || [ ! -s "$SQLITE_FILE" ]; then
        echo "[FieldPulse] Initializing fresh SQLite database via setup.php..."
        php /var/www/html/setup.php 2>&1 || true
        echo "[FieldPulse] SQLite database ready."
    fi
else
    # If DB_HOST is localhost or 127.0.0.1, try embedded MariaDB
    DB_HOST="${DB_HOST:-localhost}"
    if [ "$DB_HOST" = "localhost" ] || [ "$DB_HOST" = "127.0.0.1" ]; then
        echo "[FieldPulse] Starting embedded MariaDB service..."
        service mariadb start 2>/dev/null || /etc/init.d/mariadb start 2>/dev/null || true

        DB_NAME="${DB_NAME:-field_service_db}"
        echo "[FieldPulse] Ensuring database '$DB_NAME' exists..."
        mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || true
        mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO 'root'@'localhost'; FLUSH PRIVILEGES;" 2>/dev/null || true

        TABLE_COUNT=$(mysql -N -s -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME';" 2>/dev/null || echo "0")
        if [ "$TABLE_COUNT" -eq 0 ] 2>/dev/null; then
            echo "[FieldPulse] Initializing database schema..."
            php /var/www/html/setup.php 2>&1 || true
        fi
        echo "[FieldPulse] MariaDB ready."
    else
        echo "[FieldPulse] Using external database at $DB_HOST."
        php /var/www/html/setup.php 2>&1 || true
    fi
fi

echo "[FieldPulse] Starting Apache on port ${PORT}..."
exec "$@"