# FINAL PRODUCTION ACCEPTANCE

ตรวจ SARABAN Module ทั้งหมดเทียบ Master
ห้ามเชื่อ report อย่างเดียว
ตรวจ code/schema/migrations/tests/evidence จริง

ประเมิน:
PASS / PARTIAL / FAIL / NOT TESTED / BLOCKED-EXTERNAL / N/A

ตรวจ:
Core integration, UI compatibility, Auth/Personnel/Org/ABAC,
Document, Numbering concurrency, Incoming, Workflow/versioning/simulation,
Authority Matrix, PDF/E-Kasian/Signature, Step-up re-auth,
Distribution/Acknowledgement, Notification providers,
API/Webhook/Outbox/MCP-ready, Policy Center, Exception Queue,
Command Center, Feature Flags, Comparison/Case/Redaction/Saved Views,
Compliance Evidence, Search/Archive/Retention, Security,
Regression modules เดิม, Deploy/TLS/DNS/Migrations,
Backup/Restore/DR, Rollback rehearsal, UAT, Monitoring

Critical item ไม่ PASS = NO-GO

สร้าง:
`docs/saraban-integration/FINAL-PRODUCTION-ACCEPTANCE.md`

ถ้าทุก critical PASS:
สร้าง `PRODUCTION-GO-LIVE-SIGNOFF.md`
