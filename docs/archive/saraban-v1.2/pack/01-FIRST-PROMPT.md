# คำสั่งครั้งแรก

ใช้ `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md` เป็น Single Source of Truth

เป้าหมาย:
เพิ่ม SARABAN V1.2 Production+ เป็น Business Module ในระบบเดิม

ข้อบังคับ:
- UI ใช้ Design System/Layout/Components/Navigation ของระบบเดิม
- Existing Core เป็น Source of Truth
- REUSE > EXTEND > MODIFY > CREATE
- ห้าม duplicate Auth/User/Personnel/Organization/Permission/Audit/Notification/API
- ห้าม rewrite / unrelated refactor
- ห้าม hard-code user/position/authority
- Backend Authorization เป็น source of truth และ Default DENY
- Implement จริง
- Migration backward compatible
- Regression ระบบเดิมทุก Phase
- UI QA เทียบระบบเดิม
- ห้าม DONE ถ้า tests/evidence ไม่ครบ

เริ่ม `INT-00 — Existing System Assessment`

ก่อนแก้ code ตรวจ:
architecture, repository, DB/schema/migrations, auth/session/SSO/MFA,
users/personnel, organization/position/assignment, RBAC/ABAC,
module registry/menu, audit, file/storage/upload security,
notification/queue, API/OpenAPI/rate-limit/idempotency,
outbox/webhook, shared UI/design system, monitoring/logging,
backup/DR, deployment/CI/CD, dependencies ของ modules เดิม

สร้าง:
- `docs/saraban-integration/INT-00-REPORT.md`
- `docs/saraban-integration/CORE-COMPATIBILITY-MATRIX.md`
- `docs/saraban-integration/UI-COMPATIBILITY-MATRIX.md`

Compatibility Matrix ต้องจำแนก:
REUSE / EXTEND / MODIFY / CREATE / MIGRATE / DEPRECATE

ห้ามสร้าง duplicate Core ก่อน INT-00 เสร็จ
ถ้าไม่มี blocker จริง ให้ดำเนิน INT-01 ต่อ
