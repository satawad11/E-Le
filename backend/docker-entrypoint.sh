#!/bin/sh
set -e

# ให้ php-fpm (www-data) เขียน storage และ bootstrap/cache ได้
# ต้องทำทุกครั้งที่ start เพราะ source ถูก bind mount มาจาก host
for DIR in /var/www/html/storage /var/www/html/bootstrap/cache; do
    if [ -d "$DIR" ]; then
        chown -R www-data:www-data "$DIR" 2>/dev/null || true
        chmod -R 775 "$DIR" 2>/dev/null || true
    fi
done

exec "$@"
