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

## ชุด compose (ปรับ 2026-08-22 · DEPLOY-006)

| ไฟล์ | ใช้เมื่อไร | มีอะไร |
|---|---|---|
| `docker-compose.yml` | production และเป็นฐานของทุกสภาพแวดล้อม | db, backend (php-fpm), nginx, worker, scheduler และ job `frontend_build` (profile `build`) ไม่มี dev server และไม่มีพอร์ต 5173 |
| `docker-compose.dev.yml` | เครื่องพัฒนาเท่านั้น | Vite dev server ผูกพอร์ตไว้ที่ `127.0.0.1:5173` เท่านั้น |

```bash
# production
docker compose up -d
docker compose --profile build run --rm frontend_build

# เครื่องพัฒนา
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

เหตุผล: Vite dev server ไม่มี authentication เสิร์ฟ source ดิบและ source map ทั้งโครงการ
และเปิด websocket HMR จึงต้องไม่อยู่ในไฟล์ที่ใช้ deploy
(ไม่ใช้ `docker-compose.override.yml` เพราะ compose โหลดให้อัตโนมัติ — production ที่
`git pull` repo เดียวกันจะได้ dev server กลับมาเงียบ ๆ)

ทุก service ฝั่ง PHP จะรัน `php artisan app:verify-environment` เป็นคำสั่งแรก (SEC-022)
container จะไม่เริ่มทำงานและไม่ migrate เมื่อค่า environment ไม่ปลอดภัย
