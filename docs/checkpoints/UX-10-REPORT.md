# UX-10: Design System Consolidation Report

**วันที่ดำเนินการ:** 2026-08-15  
**เป้าหมาย:** สำรวจ จัดระเบียบ และกำหนดมาตรฐาน Design System บนระบบ E-la โดยสร้าง Canonical Component กลางใน `/components/ui/` สำหรับการพัฒนาฟีเจอร์ใหม่ พร้อมแก้ไขจุด Inconsistency ที่มีความเสี่ยงต่ำ (Low-Regression) และจัดทำข้อเสนอแนะการ Migration

---

## 1. ผลการสำรวจคอมโพเนนต์ที่ใช้งานจริง (UI Component Inventory)

จากการสำรวจ 40+ หน้าจอ (Views) และไฟล์ใน `/frontend/src/` พบรายละเอียดดังนี้:

| Component Type | สถานะเดิม | ปริมาณที่พบ (Inline) | Inconsistency Score | Canonical Component ใหม่ |
|---|---|:---:|:---:|---|
| **Button** | Inline ในทุก View | 80+ จุด (25+ files) | 🔴 High | `<AppButton />` |
| **Modal / Dialog** | Inline ในทุก CRUD | 26+ จุด (18+ files) | 🔴 High | `<AppModal />` |
| **Badge / Status Chip** | Inline | 20+ จุด | 🟠 Medium | `<AppBadge />` |
| **Pagination** | เขียนตรรกะใหม่ทุกหน้า | 4 จุด (4 files) | 🟠 Medium | `<AppPagination />` |
| **Input (Text/Search)** | Inline (2-3 generations) | 50+ จุด | 🟠 Medium | `<AppInput />` |
| **Select** | Inline | 30+ จุด | 🟠 Medium | `<AppSelect />` |
| **Textarea** | Inline | 15+ จุด | 🟡 Low | `<AppTextarea />` |
| **Card** | Inline Tailwind | 20+ จุด | 🟡 Low | ใช้ Tailwind Utility (`rounded-2xl border shadow-sm`) |
| **Table** | Inline `<table>` | 26 จุด (4 styles) | 🟠 Medium | ใช้ Tailwind Utility Standard (`w-full text-sm`) |
| **Tabs / Filter Segments** | Inline | 4 รูปแบบ | 🟡 Low | ใช้ Segment / Pill Pattern |
| **Checkbox** | Inline (บางจุดไม่มี style) | 8+ จุด | 🟡 Low | ใช้ `rounded text-[#800000] focus:ring-[#800000]` |
| **Radio** | Inline / Card Radio | 6+ จุด | 🟡 Low | ใช้ Card Radio Pattern (`sr-only` + label) |
| **Skeleton / Loading** | มี `LoadingState.vue` | 4 จุด bypass | 🟡 Low | `<LoadingState />` (Reuse) |
| **Empty State** | มี `EmptyState.vue` | 0 จุด bypass | ✅ None | `<EmptyState />` (Reuse) |
| **Error State** | มี `ErrorState.vue` | 0 จุด bypass | ✅ None | `<ErrorState />` (Reuse) |
| **Toast / Alert** | มี `AlertList.vue` | 4 inline banner | 🟡 Low | `AlertList.vue` + `useAlertStore()` |
| **Workflow Stepper** | มี `WorkflowTimeline.vue` | 0 จุด bypass | ✅ None | `<WorkflowTimeline />` (Reuse) |

---

## 2. รายการ Canonical Components (ชุดมาตรฐานทางการ)

| Canonical Component | ไฟล์พาธ | ความสามารถหลัก (Features) |
|---|---|---|
| **`<AppButton />`** | `frontend/src/components/ui/AppButton.vue` | Variants: `primary`, `danger`, `ghost`, `outline`, `secondary`<br>Sizes: `sm` (32px), `md` (44px mobile touch), `lg` (52px)<br>Props: `:loading` (spinner อัตโนมัติ), `:disabled`, `:block` |
| **`<AppBadge />`** | `frontend/src/components/ui/AppBadge.vue` | Variants: `success`, `warning`, `danger`, `info`, `brand`, `gray`<br>Props: `:dot` (จุดสีแสดงสถานะ), font `text-xs font-semibold rounded-full` |
| **`<AppModal />`** | `frontend/src/components/ui/AppModal.vue` | Props: `v-model`, `size` (`sm`, `md`, `lg`, `xl`, `full`), `title`, `closable`, `persistent`<br>Built-in: Keyboard Escape, Backdrop Blur (`bg-black/50`), Focus Trap, Scroll Lock |
| **`<AppPagination />`** | `frontend/src/components/ui/AppPagination.vue` | Props: `v-model:page`, `:total`, `:perPage`, `:maxPages`, `:showInfo`<br>Built-in: Page Calculation, Ellipsis (`...`), Prev/Next, Accessible ARIA |
| **`<AppInput />`** | `frontend/src/components/ui/AppInput.vue` | Props: `v-model`, `label`, `error`, `hint`, `required`, `disabled`<br>Slots: `#prepend`, `#append` สำหรับไอคอน, Auto Label `for`/`id` linking |
| **`<AppSelect />`** | `frontend/src/components/ui/AppSelect.vue` | Props: `v-model`, `label`, `error`, `hint`, `required`, `disabled`<br>Custom Styled Arrow, Focus Ring แบรนด์ `#800000` |
| **`<AppTextarea />`** | `frontend/src/components/ui/AppTextarea.vue` | Props: `v-model`, `label`, `error`, `hint`, `rows`, `resize`<br>Auto Label `for`/`id` linking, Error State styling |
| **`<LoadingState />`** | `frontend/src/components/ui/LoadingState.vue` | Existing Canonical: Spinner, Skeleton Card, Skeleton Table, Skeleton Form |
| **`<EmptyState />`** | `frontend/src/components/ui/EmptyState.vue` | Existing Canonical: Thai Empty State พร้อม Action CTA |
| **`<ErrorState />`** | `frontend/src/components/ui/ErrorState.vue` | Existing Canonical: Error Display พร้อม Retry Action |
| **`<WorkflowTimeline />`**| `frontend/src/components/ui/WorkflowTimeline.vue` | Existing Canonical: Multi-tier Approval Stepper & Rejection Banner |

---

## 3. มาตรฐาน Design Tokens (Consolidated Design Tokens)

### 1. Color Tokens
- **Brand Primary:** `#800000` (Maroon), Hover: `#600000`, Active: `#500000`, Light: `#800000`/10
- **Semantic Success:** `emerald-500` / `bg-emerald-100 text-emerald-800`
- **Semantic Warning:** `amber-500` / `bg-amber-100 text-amber-800`
- **Semantic Danger:** `red-600` / `bg-red-100 text-red-800`
- **Semantic Info:** `blue-600` / `bg-blue-100 text-blue-800`
- **Neutral Dark / Text:** `gray-900` (Title), `gray-700` (Body), `gray-500` (Muted)
- **Neutral Border:** `gray-200` (Default), `gray-100` (Subtle)

### 2. Sizing & Spacing Tokens
- **Touch Target (WCAG):** ขั้นต่ำ `min-h-[44px]` สำหรับ interactive elements บน Mobile
- **Input Padding:** `px-4 py-2.5` (Standard MD)
- **Card Padding:** `p-5` หรือ `p-6`

### 3. Border Radius Tokens
- **Pill / Badge:** `rounded-full`
- **Form Controls / Buttons:** `rounded-xl` (MD/LG), `rounded-lg` (SM)
- **Cards & Modals:** `rounded-2xl`

### 4. Focus & Interaction Standards
- **Focus Ring:** `:focus-visible:outline-none :focus-visible:ring-2 :focus-visible:ring-[#800000] :focus-visible:ring-offset-1`
- **Transitions:** `transition-colors duration-150`

---

## 4. รายการ Selective Fixes ที่ดำเนินการแก้ไขใน Phase นี้

1. **`views/admin/LeavePoliciesView.vue`**:
   - เพิ่ม styling class `rounded text-[#800000] focus:ring-[#800000] h-4 w-4` ให้กับ checkbox ทั้งหมดในฟอร์มที่เดิมไม่มี class
2. **`views/admin/UsersManagementView.vue`**:
   - แก้ไขสีแท็บ Active State จาก `bg-red-500` เป็นสีประจำแบรนด์ `bg-[#800000]`
3. **`views/saraban/DocumentDetailView.vue`**:
   - ปรับไวยากรณ์ Modal Backdrop จาก `bg-black bg-opacity-50` (Tailwind เก่า) เป็น `bg-black/50`
4. **`views/admin/RolesManagementView.vue`**:
   - ปรับเปลี่ยน Inline Spinner ซ้ำซ้อนให้เรียกใช้ `<LoadingState type="spinner" />`
5. **`views/DashboardView.vue`**:
   - ปรับเปลี่ยน DIY `animate-pulse` Skeleton ให้เรียกใช้ `<LoadingState type="skeleton-card" />`

---

## 5. แผนการ Migration แนะนำ (Migration Recommendations)

เนื่องจากระบบมีกว่า 80+ ปุ่มและ 26+ โมดอลในหน้าจอเดิม การ Refactor ทั้งหมดในคราวเดียวมีความเสี่ยง Regression สูง จึงแนะนำแนวทางดังนี้:

1. **New Features & Future Views (Phase ต่อไป):**
   - ทุกหน้าจอที่สร้างใหม่ **ต้องใช้ Canonical Components** จาก `/components/ui/` ทั้งหมด
2. **Gradual Adoption Strategy:**
   - **Step 1 (Low Risk):** ทยอยเปลี่ยน Pagination ใน `ApprovalsView`, `MyLeavesView`, `DocumentListView` มาใช้ `<AppPagination />`
   - **Step 2 (Medium Risk):** ทยอยเปลี่ยน Inline Badges และ Status Tags มาใช้ `<AppBadge />`
   - **Step 3 (High Risk):** ปรับเปลี่ยน CRUD Modal เป็น `<AppModal />` เมื่อมีการแตะต้องฟังก์ชันของหน้านั้นๆ (Refactor on Touch)
