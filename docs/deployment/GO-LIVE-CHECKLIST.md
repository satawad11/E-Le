# E-la v1.0 Go-Live Checklist

## 1. Pre-deployment Readiness
- [ ] `APP_DEBUG=false` และ `APP_ENV=production` ได้รับการกำหนดใน `.env` ของเครื่อง Production
- [ ] `APP_KEY` ถูกสร้างใหม่และสุ่มอย่างปลอดภัยสำหรับ Production (`php artisan key:generate --show`)
- [ ] รหัสผ่านทั้งหมด (`MYSQL_ROOT_PASSWORD`, `DB_APP_PASSWORD`) ได้มาจาก Secure Vault และไม่ได้อยู่ใน Git (ห้าม hard-code)
- [ ] ตั้งค่า HTTPS/TLS ผ่าน Reverse Proxy (เช่น Nginx หรือ Cloudflare) เรียบร้อยแล้ว
- [ ] ยืนยันสิทธิ์โฟลเดอร์ Storage (`chmod -R 775 storage/app/private_uploads` และ `chown -R www-data:www-data`)

## 2. Dependencies & Build
- [ ] ฝั่ง Frontend: `npm run build` สำเร็จและไฟล์ static อยู่ใน `./frontend/dist`
- [ ] ฝั่ง Backend: `composer install --optimize-autoloader --no-dev` เรียบร้อย

## 3. Deployment Window (Downtime)
- [ ] ตรวจสอบว่ามีผู้ใช้อยู่ในระบบหรือไม่ (Maintenance Mode: `php artisan down --secret="your-secret"`)
- [ ] ประกาศแจ้งเตือน Downtime แก่บุคลากร (ประมาณ 30-60 นาที)

## 4. Post-Deployment (Smoke Tests)
- [ ] **Health Endpoint:** ทดสอบเข้าถึง `/api/up` หรือ `/api/ping` ได้รับสถานะ 200 OK
- [ ] **Login:** สามารถเข้าสู่ระบบด้วยบัญชี Super Admin ได้สำเร็จ
- [ ] **Leave:** สามารถสร้างใบลา และระบบส่งใบลาเข้าคิว Approval ได้ (Queue ไม่พัง)
- [ ] **Audit:** ฐานข้อมูล `audit_logs` บันทึกการ Login เมื่อสักครู่ได้สำเร็จ
- [ ] **File Storage:** อัปโหลดและดาวน์โหลดรูปลายเซ็นจาก `private_uploads` ได้ปกติ
