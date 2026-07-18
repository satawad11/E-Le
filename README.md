# E-Leave — ระบบลางานออนไลน์สำหรับหน่วยงานราชการไทย

## Tech Stack
- **Backend**: Laravel 11 + Sanctum (API)
- **Frontend**: Vue 3 + Pinia + Tailwind CSS
- **Database**: MySQL 8
- **PDF**: DomPDF
- **Deploy**: Docker Compose

---

## การติดตั้ง (Installation)

### Prerequisites
- Docker + Docker Compose
- Node.js 18+

### 1. Clone & Start Docker

```bash
git clone <repo-url>
cd E-Le
docker-compose up -d
```

### 2. Backend Setup

```bash
docker exec -it eleave_backend bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
exit
```

### 3. Frontend Setup

```bash
cd frontend
npm install
npm run dev
```

Frontend จะรันที่ `http://localhost:5173`

---

## บัญชีผู้ใช้เริ่มต้น (Seeded Accounts)

| บทบาท | Employee ID | รหัสผ่าน |
|---|---|---|
| Super Admin | SA001 | password |
| Director | D001 | password |
| HR Head | HR001 | password |
| Employee | E001 | password |

---

## API Endpoints

### Authentication
| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/login` | เข้าสู่ระบบ |
| POST | `/api/logout` | ออกจากระบบ |
| GET | `/api/user` | ข้อมูลผู้ใช้ปัจจุบัน |

### Leave
| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/leaves` | รายการใบลา |
| POST | `/api/leaves` | ยื่นใบลาใหม่ |
| GET | `/api/leaves/balances` | วันลาคงเหลือ |
| GET | `/api/leaves/types` | ประเภทการลา |
| POST | `/api/leaves/{id}/cancel` | ยกเลิกใบลา |
| GET | `/api/leaves/{id}/pdf` | ดาวน์โหลด PDF |

### Approval
| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/approvals/{id}/approve` | อนุมัติใบลา |
| POST | `/api/approvals/{id}/reject` | ปฏิเสธใบลา |

### Reports
| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/reports/summary` | สรุปสถิติการลา |
| GET | `/api/reports/export-excel` | Export CSV |
| GET | `/api/reports/export-pdf` | Export PDF |

### Admin
| Method | Endpoint | Description |
|---|---|---|
| GET/POST | `/api/admin/users` | จัดการผู้ใช้ |
| PUT/DELETE | `/api/admin/users/{id}` | แก้ไข/ลบผู้ใช้ |
| GET/POST | `/api/admin/leave-types` | จัดการประเภทการลา |
| PUT/DELETE | `/api/admin/leave-types/{id}` | แก้ไข/ลบประเภทการลา |
| GET/POST | `/api/admin/workflow-steps` | จัดการขั้นตอนอนุมัติ |

---

## Features

- ✅ Authentication ด้วย Laravel Sanctum
- ✅ ยื่นใบลาออนไลน์ + แนบเอกสาร
- ✅ Multi-level Approval Workflow
- ✅ หักวันลาอัตโนมัติเมื่ออนุมัติสำเร็จ
- ✅ ยกเลิกใบลาที่ยัง Pending
- ✅ Dashboard + สถิติรายเดือน
- ✅ รายงานสถิติ + Export CSV/PDF
- ✅ Admin: จัดการผู้ใช้, ประเภทการลา, Workflow
- ✅ Thai Date (พ.ศ.) ทุกหน้า
- ✅ PDF ใบลาแบบราชการ
- ✅ Responsive (Desktop / Tablet / Mobile)

---

## Thai Date Rules

- **Database**: เก็บเป็น ค.ศ. (YYYY-MM-DD)
- **Frontend**: แสดงเป็น พ.ศ. ผ่าน `ThaiDateFormatter`
- **PDF**: แสดงเป็น พ.ศ. ผ่าน `ThaiDateService`

---

## Running Tests

```bash
docker exec -it eleave_backend bash
php artisan test
```

---

## Docker Services

| Service | Port | Description |
|---|---|---|
| eleave_backend | 8000 | Laravel API |
| eleave_db | 3306 | MySQL 8 |
| eleave_frontend | 5173 | Vue Dev Server |
