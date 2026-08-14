# CORE CHECKPOINT · DEPLOY-001 / DEPLOY-004
#
# เดิมมี Dockerfile สองชุดที่ไม่ตรงกัน:
#   - `/Dockerfile` (php:8.4-fpm) คู่กับ `docker/nginx/default.conf` — **ไม่มีใครใช้**
#   - `/backend/Dockerfile` (php:8.4-cli) ที่ compose ใช้จริง แล้วรันด้วย `php artisan serve`
#
# ผลคือสิ่งที่รันบนเครื่อง dev ไม่ใช่สิ่งที่ตั้งใจจะ deploy และ config nginx
# ที่มีกฎความปลอดภัย (`location ^~ /storage { return 404; }`) ไม่เคยมีผลบังคับใช้เลย
#
# รวมเหลือไฟล์เดียว: php-fpm ที่ nginx เรียกผ่าน FastCGI — ใช้ชุดเดียวกันทั้ง dev และ prod
FROM php:8.4-fpm

# system dependencies + mysql-client (จำเป็นสำหรับ spatie/laravel-backup)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    default-mysql-client \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# แก้สิทธิ์ storage ทุกครั้งที่ start เพราะ source ถูก bind mount มาจาก host
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
