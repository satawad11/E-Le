# DEPLOYMENT
Environments: development, test, staging, production.
Docker services baseline: web, worker, postgres, redis, minio, nginx, backup.

Production requires TLS, secret management, database backups, storage backups, health checks, log rotation and rollback-aware deployment.

## ขั้นตอนหลัง deploy ที่ต้องรันเสมอ

```bash
php artisan migrate --force
php artisan document:install-fonts   # ฟอนต์ไทยของ Document Engine
```

`document:install-fonts` สร้างไฟล์นิยามฟอนต์ TH SarabunNew สำหรับเรนเดอร์แบบฟอร์ม PDF
จากไฟล์ `.ttf` ที่อยู่ในโครงการ ผลลัพธ์เก็บใน `storage/fonts/fpdf` ซึ่งไม่ได้อยู่ใน repo
(เป็นไฟล์ที่ generate ได้) จึงต้องรันใหม่ทุกครั้งที่ deploy ลงเครื่องใหม่หรือล้าง `storage`
ถ้าไม่รัน การพิมพ์เอกสารผ่าน Document Engine จะล้มด้วยข้อความว่ายังไม่ได้ติดตั้งฟอนต์
(ระบบจะถอยไปใช้เส้นทางพิมพ์เดิมให้อัตโนมัติ ผู้ใช้ยังพิมพ์เอกสารได้)

คำสั่งนี้ idempotent — รันซ้ำได้ ใช้ `--force` เมื่อต้องการสร้างใหม่ทับของเดิม
