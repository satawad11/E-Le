# SARABAN V1.2 — EXISTING SYSTEM MODULE MASTER
## Production+ Integration Edition
### UI MUST FOLLOW EXISTING SYSTEM
### SINGLE SOURCE OF TRUTH

## 0. เป้าหมาย
เพิ่ม SARABAN V1.2 Production+ เป็น Business Module ในระบบเดิม โดย:
- Existing Core = Source of Truth
- Existing UI/Design System = Visual Source of Truth
- SARABAN = Business Module
- REUSE > EXTEND > MODIFY > CREATE
- ห้ามสร้าง Core ซ้ำ
- ห้าม rewrite ระบบเดิม
- ต้อง regression test ระบบเดิม

## 1. ลำดับความสำคัญ
1. Security / Data Integrity / Compliance
2. Existing Core Platform Contract
3. SARABAN Business Requirements
4. Existing System UI/Design System
5. Existing Module Convention
6. Developer preference

## 2. UI RULE — EXISTING SYSTEM FIRST
SARABAN ต้องใช้:
- App Shell / Sidebar / Topbar เดิม
- Theme / Tokens / Typography เดิม
- Buttons / Forms / Table / Drawer / Modal เดิม
- Toast / Loading / Empty / Error เดิม
- Responsive / Accessibility convention เดิม

ห้าม:
- สร้าง sidebar ใหม่
- เปลี่ยน theme ทั้งระบบ
- ใช้ UI kit คนละชุดโดยไม่จำเป็น
- hard-code สี/spacing กระจาย
- redesign ระบบเดิมเพียงเพื่อ SARABAN

สร้าง SARABAN-specific UI ใหม่ได้เฉพาะสิ่งที่ Core ไม่มี เช่น:
- PDF Workspace
- Receive Stamp Overlay
- Handwriting Canvas
- Signature Placement
- Workflow Route Visualizer
แต่ต้อง style ให้เข้ากับระบบเดิม

## 3. CORE ที่ต้องตรวจและ REUSE ก่อน
- Authentication / SSO / Sessions / MFA
- Users
- Personnel
- Organization
- Position / Assignment / Acting / Delegation
- RBAC / ABAC / Permission
- Module Registry / Menu Visibility
- Audit
- File Storage / Upload Security / Malware Scan
- Notification / Email / Queue / Redis / Scheduler
- API / OpenAPI / Rate Limit / Idempotency
- Webhook / Outbox
- Feature Flags ถ้ามี
- Shared UI / Design System
- Logging / Monitoring
- Backup / DR
- CI/CD / Deployment

ห้ามสมมติว่ามีหรือไม่มีโดยไม่ตรวจ repository/schema/docs

## 4. CORE OWNERSHIP
ระบบเดิมเป็นเจ้าของ Core ข้างต้นถ้ามีความสามารถเพียงพอ
SARABAN ห้ามสร้าง Auth/User/Personnel/Organization/Permission/Audit/Notification/API infrastructure ใหม่ซ้ำ

## 5. SARABAN DOMAIN OWNERSHIP
SARABAN เป็นเจ้าของ:
- documents / document_versions / document_types
- attachments metadata / document_relations
- incoming/outgoing/internal domain
- numbering / reservations
- workflow definitions/versions/instances/events
- endorsements / handwriting / signature evidence
- distributions / recipient snapshots / acknowledgements
- retention / legal hold
- cases/dossiers
- redacted copies
- saraban-specific policies/config

ทุก foreign reference ไป Core IDs เช่น user_id/personnel_id/organization_id/position_id

## 6. AUTHORIZATION
ทุก action:
Authentication
→ Subject Context
→ Validation
→ RBAC/ABAC
→ Object Authorization
→ Authority/Workflow Rule
→ Transaction
→ Audit

Default DENY
Frontend permission ไม่ใช่ security boundary

Permission namespace อย่างน้อย:
saraban.document.*
saraban.incoming.*
saraban.number.*
saraban.workflow.*
saraban.endorse.*
saraban.signature.*
saraban.distribution.*
saraban.report.*
saraban.policy.*
saraban.authority.*
saraban.exception.*
saraban.operations.*
saraban.feature_flags.*

## 7. MODULE REGISTRY
เพิ่ม Module: SARABAN
Features:
Dashboard, Incoming, Outgoing, Internal, Submission, Saraban Office,
Pending Review/Signature, Distribution, Personnel Inbox, Search/Archive,
Reports, Policy/Workflow Admin, Authority Matrix, Exception Queue,
Operations Command Center, Compliance Evidence

Menu visibility ใช้ระบบเดิม

## 8. DOCUMENT CORE
Document เป็น primary entity
PDF เป็น representation/version
Original ห้าม overwrite
Final/Issued เป็น primary view หลังออกหนังสือ
Workflow bind document_id + specific version

## 9. NUMBERING
รองรับ incoming/outgoing/internal/orders/announcements/configurable series
Buddhist Era + annual reset
Status: AVAILABLE, RESERVED, ALLOCATED, ISSUED, RELEASED, CANCELLED, VOID
ISSUED ห้าม reuse
Concurrency-safe mandatory
ห้าม SELECT MAX()+1 แบบไม่มี lock

## 10. INCOMING REGISTRATION
Incoming Inbox
เก็บ source number/date แยกจาก incoming register number/received date/time
Flow:
validate → duplicate detect → register → number → trusted time → receive stamp
→ PDF workspace → confirm → registered version → hash → audit → route

## 11. WORKFLOW
Dynamic/configurable:
mandatory, optional, conditional, return, revision, reject, cancel, forward,
delegate, parallel-ready, authorized skip
ห้าม hard-code คน

## 12. WORKFLOW VERSIONING + SIMULATION
Version: DRAFT/PUBLISHED/RETIRED
instance pin version
Simulation:
- read-only
- ใช้ Production Resolver เดียวกัน
- resolve assignment/delegation ตามเวลา
- ห้ามสร้าง instance/number/notification
- แสดง unresolved reason

## 13. AUTHORITY MATRIX
Projection จาก Personnel + Position + Assignment + Organization + Delegation
ต้องตอบได้ว่าใครมีอำนาจอะไรกับเอกสารประเภทใด ในหน่วยงานใด ณ เวลาใด
ห้ามสร้าง authority master ซ้ำ

## 14. PDF / E-KASIAN / SIGNATURE
PDF workspace:
zoom/page/drag/resize/normalized coordinates/stamp/text/date/QR/signature/handwriting
Backend render final PDF
ห้าม screenshot browser เป็น final PDF

E-Kasian:
typed + handwritten + stylus/touch/mouse + extra endorsement page

Signature evidence:
signer/position/authority/session/time/method/version/hash/IP/UA/audit
Sensitive signing ต้อง step-up re-auth

## 15. DISTRIBUTION / INBOX
ส่งรายบุคคล/หลายคน/unit/department/division/group/all authorized
TO/CC
Recipient Snapshot mandatory
DELIVERED/OPENED/ACKNOWLEDGED/ACCEPTED_FOR_ACTION/IN_PROGRESS/COMPLETED
OPENED != ACKNOWLEDGED

## 16. NOTIFICATION
ใช้ Core Notification ถ้ามี
Channels:
IN_APP, EMAIL, LINE_OA, TELEGRAM, WEB_PUSH, MOBILE_PUSH_READY
ใช้ outbox/queue/retry/dead-letter/templates/preferences/delivery tracking
Business transaction ห้าม rollback เพราะ provider fail

## 17. API / INTEGRATION / MCP
ใช้ Core API conventions
แนะนำ /api/v1/saraban/...
Reuse auth/ABAC/errors/correlation/rate limit/idempotency/OpenAPI/webhook
Service accounts/scopes ห้าม bypass authority

MCP:
Client → Core API/Integration Gateway → Auth → ABAC → SARABAN Service
ห้าม query DB ตรง
Sensitive signing กลับ Trusted Signing Session

## 18. POLICY CENTER
Central versioned policies:
numbering/workflow/signature/confidentiality/download/print/forward/distribution/SLA/
delegation/retention/public verification/notification/export
ต้อง versioned/published/rollback/audit

## 19. EXCEPTION QUEUE
รองรับ unresolved authority/workflow fail/number conflict/PDF/signature/duplicate/
notification/webhook/storage/data quality/migration anomaly
Actions: assign/retry/resolve/ack
ต้อง audit

## 20. OPERATIONAL COMMAND CENTER
รวม queues/pending/SLA/failed jobs/dead-letter/exceptions/provider health/storage/
backup/integrations/security alerts
ต้อง drill-down/retry/assign/ack ตามสิทธิ์

## 21. FEATURE FLAGS
ใช้ Core flags ถ้ามี
ถ้าไม่มีสร้าง reusable platform capability
Feature Flag != Permission
ทุก change audited

## 22. DOCUMENT COMPARISON / CASE / REDACTION / SAVED VIEWS
Comparison: metadata/file/hash/page/attachment diff
Case/Dossier: logical grouping ไม่เปลี่ยน document identity
Redaction: sanitized copy แยก original + reason/hash/audit
Saved Views: personal/shared work queues

## 23. COMPLIANCE EVIDENCE
รวมหลักฐานจริง:
permission/workflow/authority/audit/backup/restore/DR/security/UAT/accessibility/
migration/provider/contracts/incidents/retention
Status: PASS/PARTIAL/FAIL/NO_EVIDENCE/N/A
ห้าม claim PASS โดยไม่มี evidence จริง

## 24. STEP-UP RE-AUTH
Sensitive actions:
sign, cancel/void issued doc, number release/cancel, authority changes,
policy/workflow publish, confidential export, protected archive download, critical admin
Server-side enforce recent re-auth/MFA/assurance + audit reference

## 25. SEARCH / RETENTION / AUDIT
Search metadata ครบ
Legal Hold
Disposal workflow
Official issued docs ห้าม normal delete
Technical Audit ใช้ Core Audit Service
Human Timeline แยกได้

## 26. SECURITY
Server-side authz, object auth/IDOR defense, CSRF/XSS/SQLi defenses,
secure headers, rate limits, private storage, malware scan, MIME/magic-byte validation,
secret management, TLS, encryption as policy, least privilege, separation of duties,
step-up re-auth

## 27. OPERATIONS / BACKUP / DR
Integrate Core Ops:
health, DB, Redis, queue, PDF worker, provider, storage, backups, restore tests,
RPO/RTO, maintenance/read-only, rollback

## 28. REPORTS
ทะเบียนรับ/ส่ง/ภายใน/คำสั่ง/ประกาศ/จองเลข/คืนเลข/VOID/
รอตรวจ/รอรอง/รอผอ./รอลงนาม/SLA/distribution/ack/action/audit
Export PDF/Excel/CSV ตาม permission

## 29. INTEGRATION ROADMAP
INT-00 Existing System Assessment
INT-01 Core Contract Mapping
INT-02 Module Registry + Permission Namespace
INT-03 Data Model + Core References
INT-04 File/Audit/Trusted Time
INT-05 Document Core
INT-06 Numbering Engine
INT-07 Incoming Registration
INT-08 Workflow + Authority
INT-09 Workflow Versioning + Simulation
INT-10 PDF Workspace
INT-11 E-Kasian + Signature + Step-up Re-auth
INT-12 Finalization + QR + Verification
INT-13 Distribution + Inbox + Acknowledgement
INT-14 Notification Providers
INT-15 API + Webhook + Outbox + MCP-ready
INT-16 Policy Center + Authority Matrix
INT-17 Exception Queue + Command Center + Feature Flags
INT-18 Comparison + Case/Dossier + Redaction + Saved Views
INT-19 Compliance Evidence + Search/Archive/Reports
INT-20 Security + Performance + Observability
INT-21 Backup/Restore/DR + Production Evidence
INT-22 Regression + UAT + Production Acceptance

## 30. CHECKPOINT RULE
ทุก INT-XX:
- อ่าน Master + report ล่าสุด
- inspect เฉพาะ code/schema/tests ที่เกี่ยวข้อง
- REUSE > EXTEND > MODIFY > CREATE
- implement จริง
- migration safe
- tests
- security/authz
- regression ระบบเดิม
- UI QA ตาม UI เดิม
- evidence
- report

Report:
docs/saraban-integration/INT-XX-REPORT.md
Fields:
DONE, CHANGED, FILES, MIGRATIONS, TESTS, SECURITY, REGRESSION, UI-QA,
EVIDENCE, RISKS, NEXT

ห้าม DONE หาก Definition of Done ยังไม่ผ่าน

## 31. PRODUCTION ACCEPTANCE
ก่อน GO ต้องมี evidence จริง:
deploy, TLS/DNS, migrations, backup, restore, DR, rollback rehearsal,
provider notifications, API/webhook, security acceptance, UAT, performance,
audit, monitoring

Critical ไม่ PASS = NO-GO

## 32. FINAL DEFINITION OF DONE
- SARABAN อยู่ใน shell เดิม
- Login/Personnel/Organization/Permission/Audit/Notification/API ใช้ Core เดียว
- UI ใช้ระบบเดิม
- ไม่มี duplicate core
- functional requirements ครบ
- Production+ capabilities ครบ
- regression ระบบเดิมผ่าน
- production evidence จริง
- final acceptance = GO
