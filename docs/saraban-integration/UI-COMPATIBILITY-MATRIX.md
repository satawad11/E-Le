# UI COMPATIBILITY MATRIX — SARABAN V1.2 Integration

INT-00 · สำรวจเมื่อ 2026-09-01 · branch `chore/remove-saraban-module` (HEAD `2d8f056`)
Source of Truth: `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md` §2 (UI RULE — EXISTING SYSTEM FIRST)

> **กฎสูงสุดของเอกสารนี้:** UI ของระบบเดิมคือ Visual Source of Truth
> SARABAN สร้าง UI ใหม่ได้เฉพาะสิ่งที่ Core ไม่มี และต้อง style ให้เข้ากับระบบเดิม
> ห้ามสร้าง sidebar ใหม่ · ห้ามเปลี่ยน theme ทั้งระบบ · ห้ามใช้ UI kit คนละชุด ·
> ห้าม hard-code สี/spacing กระจาย · ห้าม redesign ระบบเดิมเพื่อ SARABAN

---

## 0. Stack ของหน้าเว็บเดิม

| รายการ | ค่าจริง | ที่มา |
|---|---|---|
| Framework | Vue 3.5.38 (`<script setup>`) | `frontend/package.json` |
| Build | Vite 8.0.16 + `@vitejs/plugin-vue` 6 | `frontend/package.json` |
| CSS | **Tailwind CSS v4.3.1** ผ่าน `@tailwindcss/vite` (ไม่มี `tailwind.config.js` — ใช้ `@theme` ใน CSS) | `frontend/src/style.css` |
| State | Pinia 3.0.4 | `frontend/src/stores/` |
| Router | vue-router 5.1.0 · **routes ประกาศแบบ static** | `frontend/src/router/index.js` (388 บรรทัด) |
| HTTP | axios 1.19.0 ผ่าน client กลาง `src/axios.js` | — |
| Type check | `vue-tsc --noEmit` (CI บังคับ) | `frontend/tsconfig.json` |
| Test | Vitest 4.1.11 + `@vue/test-utils` + jsdom | `frontend/tests/` |

---

## 0.1 Matrix ตามแบบฟอร์มมาตรฐาน

ตาม `UI-COMPATIBILITY-MATRIX-TEMPLATE.md` — รายละเอียดเต็มของแต่ละแถวอยู่ในหัวข้อ 1–11 ด้านล่าง

| UI Area | Existing Pattern/Component | Reuse | SARABAN Need | Action |
|---|---|---|---|---|
| App Shell | `src/App.vue` — `h-screen flex bg-gray-50` + skip link + `AlertList` + idle logout · โหมดไร้ shell ผ่าน route meta `bare` | **ใช่ 100%** | ทุกหน้าอยู่ใน shell เดิม · หน้าตรวจสอบหนังสือสาธารณะใช้ `bare` | **REUSE** — ห้ามสร้าง shell ที่สอง |
| Sidebar/Topbar | `<aside>` ใน `App.vue` `bg-[#800000]` `w-64`/`w-20` + mobile drawer `w-72` · เมนูจาก `AppMenuNav` ← `menuStore` ← `GET /api/user/menus` ← `MenuService` (กรองด้วย Gate + module enabled) | **ใช่ 100%** | เมนู SARABAN 16 รายการ + badge จำนวนงานค้าง | **REUSE + EXTEND** — เพิ่มแถวใน `menus` ผ่าน migration · ส่ง badge เพิ่มใน `menuBadges` · **ห้ามสร้าง sidebar ใหม่ (SoT §2)** |
| Theme/Tokens | `src/style.css` (75 บรรทัด) — `@theme` เดียวของระบบ · `--color-primary: #800000` + maroon 50–900 · `--spacing: 0.266667rem` | **ใช่ 100%** | ใช้ token เดิมทั้งหมด | **REUSE** — ห้ามประกาศ `@theme` ที่สอง · ห้ามนำ palette "Dark Navy" จากสเปกรุ่นก่อนมาใช้ |
| Typography | `--font-sans: 'IBM Plex Sans Thai', 'Sarabun', 'TH Sarabun New'` · `html { font-size: 93.75% }` (15px) · หัวหน้า `text-2xl font-bold text-gray-800` | **ใช่ 100%** | ข้อความไทยทั้งหมดรวม `aria-label` | **REUSE** |
| Buttons/Forms | `AppButton` (variant primary\|danger\|ghost\|outline\|secondary · size sm\|md\|lg · loading · disabled) · `AppInput`/`AppSelect`/`AppTextarea` (modelValue/label/error/required/disabled) · `ThaiDatePicker` (พ.ศ.) | **ใช่ 100%** | ฟอร์มทะเบียนรับ/ส่ง/ภายใน · วันที่หนังสือเป็น พ.ศ. | **REUSE** — `ThaiDatePicker` บังคับใช้กับทุกวันที่ |
| Data Table | **ไม่มี component กลาง** — ทุกหน้าเขียน `<table>` เองด้วยคลาสชุดเดียวกัน (`overflow-x-auto` + `thead bg-gray-50 text-xs uppercase` + `tbody divide-y`) | บางส่วน (รูปแบบ ใช่ / component ไม่มี) | ทะเบียน/กล่องเอกสาร 10+ หน้า | **CREATE `AppDataTable`** ใน `components/ui/` ตามคลาสเดิมเป๊ะ · **ห้ามไปแก้หน้าเดิมให้มาใช้** (unrelated refactor) |
| Drawer/Modal | `AppModal` (modelValue/title/size sm\|md\|lg\|xl\|**full**) มีครบ · **Drawer มีเฉพาะ mobile nav ใน `App.vue` ไม่มีตัวใช้ซ้ำได้** | Modal ใช่ / Drawer ไม่มี | ดูรายละเอียดหนังสือแบบ side panel · PDF Workspace ใช้ `size="full"` | **REUSE `AppModal`** + **CREATE `AppDrawer`** ใน `components/ui/` (ของกลาง) |
| Badges/Alerts/Toast | `AppBadge` (success\|warning\|danger\|info\|brand\|gray) · `AlertList.vue` + `stores/alert.js` (`success`/`error`/`info`) · `sweetalert2` สำหรับ confirm | **ใช่ 100%** | สถานะหนังสือ/สถานะเลข/ยืนยันการลงนาม | **REUSE + EXTEND** — map สถานะ SARABAN เข้ากับ 6 variant เดิม **ห้ามเพิ่มสีใหม่** · ห้ามสร้าง toast ตัวที่สอง |
| Responsive | breakpoint `md` แยก sidebar/drawer · หน้า `max-w-7xl mx-auto space-y-6` · หัวหน้า `flex-col sm:flex-row` · ตารางครอบ `overflow-x-auto` เสมอ | **ใช่ 100%** | ทุกหน้า SARABAN | **REUSE** — เดินตาม layout convention (หัวข้อ 6) |
| Accessibility | skip link · `.sr-only`/`.focus:not-sr-only` · `:focus-visible { outline: 2px solid #800000 }` · `role="dialog" aria-modal` + ปิดด้วย Esc · `aria-label` ภาษาไทย | **ใช่ 100%** | รักษาระดับเดิมทุกหน้า | **REUSE (บังคับ)** · ช่องว่าง: ยังไม่มีเทสต์ a11y อัตโนมัติ → งานของ INT-20 |
| PDF Viewer | **ไม่มี** — `DocumentDesignerView.vue` วางฟิลด์บนแม่แบบได้ แต่เป็นเครื่องมือผู้ดูแล ไม่ใช่ viewer | ไม่ | แสดงหนังสือ PDF ทุกหน้าจอ | **CREATE** + เพิ่ม dependency ใหม่ **ตัวเดียวของโครงการ** (เช่น `pdfjs-dist`) · ต้องผ่าน `npm audit --audit-level=high` · **แสดงผลเท่านั้น — Final PDF เรนเดอร์ที่ backend (SoT §14)** |
| Overlay Editor | **ไม่มี** — backend มี `PdfOverlayRenderer` + `CoordinateConverter` (พิกัด PDF point) แล้ว | ฝั่ง backend ใช่ / ฝั่ง UI ไม่มี | zoom/เลื่อนหน้า/ลาก/ปรับขนาด/ตรายางรับ/ข้อความ/วันที่/QR | **CREATE `PdfWorkspace` + `ReceiveStampOverlay`** ใน `components/saraban/` · พิกัดต้องเป็น normalized/PDF point ให้ตรงกับ `CoordinateConverter` เดิม |
| Handwriting Canvas | **`signature_pad` 5.1.3 เป็น dependency อยู่แล้ว** แต่ยังไม่มี component ครอบ | library ใช่ / component ไม่มี | ลายมือ + ลายเซ็น รองรับ stylus/touch/mouse (SoT §14) | **REUSE library** + **CREATE `HandwritingCanvas`** ใน `components/ui/` · ห้ามลง library ลายเซ็นตัวอื่น |
| Signature Placement | **ไม่มี** · ลายเซ็นผู้ใช้เก็บที่ `user_profiles.signature_path` แล้ว | แหล่งลายเซ็นใช่ / UI ไม่มี | วาง placeholder + ยืนยันตัวตนก่อนลงนาม | **CREATE `SignaturePlacement`** ใน `components/saraban/` · ต้องผ่าน step-up re-auth ฝั่ง server (SoT §24) · ไฟล์ลายเซ็นเรียกผ่าน `v-secure-src` เท่านั้น |

---

## 1. Design Tokens — REUSE (ห้ามแตะ)

ทั้งระบบมี token อยู่ที่ **ไฟล์เดียว**: `frontend/src/style.css` (75 บรรทัด)

| Token | ค่า | หมายเหตุ |
|---|---|---|
| `--color-primary` / `--primary` | `#800000` (เลือดหมู) | สีหลักของทั้งระบบ |
| `--color-maroon-50..900` | ไล่เฉด maroon เต็มสเกล | ใช้ผ่าน utility `bg-maroon-600` ฯลฯ |
| `--primary-dark` / `--primary-light` | `#600000` / `#a00000` | สถานะ hover/active |
| `--font-sans` | `'IBM Plex Sans Thai', 'Sarabun', 'TH Sarabun New', system-ui` | โหลดจาก Google Fonts |
| `--spacing` | `0.266667rem` | **pin ไว้ให้ = 4px** เพราะ base font 15px จะทำให้ spacing หด |
| `html { font-size }` | `93.75%` (= 15px) | ฐานตัวอักษรของทั้งระบบ |
| focus ring | `outline: 2px solid #800000; outline-offset: 2px` | มาตรฐาน a11y ของระบบ |
| scrollbar | 6px · thumb `#800000` | — |

**ตัดสิน: REUSE ทั้งหมด**
SARABAN **ห้าม** เพิ่ม token ชุดใหม่, ห้ามประกาศ `@theme` ที่สอง, ห้ามนำ palette
"Dark Navy Sidebar" จากสเปกรุ่นก่อนมาใช้ — §2 ของ SoT ระบุชัดว่า theme ของระบบเดิมชนะ

> **ข้อสังเกตที่ต้องแจ้ง:** โค้ดเดิมจำนวนมากเขียนสีเป็น `bg-[#800000]` ตรง ๆ
> (เช่น `App.vue`, `PersonnelAssignmentsView.vue`) แทนที่จะใช้ utility จาก token
> SARABAN ต้อง **ใช้ token/utility** ไม่สืบทอดนิสัย hard-code นี้ (§2 ห้าม hard-code สีกระจาย)
> แต่ก็ **ห้ามไล่แก้ของเดิม** เพราะเป็น unrelated refactor ที่ข้อบังคับห้ามไว้

---

## 2. App Shell / Navigation — REUSE (ห้ามสร้างใหม่)

| # | ส่วนประกอบ | ของเดิม | ตัดสิน |
|---|---|---|---|
| 2.1 | App Shell | `frontend/src/App.vue` — `h-screen flex bg-gray-50` | **REUSE** |
| 2.2 | Sidebar (desktop) | `<aside>` ใน `App.vue` · `bg-[#800000] text-white` · กว้าง `w-64` / ย่อเหลือ `w-20` · มีปุ่มพับ | **REUSE — ห้ามสร้าง sidebar ใหม่ (§2)** |
| 2.3 | Drawer (mobile) | panel `w-72 max-w-[85vw]` + overlay `bg-black/60 backdrop-blur-sm` · `role="dialog" aria-modal` · ปิดด้วย Esc | **REUSE** |
| 2.4 | รายการเมนู | `components/AppMenuNav.vue` รับ `menus`, `collapsed`, `badges` · emit `navigate` | **REUSE + EXTEND (ส่ง badge ของ SARABAN เพิ่ม)** |
| 2.5 | แหล่งข้อมูลเมนู | `stores/menuStore.js` → `GET /api/user/menus` → `MenuService` (กรองด้วย Gate + module enabled) | **REUSE — เมนู SARABAN ต้องมาจากฐานข้อมูลผ่านเส้นทางนี้เท่านั้น** |
| 2.6 | ข้อมูลผู้ใช้ + badge role | บล็อกใน sidebar ลิงก์ไป `/profile` | **REUSE** |
| 2.7 | Idle logout | ตัวนับ `formattedIdleTime` + `confirmLogout()` | **REUSE** |
| 2.8 | Skip to content | `<a href="#main-content" class="sr-only focus:not-sr-only ...">` | **REUSE (บังคับ)** |
| 2.9 | Layout แบบไม่มี shell | `isBareLayout` จาก route meta `bare: true` (ใช้กับหน้า verify สาธารณะ) | **REUSE** — หน้าตรวจสอบหนังสือสาธารณะ (§ SoT 12/ INT-12) ใช้รูปแบบนี้ |

---

## 3. Routing — REUSE + EXTEND

| # | รายการ | ของเดิม | ตัดสิน |
|---|---|---|---|
| 3.1 | นิยาม route | static array ใน `src/router/index.js` · lazy `() => import()` สำหรับหน้าย่อย | **EXTEND** — เพิ่ม route ของ SARABAN ในไฟล์เดิม ห้ามสร้าง router instance ที่สอง |
| 3.2 | route meta | `{ requiresAuth, requiresGuest, title, parent, bare, public }` | **REUSE** — `parent` ของ SARABAN = ชื่อกลุ่มเมนูภาษาไทย |
| 3.3 | Guard | guard ตรวจ `requiresAuth`/`requiresGuest` ใน router | **REUSE** |
| 3.4 | สิทธิ์ที่ router | โครงการ **จงใจไม่ผูก permission ที่ router** (เห็นได้จากคอมเมนต์ของ `/report-center`) — ให้ backend ตรวจซ้ำทุกครั้ง | **REUSE (สำคัญ)** — ตรงกับ §6 "Frontend permission ไม่ใช่ security boundary" |

---

## 4. UI Kit ที่มีอยู่ — REUSE

ทั้งหมดอยู่ที่ `frontend/src/components/ui/`

| Component | API จริง | ใช้กับ SARABAN | ตัดสิน |
|---|---|---|---|
| `AppButton` | `type`, `variant` = primary\|danger\|ghost\|outline\|secondary, `size` = sm\|md\|lg, `loading`, `disabled` | ทุกปุ่ม | **REUSE** |
| `AppInput` | `modelValue`, `label`, `error`, `required`, `disabled` | ฟอร์มทะเบียนรับ/ส่ง | **REUSE** |
| `AppSelect` | เหมือน `AppInput` | ประเภทหนังสือ / ชั้นความลับ / ความเร่งด่วน | **REUSE** |
| `AppTextarea` | เหมือน `AppInput` | สาระสำคัญ / ความเห็น | **REUSE** |
| `AppModal` | `modelValue`, `title`, `size` = sm\|md\|lg\|xl\|**full** | ยืนยันการลงนาม / ดูรายละเอียด · `size="full"` ใช้กับ PDF Workspace ได้ | **REUSE** |
| `AppBadge` | `variant` = success\|warning\|danger\|info\|brand\|gray | สถานะหนังสือ / สถานะเลข | **REUSE + EXTEND** (map สถานะ SARABAN เข้ากับ 6 variant นี้ ห้ามเพิ่มสีใหม่) |
| `AppPagination` | emit `update:page` | ทุกทะเบียน/กล่องเอกสาร | **REUSE** |
| `EmptyState` | `icon`, `title`, `description`, action + emit `action` | กล่องหนังสือว่าง | **REUSE** |
| `ErrorState` | `icon`, `title`, `message`, retry + emit `retry` | โหลดล้มเหลว | **REUSE** |
| `LoadingState` | `type`, `message`, จำนวนแถว/คอลัมน์ (skeleton) | ทุกหน้า | **REUSE** |
| `PermissionDenied` | `title`, `message` | หน้าที่สิทธิ์ไม่ถึง | **REUSE (บังคับ)** |
| `WorkflowTimeline` | `props.leave` (Object, required) — **ผูกกับโครงสร้างใบลาโดยตรง** | เส้นทางเดินหนังสือ | **EXTEND** — ดู 7.1 |

| Component นอก `ui/` | หน้าที่ | ตัดสิน |
|---|---|---|
| `AlertList.vue` + `stores/alert.js` | Toast (`success`/`error`/`info` + alias `showSuccess`…) | **REUSE — ห้ามสร้าง toast ตัวที่สอง** |
| `NotificationBell.vue` | กระดิ่งแจ้งเตือน In-App | **REUSE + EXTEND** (แจ้งเตือน SARABAN เข้าช่องเดียวกัน) |
| `ThaiDatePicker.vue` | วันที่พุทธศักราช (ครอบ `@vuepic/vue-datepicker`) | **REUSE — บังคับ** สำหรับทุกวันที่ในหนังสือ |
| `LeaveEvidenceManager.vue` | อัปโหลด/แสดงไฟล์แนบของใบลา | **REUSE เป็นแบบอย่าง** — รูปแบบไฟล์แนบของ SARABAN ให้เดินตาม (ห้ามแก้ตัวนี้) |
| `admin/UserPermissionModal.vue` | จัดการสิทธิ์รายบุคคล | **REUSE** — สิทธิ์ `saraban.*` จะโผล่ที่นี่เองเมื่อลงทะเบียนใน catalog |

---

## 5. Directives / Utilities — REUSE

| # | รายการ | ของเดิม | ตัดสิน |
|---|---|---|---|
| 5.1 | ซ่อน UI ตามสิทธิ์ | `v-can="'leave.approve'"` หรือ array · `src/directives/can.js` (ถอด element ออกจาก DOM) | **REUSE** — ใช้ `v-can="'saraban.document.create'"` · **ย้ำ: เป็น UX ไม่ใช่ security boundary** |
| 5.2 | ไฟล์ที่ต้องยืนยันตัวตน | `v-secure-src` · `src/directives/secureSrc.js` → `GET /api/files/{path}` | **REUSE — บังคับ** สำหรับไฟล์แนบและ PDF ของหนังสือ ห้ามใส่ URL ตรง |
| 5.3 | ข้อความ error จาก API | `src/utils/errorHandler.js` (`getErrorMessage`) มีเทสต์คุมอยู่ | **REUSE** |
| 5.4 | HTTP client | `src/axios.js` — แนบ token, retry 429 แบบ exponential + เคารพ `Retry-After`, dedupe GET ของ `/user`,`/user/menus`,`/settings`,`/notifications`, **401 เท่านั้นที่ล้าง token** | **REUSE + EXTEND** — ถ้าจะ dedupe เส้นทาง SARABAN ให้เพิ่มใน `DEDUPLICATED_PATHS` เดิม |

---

## 6. รูปแบบหน้าจอ (Layout Convention) — REUSE

ถอดจากหน้า admin ที่เขียนล่าสุด (`PersonnelAssignmentsView.vue`, `ReportCenterView.vue` ฯลฯ)
SARABAN ต้องเดินตามรูปแบบนี้ทุกหน้า:

```
<div class="max-w-7xl mx-auto space-y-6">          ← กรอบหน้า
  <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-800">ชื่อหน้า</h1>
      <p class="text-sm text-gray-500 mt-1">คำอธิบายหนึ่งบรรทัด</p>
    </div>
    <AppButton …>ปุ่มหลัก</AppButton>        ← มุมขวาบน
  </div>

  <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
    <header class="px-5 py-4 border-b border-gray-100">…</header>
    <div class="overflow-x-auto">              ← ตารางต้องเลื่อนแนวนอนได้เสมอ
      <table class="w-full text-sm min-w-[…]">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
        <tbody class="divide-y divide-gray-50"> <tr class="hover:bg-gray-50/60">
```

| องค์ประกอบ | ค่ามาตรฐาน |
|---|---|
| ความกว้างหน้า | `max-w-7xl mx-auto` |
| ระยะห่างบล็อก | `space-y-6` |
| การ์ด | `bg-white border border-gray-100 rounded-2xl shadow-sm` |
| หัวข้อหน้า | `text-2xl font-bold text-gray-800` + คำอธิบาย `text-sm text-gray-500` |
| หัวตาราง | `bg-gray-50 text-gray-500 text-xs uppercase` |
| มุมโค้ง | ปุ่ม/อินพุต `rounded-xl` · การ์ด `rounded-2xl` |
| ภาษา | **ข้อความบนหน้าจอเป็นภาษาไทยทั้งหมด** รวม `aria-label` |

---

## 7. ส่วนที่ต้องสร้างใหม่ — CREATE (แต่ต้องเป็นของกลาง ไม่ใช่ของ SARABAN)

§2 ของ SoT อนุญาตให้สร้าง UI ใหม่เฉพาะสิ่งที่ Core ไม่มี
รายการต่อไปนี้ตรวจแล้วว่า **ไม่มีในระบบเดิมจริง**

| # | สิ่งที่ต้องสร้าง | เหตุผล | ที่อยู่ที่ถูกต้อง | Checkpoint |
|---|---|---|---|---|
| 7.1 | `WorkflowRouteVisualizer` | `WorkflowTimeline.vue` มีอยู่แต่ **รับ `props.leave` และอ่านฟิลด์ของใบลาโดยตรง** ใช้กับหนังสือไม่ได้ · การแก้ตัวเดิมให้รับ generic = แตะโมดูลลา (unrelated) | `components/ui/` ตัวใหม่ที่รับ step ทั่วไป · **ห้ามแก้ `WorkflowTimeline.vue`** | INT-09 |
| 7.2 | `PdfWorkspace` (zoom / เลื่อนหน้า / ลาก / ปรับขนาด / พิกัด normalized) | ไม่มีของเดิม · `DocumentDesignerView.vue` วางฟิลด์บนแม่แบบได้แต่เป็นเครื่องมือของผู้ดูแล ไม่ใช่ workspace ของผู้ใช้ | `components/saraban/` | INT-10 |
| 7.3 | `ReceiveStampOverlay` | ไม่มีของเดิม | `components/saraban/` | INT-07 |
| 7.4 | `HandwritingCanvas` | **`signature_pad` 5.1.3 เป็น dependency อยู่แล้ว** → REUSE library, CREATE เฉพาะ wrapper รองรับ stylus/touch/mouse | `components/ui/` | INT-11 |
| 7.5 | `SignaturePlacement` | ไม่มีของเดิม | `components/saraban/` | INT-11 |
| 7.6 | `AppDrawer` | §2 ระบุ "Drawer เดิม" แต่ **ระบบเดิมมีเฉพาะ mobile nav drawer ใน `App.vue` ไม่มี drawer ที่ใช้ซ้ำได้** | `components/ui/` (ของกลาง) | INT-05 |
| 7.7 | `AppDataTable` | ทุกหน้าเขียน `<table>` เองด้วยคลาสชุดเดียวกัน — SARABAN มีทะเบียน 10+ หน้า ถ้าคัดลอกต่อจะกลายเป็นหนี้ | `components/ui/` (ของกลาง) · **ห้ามไปแก้หน้าเดิมให้มาใช้** (unrelated refactor) | INT-05 |
| 7.8 | `AppTabs` | ไม่มีของเดิม · กล่องหนังสือ (§15) ต้องมีแท็บ ใหม่/ยังไม่อ่าน/รอรับทราบ/… | `components/ui/` (ของกลาง) | INT-13 |
| 7.9 | `AppFileUpload` | มีเฉพาะ `LeaveEvidenceManager` ที่ผูกกับใบลา | `components/ui/` (ของกลาง) | INT-05 |

**หลักการ:** อะไรที่โมดูลอื่นก็ใช้ได้ → `components/ui/`
อะไรที่เป็นงานสารบรรณแท้ ๆ → `components/saraban/`

---

## 8. Library ที่มีอยู่แล้วและต้อง REUSE (ห้ามเพิ่มตัวใหม่)

| ความต้องการของ SARABAN | library ที่มีอยู่แล้ว | หมายเหตุ |
|---|---|---|
| ลายมือ / ลายเซ็น (§14 E-Kasian) | **`signature_pad` 5.1.3** | มีอยู่แล้ว — ห้ามลงตัวอื่น |
| กล่องข้อความยืนยัน | **`sweetalert2` 11.26.25** | ใช้ในหน้าจอเดิมแล้ว |
| วันที่ | **`@vuepic/vue-datepicker` 14** ผ่าน `ThaiDatePicker.vue` | บังคับใช้ตัวครอบ |
| ข้อความมีรูปแบบ (สาระสำคัญ/เนื้อหนังสือ) | **`@vueup/vue-quill` 1.5.5** | มีอยู่แล้ว |
| กราฟ (Command Center §20, รายงาน §28) | **`chart.js` 4.5.1 + `vue-chartjs` 5.3.4** | มีอยู่แล้ว |
| วันเวลา | **`date-fns` 4.4.0** | มีอยู่แล้ว |
| **แสดง PDF ในเบราว์เซอร์** | **ไม่มี** | ต้องเพิ่ม (เช่น `pdfjs-dist`) — เป็น dependency ใหม่ตัวเดียวที่จำเป็น · ต้องผ่าน `npm audit --audit-level=high` ของ CI · **ย้ำ §14: ใช้แสดงผลเท่านั้น Final PDF เรนเดอร์ที่ backend** |

---

## 9. Accessibility — REUSE (บังคับ)

ระบบเดิมทำ a11y ไว้จริง SARABAN ต้องรักษาระดับเดิม:

| รายการ | ของเดิม |
|---|---|
| Skip link | `App.vue` |
| `.sr-only` / `.focus:not-sr-only` | `style.css` |
| Focus ring | `:focus-visible { outline: 2px solid #800000 }` |
| `aria-label` ภาษาไทย | ทุกปุ่ม/แถบเมนูใน `App.vue` |
| Dialog | `role="dialog" aria-modal="true"` + ปิดด้วย Esc |
| ตาราง | ครอบด้วย `overflow-x-auto` เสมอ |

**ช่องว่าง:** ยังไม่มีการทดสอบ a11y อัตโนมัติใน CI → §23 (Compliance Evidence) ต้องการหลักฐาน
accessibility จริง → ตั้งเป็นงานของ INT-20

---

## 10. ด่านคุณภาพ UI ที่ต้องผ่านทุก checkpoint

จาก `.github/workflows/ci.yml` job `frontend`:

| ด่าน | คำสั่ง | ผลถ้าไม่ผ่าน |
|---|---|---|
| Lint | `npm run lint` (eslint 10 + eslint-plugin-vue 10) | CI แดง |
| **Typecheck** | `npm run typecheck` (`vue-tsc --noEmit`) | CI แดง |
| Test | `npm test` (vitest) | CI แดง |
| Audit | `npm audit --audit-level=high` | CI แดง — **จำกัดการเพิ่ม dependency ใหม่** |
| Build | `npm run build` | CI แดง |
| ไม่มีบัญชีทดสอบใน bundle | grep `superadmin@example.com` / `1111111111111` ใน `dist/` | CI แดง (SEC-027) |

และจากฝั่ง backend: **`tests/Feature/ApiSurfaceContractTest.php`** บังคับว่าทุก endpoint
ใต้ `api/` ต้องมีหน้าเว็บเรียกจริง → **backend กับ frontend ของ SARABAN ต้องส่งมาคู่กันทุก checkpoint**

---

## 11. สรุปการตัดสิน

| ตัดสิน | จำนวน | รายการ |
|---|---|---|
| **REUSE** | 31 | tokens, shell, sidebar, drawer, เมนู, router meta, UI kit 12 ตัว, toast, directives, axios, layout convention, a11y, library 6 ตัว |
| **EXTEND** | 6 | router array, `AppMenuNav` badges, `AppBadge` mapping, `NotificationBell`, `DEDUPLICATED_PATHS`, ด่าน CI |
| **CREATE** | 9 | 7.1–7.9 (ของกลาง 5 · ของ SARABAN 4) |
| **MODIFY** | 0 | — |
| dependency ใหม่ | 1 | ตัวแสดง PDF ในเบราว์เซอร์ |

**ห้ามเด็ดขาด:** sidebar ตัวที่สอง · theme/`@theme` ชุดที่สอง · toast ตัวที่สอง ·
router instance ที่สอง · UI kit คนละชุด · แก้ `WorkflowTimeline.vue` / `LeaveEvidenceManager.vue`
หรือหน้าจอของโมดูลเดิมเพื่อให้ SARABAN ใช้ซ้ำ (= unrelated refactor)
