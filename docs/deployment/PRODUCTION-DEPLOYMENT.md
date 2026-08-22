# Production Deployment Procedure

เอกสารนี้ระบุขั้นตอนการ Deploy ระบบ E-la สำหรับสภาพแวดล้อม Production ที่ใช้ Docker Compose แบบทำซ้ำได้ (Repeatable Deployment)

## ขั้นตอนการ Deploy อย่างเป็นทางการ

### 1. เข้าสู่ Maintenance Mode
ระงับการเข้าถึงชั่วคราวเพื่อป้องกัน Data Inconsistency
```bash
docker exec eleave_backend php artisan down --secret="ela-maintenance-bypass"
```

### 2. Backup Procedure (ก่อน Deploy)
บังคับทำ Backup ฐานข้อมูลและไฟล์เสมอ
```bash
docker exec eleave_db /usr/bin/mysqldump -u root --password="${MYSQL_ROOT_PASSWORD}" eleave > /backups/db/pre_deploy_$(date +%s).sql
tar -czvf /backups/files/pre_deploy_$(date +%s).tar.gz ./backend/storage/app/private_uploads
```

### 3. Application Deployment (ดึงโค้ดและ Build)
ดึงโค้ดล่าสุดจาก Branch `main` หรือ Release Tag:
```bash
git pull origin main
```
*ข้อควรระวัง:* `.env` ของ Production ต้องอยู่นอกระบบ Git และดึงมาจาก Secure Vault เท่านั้น ห้าม Hard-code Secrets ใน Repository

Build Container ใหม่:
```bash
docker-compose build
docker-compose up -d
```

*DEPLOY-006:* ใช้ `docker-compose.yml` **ไฟล์เดียว** เท่านั้นบน production — ห้ามใส่ `-f docker-compose.dev.yml` เพราะไฟล์นั้นเปิด Vite dev server ซึ่งไม่มี authentication และเสิร์ฟ source ทั้งโครงการ

สร้างไฟล์หน้าเว็บที่ nginx เสิร์ฟ (`frontend/dist`) — เป็น job ที่ทำงานแล้วจบ ไม่ใช่ service ที่เปิดพอร์ตค้างไว้:
```bash
docker compose --profile build run --rm frontend_build
```

*SEC-022:* backend / worker / scheduler จะรัน `php artisan app:verify-environment` เป็นคำสั่งแรก ถ้า `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_URL` หรือ `filesystems.disks.local.serve` ตั้งค่าไม่ปลอดภัย container จะออกทันทีก่อน migrate พร้อมบอกเหตุผลเป็นภาษาไทย — ให้แก้ `.env` แล้ว `docker-compose up -d` ใหม่

### 4. Database Migration Procedure
อัปเดตโครงสร้างฐานข้อมูลโดยห้ามใช้คำสั่ง Drop หรือ Fresh เด็ดขาด
```bash
docker exec eleave_backend php artisan migrate --force
```

### 5. Cache Handling
ล้าง Cache ระบบและสร้าง Cache Config เพื่อประสิทธิภาพสูงสุด
```bash
docker exec eleave_backend php artisan optimize:clear
docker exec eleave_backend php artisan config:cache
docker exec eleave_backend php artisan route:cache
docker exec eleave_backend php artisan view:cache
```

### 6. Queue Restart
เริ่มการทำงานของ Worker ใหม่เพื่อให้โหลดโค้ดชุดล่าสุด
```bash
docker exec eleave_backend php artisan queue:restart
docker-compose restart worker scheduler
```

### 7. Permission Verification
ตรวจสอบสิทธิ์การเขียน/อ่านของ Storage
```bash
docker exec eleave_backend chown -R www-data:www-data storage/ bootstrap/cache/
docker exec eleave_backend chmod -R 775 storage/ bootstrap/cache/
```

### 8. Post-Deployment Validation
1. รัน Health Check ภายใน
```bash
docker exec eleave_backend php artisan about
docker exec eleave_backend php artisan env
```
2. ปลด Maintenance Mode
```bash
docker exec eleave_backend php artisan up
```
3. ดำเนินการทดสอบระบบเบื้องต้นตาม (Smoke Tests) ใน `GO-LIVE-CHECKLIST.md`
