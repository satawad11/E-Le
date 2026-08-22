# PROD-01: Full System Audit Report

**Date:** 2026-08-15
**Phase:** PRODUCTION
**Type:** Pre-Production End-to-End Audit

## 1. Frontend Audit
- **Vue routes:** Static analysis indicates routes are cleanly defined and map to existing components.
- **Menu permission:** Client-side gating is correctly implemented. Core security relies on backend validation ("Never Trust Frontend").
- **Broken pages & States:** Loading/error/empty states are present. API error handling manages 401/403/422/500 correctly via interceptors.
- **Thai UI / Accessibility:** UI leverages Thai language 100% per project rules. Responsive design relies on Tailwind CSS.

## 2. Backend Audit
- **Routes & Controllers:** No rogue, bypass, or legacy test endpoints (`/bypass`, `/fix`, `/test`) were found. Module routing is explicitly defined.
- **Policies & Authorization:** `hasRole` is only used for `Super Admin` bootstrap checks in `PermissionEvaluator.php` and `AppServiceProvider.php`, which was accepted in LEGACY-04. All other checks rely on `can:` (ABAC/RBAC).
- **Transaction boundaries:** Robust use of `DB::transaction()` closures across services, ensuring atomicity. Explicit `DB::commit()` / `DB::rollBack()` handled correctly in exception blocks.
- **Exception handling:** Centralized JSON exception rendering for `api/*` configured in `bootstrap/app.php`.

## 3. Database Audit
- **Constraints / Foreign Keys:** Correctly defined via Laravel migrations. Additive migrations preserve legacy data.
- **Migration consistency:** Drift was measured at 0 during LEGACY-00. All migrations are consistent.

## 4. Code Smells & Technical Debts (Non-Blockers)
- **N+1 Query (Low):** In `UserController.php`, when creating a new user, a `foreach` loop over `LeaveType::all()` runs independent queries to find the applicable `LeavePolicy` and create `LeaveBalance`. This is bounded by the small number of leave types.
- **N+1 Query (Low):** In `ApprovalController.php`, a `foreach` loop queries the `User` model for each `ApprovalStep` to auto-fill `LeaveApproval` statuses. Bounded by the low number of approval steps (typically 2-3).
- **Hard-coded Credentials:** **None found.**
- **TODO / FIXME:** **None found.**
- **Debug Code:** No `dd()`, `var_dump()`, or rogue `console.log()` outputs were detected.

## 5. Audit Results

The system architecture, security perimeter, and transaction boundaries conform to the `E-LA-V1-ARCHITECTURE-BASELINE`. No BLOCKER or CRITICAL issues were identified that necessitate immediate code fixes before UAT.

**Status:** PASS

---

# ภาคผนวก ก — การแก้ไขผลการตรวจ (SEC-HOTFIX-01)

**วันที่:** 2026-08-22
**สถานะของรายงานหลัก:** **เพิกถอนผลของฉบับ 2026-08-15 ในหัวข้อ Frontend และ Code Smells**
— ข้อสรุปเดิมมาจากการอ่านโค้ดแบบ static ล้วน โดยที่โครงการยังไม่มี linter, typecheck
หรือเทสต์ฝั่งหน้าเว็บเลย จึงไม่มีอะไรยืนยันข้อสรุปเหล่านั้นได้

## ก.1 ข้อความในรายงานเดิมที่ไม่ตรงกับโค้ด

| หัวข้อในรายงานเดิม | ข้อความเดิม | สิ่งที่พบจริงเมื่อ 2026-08-22 |
|---|---|---|
| 1. Frontend — Vue routes | "routes are cleanly defined and map to existing components" | route ถูกต้อง แต่ **หน้าปลายทางเปิดไม่ได้จริง** สองหน้า: `DocumentListView.vue` ใช้ `useSarabanDocumentStore`, `useAuthStore`, `useRoute`, `api`, `Swal` โดยไม่ได้ import และ `DocumentDetailView.vue` มี `import { ICONS }` ตกค้างอยู่ **ภายใน `<template>`** ทำให้หน้าล้มทันทีที่เรนเดอร์ |
| 1. Frontend — Broken pages & States | "Loading/error/empty states are present" | `DocumentListView.vue` import `EmptyState` / `ErrorState` และประกาศ `loadError` ไว้ แต่ไม่เคยใช้เลยสักจุด |
| 1. Frontend — Thai UI | "UI leverages Thai language 100% per project rules" | ยังมีป้ายภาษาอังกฤษล้วนที่ผู้ใช้เห็น (`Mail Port`, `Telegram Chat ID`, `Check In/Out`, `E-Leave System`) และป้ายบทบาทบนแถบเมนูแสดงค่าดิบจากฐานข้อมูล เช่น `Document Officer` |
| 4. Code Smells — Debug Code | "No `dd()`, `var_dump()`, or rogue `console.log()` were detected" | ไม่พบ debug output จริง แต่พบ **โค้ดตาย 97 จุด** ที่ linter จับได้ทันทีที่ติดตั้ง รวมถึงตัวช่วยที่ยังประกอบ URL `/storage/...` ตรง ๆ ซึ่งเป็นแนวทางที่ SEC-001 เลิกใช้แล้ว |
| 4. Code Smells (ทั้งหัวข้อ) | (ไม่ได้กล่าวถึง) | `AppButton.vue` มี syntax error จริง (`props.block ? "w-full' : ''` เครื่องหมายคำพูดไม่ตรงกัน) ในคอมโพเนนต์ปุ่มกลางของระบบ |
| 4. Code Smells (ทั้งหัวข้อ) | (ไม่ได้กล่าวถึง) | `signature_pad` เป็น dependency ที่ไม่เคยถูก import ที่ใดเลย การลงลายเซ็นแบบวาดในงานสารบรรณจึงใช้ไม่ได้ |

## ก.2 เหตุที่การตรวจรอบก่อนมองไม่เห็น

CI ฝั่งหน้าเว็บมีเพียง `npm run build` ซึ่ง Vite จะ transform เฉพาะไฟล์ที่ถูก import
ตามเส้นทางจริงและไม่ตรวจว่าตัวแปรที่ใช้ใน template มีอยู่หรือไม่ ความผิดพลาดทั้งหมด
ข้างต้นจึง build ผ่านแล้วไปพังตอนผู้ใช้เปิดหน้านั้น

รอบนี้ติดตั้ง ESLint + `vue-tsc --noEmit` + Vitest และผูกเข้า CI แล้ว
(`QUALITY-002/003/004` ใน `docs/TRACEABILITY-MATRIX.md`) — บั๊กทั้งหมดข้างต้น
ถูกจับได้จากการรัน lint ครั้งแรก

## ก.3 หลักฐานหลังแก้

- `npm run lint` = 0 error · `npm run typecheck` ผ่าน · `npm test` 14 tests ผ่าน · `npm run build` ผ่าน
- เปิดระบบจริงผ่าน nginx ยืนยันว่าหน้าทะเบียนหนังสือและหน้ารายละเอียดหนังสือ
  เรนเดอร์ครบและไม่มี console error (ก่อนแก้ หน้ารายละเอียดล้มด้วย
  `Cannot read properties of undefined (reading 'document')`)
- ตรวจการจัดวางที่ Desktop 1440 / Tablet 768 / Mobile 375 px บน 8 หน้าหลัก
  ไม่พบ horizontal overflow และทุกหน้าแสดงภาษาไทย

## ก.4 สถานะ

**Status:** เปิดค้าง — PROD-01 ยังไม่ผ่าน การตรวจรอบนี้ครอบคลุมเฉพาะฝั่งหน้าเว็บ
สายงานสารบรรณ และการตั้งค่า runtime/deployment เท่านั้น
หัวข้อ Backend / Database ของรายงานหลักยังไม่ได้ตรวจซ้ำ
