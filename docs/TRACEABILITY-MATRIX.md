# TRACEABILITY MATRIX
เอกสารนี้ต้องอัปเดตระหว่างพัฒนา

| Requirement | Spec | Implementation | Test | Status |
|---|---|---|---|---|
| REQ-CORE-001 | 08-MODULE-STANDARDS, CORE specs | TBD | TBD | Planned |
| REQ-PER-001 | CORE-02-PERSONNEL | TBD | TBD | Planned |
| REQ-LEAVE-002 | LEAVE-01-OVERVIEW | TBD | TBD | Planned |
| REQ-ATT-001 | TIME-01-WORK-SCHEDULE | TBD | TBD | Planned |
| REQ-ATT-002 | ATT-02-DEVICE-INTEGRATION | TBD | TBD | Planned |
| REQ-NOT-001 | NOTIFY-01-NOTIFICATION | TBD | TBD | Planned |
| LEGACY-01-BACKUP | LEGACY-MIGRATION-PLAN 01-0 | Spatie backup, private disk, scheduler/worker | backup run + isolated restore drill | Verified |
| LEGACY-01-CI | LEGACY-MIGRATION-PLAN 01-6 | `.github/workflows/ci.yml` | Pint, 130 tests, MySQL 8 fresh migration, Vite build | Verified |
| LEGACY-01-OBS | LEGACY-MIGRATION-PLAN 01-7/01-8 | production guard, structured log, request context middleware | ProductionEnvironmentGuardTest, RequestContextTest | Verified |
| LEGACY-01-LEAVE | LEGACY-MIGRATION-PLAN 01-9/01-10 | permission authorization, checked status varchar | LeaveTest, GovernmentLeaveGovernanceTest | Verified |
| LEGACY-01-REFDATA | LEGACY-MIGRATION-PLAN 01-11 | FiscalYearSeeder, ThaiGovernmentHoliday2569Seeder, DocumentTemplateSeeder | BaselineReferenceDataSeederTest | Verified |
| LEGACY-02-FILES | LEGACY-MIGRATION-PLAN 02-A | private_uploads, SecureFileAccessService, nginx storage deny, attendance MIME validation | SecureFileAuthorizationTest, AttendanceImageSecurityTest | Verified |
| LEGACY-02-AUTH | LEGACY-MIGRATION-PLAN 02-C | account lockout, Sanctum expiry/rotation/revocation, reset flow, production session defaults | AuthenticationHardeningTest, AuthTest, frontend build | Verified |
| LEGACY-02-SIGN | LEGACY-MIGRATION-PLAN 02-D | mandatory password confirmation, assigned signer policy, sealed document hash, verify throttle | Saraban SignatureTest | Verified |
