# TECH STACK

| ชั้น | เทคโนโลยี |
|---|---|
| Backend | Laravel 13 · PHP 8.4 · Sanctum (token) · PHPUnit 12 · Pint |
| Frontend | Vue 3.5 · Vue Router 5 · Pinia 3 · Vite 8 · Tailwind CSS 4 · axios |
| Database | MySQL 8.0 (authoritative store) |
| Infra | Docker Compose · Nginx · private file storage + signed URL |
| PDF | barryvdh/laravel-dompdf + Document Engine |

**ถอดออกใน R-02:** `spatie/laravel-permission` (แทนด้วยตารางสิทธิ์ของแกนกลาง ADR-R-003)
**เพิ่มใน R-05:** ไลบรารี TOTP สำหรับ MFA (เลือกใน R-05 และบันทึก ADR)

## Required engineering
migration-only schema changes · FormRequest validation · structured logging · health check · env validation ·
automated tests ทุก business rule และทุกสิทธิ์ · CI ต้องรัน test + pint + frontend build
