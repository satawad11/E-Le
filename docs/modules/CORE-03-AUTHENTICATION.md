# CORE-03 Identity & Account (REBUILD-2569)

อ้างอิง: `REBUILD-PLAN-2569.md` §3.6 · ต้นแบบหน้า 1, 7, 8 · เฟส R-02, R-05

## Schema
### `users`
uuid, personnel_id (null สำหรับบัญชีระบบ), username, email, password,
`status` enum `pending`,`active`,`locked`,`suspended`,`separated`, locked_until, separated_at, separation_reason,
last_login_at, `account_type` enum `personnel`,`service`,`external`, `is_break_glass` bool

### `mfa_credentials`
user_id, type (`totp`), secret (encrypted), confirmed_at, recovery_codes (encrypted json), last_used_at

### `login_events` (append-only)
user_id null, username_attempted, result (`success`,`bad_password`,`locked`,`mfa_failed`,`new_device`), ip, user_agent, created_at

## วงจรบัญชี (Joiner–Mover–Leaver)
| เหตุการณ์ (จาก CORE-02) | ผลอัตโนมัติ |
|---|---|
| personnel.status → `active` | สร้าง/เปิดบัญชี `active` ส่งลิงก์ตั้งรหัสผ่าน |
| `AssignmentChanged` | ล้าง permission cache ของผู้ใช้ · แจ้งผู้ใช้ |
| status → `study_leave` / `suspended` | บัญชี `suspended` · ตัด token ทั้งหมด |
| status → `separated` | บัญชี `separated` · ตัด token · ปิด assignment/grant/override ที่ยังมีผล · ส่งงานค้างให้งานบุคคล |
| ไม่เข้าใช้ 90 วัน | `suspended` แจ้งงานบุคคล |
Job รายวัน `accounts:reconcile` ทำซ้ำทุกข้อให้ idempotent

## นโยบาย
- MFA บังคับเมื่อผู้ใช้มี assignment L1–L3, functional grant กลุ่ม HR, ผู้ดูแลระบบ หรือ break-glass
- ล็อก 30 นาทีหลังรหัสผ่านผิด 5 ครั้ง · token อายุตาม ADR-005 เดิม (ทบทวนใน R-05)
- อุปกรณ์ใหม่ → อีเมลแจ้งเตือน
- บัญชี `service` ไม่มี personnel ได้เฉพาะ permission ที่ระบุ ห้าม interactive login
- บัญชี break-glass: 1–2 บัญชี · ทุกการเข้าใช้แจ้ง ผอ. และผู้ตรวจสอบ · ไม่อยู่ในสายอนุมัติ

## Tests ขั้นต่ำ
ทุกเหตุการณ์ JML · MFA บังคับถูกกลุ่ม · ล็อกและปลดล็อก · บัญชี separated login ไม่ได้และ token เดิมใช้ไม่ได้
