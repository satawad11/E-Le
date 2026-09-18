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
    # INT-18 — ghostscript สำหรับ "ปกปิดข้อมูลจริง" (SoT §22)
    #
    # การวาดกล่องทึบทับข้อความ **ไม่ใช่การปกปิด** — ข้อความยังอยู่ใต้กล่องและ
    # คัดลอกออกมาได้ด้วยการเลือกข้อความหรือเครื่องมืออ่าน PDF ทั่วไป
    # ซึ่งเป็นความผิดพลาดที่หน่วยงานราชการทั่วโลกเคยทำจนข้อมูลลับหลุด
    #
    # สำเนาที่ปกปิดจึงต้องถูกแปลงเป็นภาพ (`pdfimage24`) เพื่อทำลายชั้นข้อความ
    # ทั้งแผ่นอย่างถาวร · ไม่มีทางอื่นที่ทำได้ด้วย FPDI เพียงลำพัง
    ghostscript \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ค่า PHP พื้นฐานที่ "อบติดมากับ image" — ต้องมีในทุกวิธีที่รันคอนเทนเนอร์
#
# เดิมค่าเหล่านี้ถูกตั้งไว้ที่ `docker/php/php.ini` แล้ว mount ผ่าน docker-compose
# เท่านั้น · ผลคือถ้ารัน image ตรง ๆ (`docker run` แบบ one-off, หรือรันเทสต์ใน CI
# ที่ไม่ผ่าน compose) จะได้ค่า default ของ PHP คือ memory_limit=128M ซึ่ง **ไม่พอ**
# กับเทสต์ที่อัปโหลดรูปเพดาน 5 MB (ส่งเป็น base64 data URL ที่พองหลายเท่าตอนถอดรหัส)
# ทำให้ AttendanceImageSecurityTest ล้มด้วย "Allowed memory size exhausted"
#
# จึงอบค่าเป็น "พื้น" (baseline) ไว้ใน image · ตั้งชื่อไฟล์ให้โหลดก่อน `zz-app.ini`
# (`-` < `.` ในลำดับตัวอักษร) เพื่อให้ compose ยัง override ทับได้ตามเดิมถ้าต้องการ
#
# ‼️ ต้องแก้ให้ตรงกับ `docker/php/php.ini` เสมอเมื่อมีการเปลี่ยนค่า
RUN { \
        echo '; อบมากับ image เป็นค่าพื้น — ดูเหตุผลใน backend/Dockerfile'; \
        echo 'memory_limit = 256M'; \
        echo 'post_max_size = 100M'; \
        echo 'upload_max_filesize = 100M'; \
        echo 'variables_order = EGPCS'; \
    } > /usr/local/etc/php/conf.d/zz-app-baseline.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# แก้สิทธิ์ storage ทุกครั้งที่ start เพราะ source ถูก bind mount มาจาก host
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
