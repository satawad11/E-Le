# ARCHITECTURE

## Style
Modular Monolith + domain boundaries. แต่ละ domain มี application/service/repository/policy/event interfaces ของตนเอง

## Layers
UI -> Application/API -> Authorization/Validation -> Domain -> Repository -> PostgreSQL. Background worker consumes queued domain events for notifications, PDF and scheduled work.

## Dependency rule
Business modules อ้าง Core contracts; ห้าม business module เขียนข้าม schema ของ module อื่นโดยตรงถ้าไม่มี service contract/event.

## Internal events
ตัวอย่าง `LeaveSubmitted`, `LeaveApproved`, `AttendanceImported`, `WorkflowTaskCreated`. Event handlers ต้อง idempotent.

## Scalability
เริ่ม single deployment; แยก worker; รองรับ horizontal web workers; external storage; connection pool; Redis queue/cache.
