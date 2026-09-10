import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import DashboardView from '../views/DashboardView.vue'
import LeaveRequestView from '../views/LeaveRequestView.vue'
import ApprovalsView from '../views/ApprovalsView.vue'
import LoginView from '../views/LoginView.vue'
import ForgotPasswordView from '../views/ForgotPasswordView.vue'
import ResetPasswordView from '../views/ResetPasswordView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { requiresGuest: true, title: 'เข้าสู่ระบบ' }
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: ForgotPasswordView,
      meta: { requiresGuest: true, title: 'ลืมรหัสผ่าน' }
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: ResetPasswordView,
      meta: { requiresGuest: true, title: 'ตั้งรหัสผ่านใหม่' }
    },
    {
      /*
       * CLEANUP-01 (D1) — เส้นทางนี้ต้องรับ hash ตาม QR ที่พิมพ์ลงบนเอกสาร
       *
       * เดิมประกาศไว้ที่นี่แบบไม่มีพารามิเตอร์ แล้วถูก `main.js` ลงทะเบียนทับด้วย
       * `/verify-leave/:hash` (ชื่อ route เดียวกัน = แทนที่กันเงียบ ๆ)
       * `LeaveVerificationService` สร้างลิงก์เป็น `/verify-leave/{hash}` เสมอ
       */
      path: '/verify-leave/:hash',
      name: 'verify-leave',
      component: () => import('../views/VerifyLeaveView.vue'),
      meta: { requiresGuest: false, bare: true, public: true, title: 'ตรวจสอบใบลา' }
    },
    {
      /*
       * INT-12 — ตรวจสอบหนังสือราชการจาก QR (SoT §12)
       *
       * ใช้ meta ชุดเดียวกับหน้าตรวจสอบใบลา: `bare` = แสดงนอก app shell,
       * `public` = ไม่ต้องเข้าสู่ระบบ — ผู้เปิดคือคนทั่วไปที่ถือกระดาษอยู่ในมือ
       */
      path: '/verify-saraban/:hash',
      name: 'verify-saraban',
      component: () => import('../views/saraban/VerifyDocumentView.vue'),
      meta: { requiresGuest: false, bare: true, public: true, title: 'ตรวจสอบหนังสือราชการ' }
    },
    {
      path: '/',
      name: 'dashboard',
      component: DashboardView,
      meta: { requiresAuth: true, title: 'หน้าหลัก' }
    },
    // === ระบบลางาน ===
    {
      path: '/request',
      name: 'request',
      component: LeaveRequestView,
      meta: { requiresAuth: true, title: 'ยื่นใบลา', parent: 'ระบบลางาน' }
    },
    {
      path: '/leaves',
      name: 'my-leaves',
      component: () => import('../views/MyLeavesView.vue'),
      meta: { requiresAuth: true, title: 'รายการใบลาของฉัน', parent: 'ระบบลางาน' }
    },
    {
      path: '/reports',
      name: 'reports',
      component: () => import('../views/ReportsView.vue'),
      meta: { requiresAuth: true, title: 'รายงานสถิติการลา', parent: 'ระบบลางาน' }
    },
    {
      /*
       * CLEANUP-01 (D3) — หน้าจอของชั้นรายงานกลาง
       *
       * ชั้นรายงานกลาง (`/api/reporting/*`) สร้างไว้ตั้งแต่ LEGACY-11 แต่ไม่เคยมี
       * หน้าจอใดเรียกใช้เลย ผู้ใช้จึงเข้าไม่ถึงรายงานมาตรฐาน 8 ชนิดที่มีอยู่แล้ว
       * ไม่ผูก permission ที่ router — สารบัญรายงานคัดตามสิทธิ์ของผู้เรียกให้เองแล้ว
       * และแต่ละรายงานยังตรวจสิทธิ์ซ้ำที่ฝั่ง server ทุกครั้ง
       */
      path: '/report-center',
      name: 'report-center',
      component: () => import('../views/admin/ReportCenterView.vue'),
      meta: { requiresAuth: true, title: 'ศูนย์รายงานกลาง', parent: 'รายงาน' }
    },
    /*
     * รายงานที่ผู้ดูแลออกแบบเอง — ย้ายออกจาก "ศูนย์จัดการเอกสาร" (2026-09-09)
     *
     * เดิมรายการรายงานและหน้าออกแบบรายงานอยู่ใต้เมนูตั้งค่าระบบ ทั้งที่การเรียกดูรายงาน
     * เป็นงานประจำวันคนละเรื่องกับการตั้งค่า — ย้ายมาอยู่หมวด "รายงาน" ให้ตรงกับสิ่งที่ทำ
     */
    {
      path: '/reports/custom',
      name: 'custom-reports',
      component: () => import('../views/CustomReportsView.vue'),
      meta: {
        requiresAuth: true,
        permissions: ['document.report.view', 'document.report.manage', 'system.setting.manage'],
        title: 'รายงานที่ออกแบบเอง',
        parent: 'รายงาน',
      }
    },
    {
      path: '/reports/custom/designer',
      name: 'report-designer',
      component: () => import('../views/admin/ReportDesignerView.vue'),
      meta: {
        requiresAuth: true,
        permissions: ['document.report.manage', 'system.setting.manage'],
        title: 'ออกแบบรายงาน',
        parent: 'รายงาน',
      }
    },
    // เส้นทางเดิมของหน้าออกแบบรายงาน — คงไว้เป็น redirect ไม่ให้ลิงก์/บุ๊กมาร์กเดิมเสีย
    {
      path: '/admin/document-center/reports',
      redirect: { name: 'report-designer' }
    },
    /*
     * งานสารบรรณ (INT-05)
     *
     * ไม่ผูก permission ที่ router ตามแนวทางเดิมของโครงการ — สารบัญเมนูคัดตามสิทธิ์
     * ให้แล้ว และทุก endpoint ตรวจสิทธิ์ซ้ำที่ฝั่ง server ทุกครั้ง
     * (หน้าเว็บไม่ใช่ด่านความปลอดภัย ตาม SoT §6)
     */
    {
      path: '/saraban/documents',
      name: 'saraban-documents',
      component: () => import('../views/saraban/DocumentsView.vue'),
      meta: {
        requiresAuth: true,
        title: 'ค้นหา / คลังเอกสาร',
        parent: 'งานสารบรรณ',
        saraban: {
          title: 'ค้นหา / คลังเอกสาร',
          description: 'หนังสือรับ หนังสือส่ง และหนังสือภายในที่อยู่ในขอบเขตของท่าน',
        },
      }
    },
    /*
     * ทะเบียนแยกตามทิศทาง (NAV §2)
     *
     * ต้องอยู่ **ก่อน** `/saraban/documents/:id` ด้วยเหตุผลเดียวกับคิวงาน
     * มิฉะนั้น `outgoing` จะถูกจับเป็นรหัสหนังสือ · ใช้ component เดียวกับ
     * ทะเบียนรวม ต่างกันเฉพาะค่าตั้งต้นใน `meta.saraban`
     */
    {
      path: '/saraban/documents/outgoing',
      name: 'saraban-documents-outgoing',
      component: () => import('../views/saraban/DocumentsView.vue'),
      meta: {
        requiresAuth: true,
        title: 'ทะเบียนหนังสือส่ง',
        parent: 'งานสารบรรณ',
        saraban: {
          direction: 'outgoing',
          title: 'ทะเบียนหนังสือส่ง',
          description: 'หนังสือที่ออกจากหน่วยงานของท่าน',
        },
      }
    },
    {
      path: '/saraban/documents/internal',
      name: 'saraban-documents-internal',
      component: () => import('../views/saraban/DocumentsView.vue'),
      meta: {
        requiresAuth: true,
        title: 'ทะเบียนหนังสือภายใน',
        parent: 'งานสารบรรณ',
        saraban: {
          direction: 'internal',
          title: 'ทะเบียนหนังสือภายใน',
          description: 'หนังสือที่เวียนภายในหน่วยงาน',
        },
      }
    },
    /*
     * คิวงานของผู้ใช้ (NAV §2 · §4.7)
     *
     * ต้องอยู่ **ก่อน** `/saraban/documents/:id` มิฉะนั้น `queue` จะถูกจับเป็นรหัสหนังสือ
     * — ข้อผิดพลาดเดียวกับที่ต้องระวังฝั่ง route ของ API
     */
    {
      path: '/saraban/documents/queue',
      name: 'saraban-work-queue',
      component: () => import('../views/saraban/WorkQueueView.vue'),
      meta: { requiresAuth: true, title: 'รอพิจารณา / รอลงนาม', parent: 'งานสารบรรณ' }
    },
    {
      path: '/saraban',
      name: 'saraban-dashboard',
      component: () => import('../views/saraban/SarabanDashboardView.vue'),
      meta: { requiresAuth: true, title: 'แดชบอร์ด', parent: 'งานสารบรรณ' }
    },
    {
      path: '/saraban/numbers',
      name: 'saraban-numbers',
      component: () => import('../views/saraban/NumberReservationView.vue'),
      meta: { requiresAuth: true, title: 'จอง/ออกเลข', parent: 'งานสารบรรณ' }
    },
    {
      path: '/saraban/documents/new',
      name: 'saraban-document-create',
      component: () => import('../views/saraban/DocumentFormView.vue'),
      meta: { requiresAuth: true, title: 'สร้างหนังสือ', parent: 'งานสารบรรณ' }
    },
    {
      path: '/saraban/documents/:id',
      name: 'saraban-document-detail',
      component: () => import('../views/saraban/DocumentFormView.vue'),
      meta: { requiresAuth: true, title: 'รายละเอียดหนังสือ', parent: 'งานสารบรรณ' }
    },
    /*
     * หน้าลงนาม (NAV §4.7 · ปิด RISK-30)
     *
     * เป็นหน้าเต็มไม่ใช่ modal โดยเจตนา — ผู้ลงนามต้องเห็นเอกสารทั้งหน้าและ
     * ลากวางลายเซ็นบนตำแหน่งจริง ซึ่งทำใน modal ไม่ได้
     */
    {
      path: '/saraban/documents/:id/sign',
      name: 'saraban-document-sign',
      component: () => import('../views/saraban/SignDocumentView.vue'),
      meta: { requiresAuth: true, title: 'ลงนามในหนังสือ', parent: 'งานสารบรรณ' }
    },
    /*
     * หน้าเกษียน — ใช้พื้นที่ทำงานเดียวกับหน้าลงนาม
     *
     * เดิมเกษียนด้วยการกรอกเลขหน้าและพิกัดเป็นตัวเลขโดยไม่เห็นเอกสาร
     * ซึ่งไม่มีทางวางตรงที่ตั้งใจได้
     */
    {
      path: '/saraban/documents/:id/endorse',
      name: 'saraban-document-endorse',
      component: () => import('../views/saraban/EndorseDocumentView.vue'),
      meta: { requiresAuth: true, title: 'เกษียนหนังสือ', parent: 'งานสารบรรณ' }
    },
    {
      path: '/saraban/documents/:id/workspace',
      name: 'saraban-document-workspace',
      component: () => import('../views/saraban/DocumentWorkspaceView.vue'),
      meta: { requiresAuth: true, title: 'ประทับตราหนังสือ', parent: 'งานสารบรรณ' }
    },
    /*
     * จัดการสายการเดินหนังสือ (RISK-26 · NAV §5.2)
     *
     * แยกจาก `/admin/workflow` ของโมดูลการลาโดยเจตนา — คนละสายงาน คนละผู้ดูแล
     * และ endpoint ฝั่ง server ตรวจซ้ำว่าเวอร์ชันเป็นของสารบรรณจริง
     */
    {
      path: '/saraban/workflow',
      name: 'saraban-workflow-design',
      component: () => import('../views/saraban/WorkflowDesignView.vue'),
      meta: { requiresAuth: true, title: 'สายการเดินหนังสือ', parent: 'งานสารบรรณ' }
    },
    {
      path: '/saraban/workflow/simulate',
      name: 'saraban-workflow-simulate',
      component: () => import('../views/saraban/WorkflowSimulationView.vue'),
      meta: { requiresAuth: true, title: 'จำลองเส้นทางเดินหนังสือ', parent: 'งานสารบรรณ' }
    },
    {
      // INT-20 — ทะเบียนหลักฐานการปฏิบัติตามข้อกำหนด (SoT §23)
      path: '/saraban/compliance',
      name: 'saraban-compliance',
      component: () => import('../views/saraban/ComplianceEvidenceView.vue'),
      meta: { requiresAuth: true, title: 'ทะเบียนหลักฐานการปฏิบัติตามข้อกำหนด', parent: 'งานสารบรรณ' }
    },
    {
      // INT-19 — รายงานทะเบียน (SoT §28)
      path: '/saraban/reports',
      name: 'saraban-reports',
      component: () => import('../views/saraban/RegisterReportsView.vue'),
      meta: { requiresAuth: true, title: 'รายงาน', parent: 'งานสารบรรณ' }
    },
    {
      // INT-18 — สารบบเรื่อง (SoT §22)
      path: '/saraban/cases',
      name: 'saraban-cases',
      component: () => import('../views/saraban/CasesView.vue'),
      meta: { requiresAuth: true, title: 'สารบบเรื่อง', parent: 'งานสารบรรณ' }
    },
    {
      // INT-17 — ศูนย์เฝ้าระวังการปฏิบัติการ (SoT §19, §20, §21)
      path: '/saraban/operations',
      name: 'saraban-command-center',
      component: () => import('../views/saraban/CommandCenterView.vue'),
      meta: { requiresAuth: true, title: 'ศูนย์เฝ้าระวังการปฏิบัติการ', parent: 'งานสารบรรณ' }
    },
    {
      // INT-16 — ศูนย์นโยบายและตารางอำนาจ (SoT §6, §18)
      path: '/saraban/policies',
      name: 'saraban-policies',
      component: () => import('../views/saraban/PolicyCenterView.vue'),
      meta: { requiresAuth: true, title: 'ศูนย์นโยบายงานสารบรรณ', parent: 'งานสารบรรณ' }
    },
    {
      path: '/saraban/authority',
      name: 'saraban-authority',
      component: () => import('../views/saraban/AuthorityMatrixView.vue'),
      meta: { requiresAuth: true, title: 'ตารางอำนาจหน้าที่', parent: 'งานสารบรรณ' }
    },
    {
      path: '/saraban/number-series',
      name: 'saraban-number-series',
      component: () => import('../views/saraban/NumberSeriesView.vue'),
      meta: { requiresAuth: true, title: 'ชุดเลขหนังสือ', parent: 'งานสารบรรณ' }
    },
    {
      path: '/saraban/inbox',
      name: 'saraban-inbox',
      component: () => import('../views/saraban/InboxView.vue'),
      meta: { requiresAuth: true, title: 'กล่องหนังสือของฉัน', parent: 'งานสารบรรณ' }
    },
    {
      path: '/saraban/incoming',
      name: 'saraban-incoming',
      component: () => import('../views/saraban/IncomingView.vue'),
      meta: { requiresAuth: true, title: 'ทะเบียนหนังสือรับ', parent: 'งานสารบรรณ' }
    },
    {
      path: '/saraban/incoming/new',
      name: 'saraban-incoming-register',
      component: () => import('../views/saraban/IncomingRegisterView.vue'),
      meta: { requiresAuth: true, title: 'ลงทะเบียนรับ', parent: 'งานสารบรรณ' }
    },
    /*
     * กล่องรอลงทะเบียน (NAV §2 · §4.2) — หนังสือรับที่ยังไม่มีเลขรับ
     *
     * ใช้ทะเบียนหนังสือเป็นหน้าจอ เพราะเป็นรายการหนังสือชุดเดียวกัน
     * ต่างกันแค่เงื่อนไข ไม่ใช่คนละเรื่อง
     */
    {
      path: '/saraban/incoming/pending',
      name: 'saraban-incoming-pending',
      component: () => import('../views/saraban/DocumentsView.vue'),
      meta: {
        requiresAuth: true,
        title: 'กล่องรอลงทะเบียน',
        parent: 'งานสารบรรณ',
        saraban: {
          pendingRegistration: true,
          title: 'กล่องรอลงทะเบียน',
          description: 'หนังสือรับที่เข้ามาแล้วแต่ยังไม่ได้ออกเลขรับ',
        },
      }
    },
    {
      // NAV §2 · §4.8 — ติดตามการกระจายเอกสารข้ามฉบับ
      path: '/saraban/distributions',
      name: 'saraban-distributions',
      component: () => import('../views/saraban/DistributionTrackingView.vue'),
      meta: { requiresAuth: true, title: 'กระจายเอกสาร', parent: 'งานสารบรรณ' }
    },
    {
      path: '/leave-card',
      name: 'leave-card',
      component: () => import('../views/LeaveCardView.vue'),
      meta: { requiresAuth: true, title: 'บัตรลา', parent: 'ระบบลางาน' }
    },
    {
      /*
       * CLEANUP-01 (D10) — "บริการและเอกสารใบลา" ถูกยุบเข้า "รายการใบลาของฉัน"
       *
       * ทั้งสองหน้าคือรายการใบลาของผู้ใช้คนเดียวกัน ต่างกันแค่ปุ่มบริการ
       * ผู้ใช้ทั่วไปจึงเห็นสองเมนูที่ดูเหมือนกัน ตอนนี้หลักฐาน ประวัติการดำเนินการ
       * และการขอยกเลิกบางช่วง อยู่ในแถบขยายของแต่ละใบลาที่ /leaves
       */
      path: '/leave-services',
      redirect: { name: 'my-leaves' }
    },
    {
      path: '/team-calendar',
      name: 'team-calendar',
      component: () => import('../views/TeamCalendarView.vue'),
      meta: { requiresAuth: true, title: 'ปฏิทินวันลาทีม', parent: 'ระบบลางาน' }
    },
    {
      path: '/approvals',
      name: 'approvals',
      component: ApprovalsView,
      props: { isCancel: false },
      meta: { requiresAuth: true, title: 'อนุมัติใบลา', parent: 'ระบบลางาน' }
    },
    {
      path: '/cancel-approvals',
      name: 'cancel-approvals',
      component: ApprovalsView,
      props: { isCancel: true },
      meta: { requiresAuth: true, title: 'อนุมัติยกเลิกใบลา', parent: 'ระบบลางาน' }
    },
    {
      path: '/admin/leaves-management',
      name: 'admin-leaves-management',
      component: () => import('../views/admin/LeaveManagementView.vue'),
      meta: { requiresAuth: true, permissions: ['leave.approval.dept', 'leave.management.manage'], title: 'จัดการ/ซิงค์วันลา (HR)', parent: 'ระบบลางาน' }
    },
    {
      path: '/admin/promotion-screening',
      name: 'admin-promotion-screening',
      component: () => import('../views/admin/PromotionScreeningView.vue'),
      meta: { requiresAuth: true, permissions: ['leave.report.view', 'leave.management.manage'], title: 'คัดกรองเลื่อนเงินเดือน', parent: 'ระบบลางาน' }
    },
    {
      path: '/admin/leave-governance',
      name: 'admin-leave-governance',
      component: () => import('../views/admin/LeaveGovernanceView.vue'),
      meta: { requiresAuth: true, permissions: ['leave.approval.hr', 'leave.management.manage'], title: 'ศูนย์งานกำกับการลา', parent: 'ระบบลางาน' }
    },

    // === โปรไฟล์ผู้ใช้ ===
    {
      path: '/profile',
      name: 'profile',
      component: () => import('../views/UserProfileView.vue'),
      meta: { requiresAuth: true, title: 'โปรไฟล์ของฉัน' }
    },
    {
      path: '/profile/access-history',
      name: 'my-access-history',
      component: () => import('../views/MyAccessHistoryView.vue'),
      meta: { requiresAuth: true, title: 'ประวัติการเข้าถึงข้อมูลส่วนบุคคล', parent: 'โปรไฟล์ของฉัน' }
    },

    // === ระบบลงเวลาออนไลน์ ===
    {
      path: '/attendance/check-in',
      name: 'attendance-check-in',
      component: () => import('../views/Attendance/CheckInView.vue'),
      meta: { requiresAuth: true, title: 'ลงเวลาปฏิบัติงาน', parent: 'ระบบลงเวลาออนไลน์' }
    },
    {
      path: '/attendance/history',
      name: 'attendance-history',
      component: () => import('../views/Attendance/MyHistoryView.vue'),
      meta: { requiresAuth: true, title: 'ประวัติการลงเวลา', parent: 'ระบบลงเวลาออนไลน์' }
    },
    {
      path: '/admin/attendances',
      name: 'admin-attendances',
      component: () => import('../views/admin/AttendanceReportView.vue'),
      meta: { requiresAuth: true, permission: 'attendance.view_all', title: 'รายงานการลงเวลา', parent: 'ระบบลงเวลาออนไลน์' }
    },
    {
      path: '/admin/attendances/summary',
      name: 'admin-attendances-summary',
      component: () => import('../views/admin/AttendanceSummaryView.vue'),
      meta: { requiresAuth: true, permission: 'attendance.view_all', title: 'สรุปผลการปฏิบัติงาน', parent: 'ระบบลงเวลาออนไลน์' }
    },
    {
      path: '/admin/ot-summary',
      name: 'admin-ot-summary',
      component: () => import('../views/admin/AttendanceOTSummaryView.vue'),
      meta: { requiresAuth: true, permissions: ['attendance.ot', 'attendance.ot_summary'], title: 'สรุปผลการปฏิบัติงาน (OT)', parent: 'ระบบลงเวลาออนไลน์' }
    },
    {
      path: '/admin/overtime-orders',
      name: 'admin-overtime-orders',
      component: () => import('../views/admin/OvertimeOrdersView.vue'),
      meta: { requiresAuth: true, permissions: ['overtime.operation.execute', 'overtime.management.review'], title: 'คำสั่งปฏิบัติงานล่วงเวลา (OT)', parent: 'ระบบลงเวลาออนไลน์' }
    },

    // === จัดการผู้ใช้งาน ===
    {
      path: '/admin/users',
      name: 'admin-users',
      component: () => import('../views/admin/UsersManagementView.vue'),
      meta: { requiresAuth: true, permission: 'system.user.view', title: 'รายชื่อผู้ใช้งาน', parent: 'จัดการผู้ใช้งาน' }
    },
    {
      path: '/admin/personnel-assignments',
      name: 'admin-personnel-assignments',
      component: () => import('../views/admin/PersonnelAssignmentsView.vue'),
      meta: { requiresAuth: true, permission: 'system.user.manage', title: 'กำหนดประเภทบุคลากร', parent: 'จัดการผู้ใช้งาน' }
    },
    {
      path: '/admin/menus',
      name: 'admin-menus',
      component: () => import('../views/admin/MenusManagementView.vue'),
      meta: { requiresAuth: true, permission: 'system.menu.manage', title: 'จัดการเมนูตามสิทธิ์', parent: 'จัดการผู้ใช้งาน' }
    },
    {
      path: '/admin/roles',
      name: 'admin-roles',
      component: () => import('../views/admin/RolesManagementView.vue'),
      meta: { requiresAuth: true, permissions: ['system.menu.manage', 'system.role.manage'], title: 'จัดการบทบาทและสิทธิ์', parent: 'จัดการผู้ใช้งาน' }
    },

    // === ตั้งค่าระบบ ===
    {
      path: '/admin/settings',
      name: 'admin-settings',
      component: () => import('../views/admin/AdminSettingsView.vue'),
      meta: { requiresAuth: true, permission: 'system.setting.manage', title: 'ข้อมูลสถานศึกษาและแบบฟอร์ม', parent: 'ตั้งค่าระบบ' }
    },
    {
      // เมนู "ตั้งค่าแบบฟอร์ม & ส่งออก" ถูกยุบเข้าเป็นแท็บของ /admin/settings แล้ว
      // คง path เดิมไว้เป็น redirect เพื่อไม่ให้ลิงก์หรือบุ๊กมาร์กที่บันทึกไว้เสีย
      path: '/admin/export-settings',
      redirect: { name: 'admin-settings', query: { tab: 'export' } }
    },
    {
      path: '/admin/attendance-settings',
      name: 'admin-attendance-settings',
      component: () => import('../views/admin/AttendanceSettingsView.vue'),
      meta: { requiresAuth: true, permission: 'system.setting.manage', title: 'ตั้งค่าระบบลงเวลา', parent: 'ตั้งค่าระบบ' }
    },
    {
      path: '/admin/work-calendar',
      name: 'admin-work-calendar',
      component: () => import('../views/admin/WorkCalendarView.vue'),
      meta: { requiresAuth: true, permission: 'system.setting.manage', title: 'ปฏิทินวันทำการ', parent: 'ตั้งค่าระบบ' }
    },
    {
      path: '/admin/departments',
      name: 'admin-departments',
      component: () => import('../views/admin/DepartmentsManagementView.vue'),
      meta: { requiresAuth: true, permission: 'manage_departments', title: 'จัดการแผนกวิชา', parent: 'ตั้งค่าระบบ' }
    },
    /*
     * ผู้รับผิดชอบหน่วยงาน — ข้อมูลที่ตัวหาผู้อนุมัติใช้จริง
     * แทนช่อง "หัวหน้าแผนก" เดิมในหน้าจัดการแผนกวิชา ซึ่งตั้งค่าได้แต่ไม่มีใครอ่าน
     */
    {
      path: '/admin/org-unit-managers',
      name: 'admin-org-unit-managers',
      component: () => import('../views/admin/OrgUnitManagersView.vue'),
      meta: { requiresAuth: true, permission: 'manage_departments', title: 'ผู้รับผิดชอบหน่วยงาน', parent: 'ตั้งค่าระบบ' }
    },
    {
      path: '/admin/workflow',
      name: 'admin-workflow',
      component: () => import('../views/admin/WorkflowManagementView.vue'),
      meta: { requiresAuth: true, permission: 'manage_workflow_steps', title: 'จัดการลำดับการอนุมัติ', parent: 'ตั้งค่าระบบ' }
    },
    {
      path: '/admin/leave-policies',
      name: 'admin-leave-policies',
      component: () => import('../views/admin/LeavePoliciesView.vue'),
      /*
       * สิทธิ์ของหน้าต้องตรงกับสิทธิ์ที่ API ต้องการจริง
       *
       * เดิมเปิดหน้าได้ด้วย `system.setting.manage` ด้วย แต่ทุก endpoint ของนโยบายสิทธิ
       * ต้องการ `leave.policy.manage` เท่านั้น ผู้ที่มีเพียงสิทธิ์ตั้งค่าระบบจึงเข้าหน้าได้
       * แล้วเจอ 403 ทุกครั้งที่โหลดข้อมูล
       */
      meta: { requiresAuth: true, permission: 'leave.policy.manage', title: 'นโยบายสิทธิการลา', parent: 'ตั้งค่าระบบ' }
    },
    {
      path: '/admin/leave-types',
      name: 'admin-leave-types',
      component: () => import('../views/admin/LeaveTypesManagementView.vue'),
      meta: { requiresAuth: true, permission: 'manage_leave_types', title: 'ประเภทการลา', parent: 'ตั้งค่าระบบ' }
    },
    {
      path: '/admin/fiscal-years',
      name: 'admin-fiscal-years',
      component: () => import('../views/admin/FiscalYearManagementView.vue'),
      meta: { requiresAuth: true, permission: 'system.setting.manage', title: 'ปีงบประมาณ', parent: 'ตั้งค่าระบบ' }
    },
    {
      path: '/admin/notifications',
      name: 'admin-notifications',
      component: () => import('../views/admin/TelegramSettingsView.vue'),
      meta: { requiresAuth: true, permission: 'system.setting.manage', title: 'การแจ้งเตือน', parent: 'ตั้งค่าระบบ' }
    },
    {
      /*
       * CLEANUP-01 (D2) — หน้า "จัดการแม่แบบเอกสาร" ถูกยุบเข้าศูนย์จัดการเอกสาร
       *
       * ทั้งสองหน้าอ่านเขียนตาราง `document_templates` ชุดเดียวกันแต่เป็นคนละเมนู
       * ผู้ดูแลจึงต้องเดาว่าจะแก้แม่แบบที่หน้าไหน คงเส้นทางเดิมไว้เป็น redirect
       * เพื่อไม่ให้ลิงก์หรือบุ๊กมาร์กที่บันทึกไว้เสีย
       */
      path: '/admin/document-templates',
      redirect: { name: 'document-center' }
    },
    // === ศูนย์จัดการเอกสาร (แบบฟอร์มและเอกสารที่ระบบออก) ===
    {
      path: '/admin/document-center',
      name: 'document-center',
      component: () => import('../views/admin/DocumentCenterView.vue'),
      meta: {
        requiresAuth: true,
        permissions: ['document.template.view', 'document.template.manage', 'system.setting.manage'],
        title: 'ศูนย์จัดการเอกสาร',
        parent: 'ตั้งค่าระบบ',
      }
    },
    {
      path: '/admin/document-center/templates/:templateId',
      name: 'document-designer',
      component: () => import('../views/admin/DocumentDesignerView.vue'),
      meta: {
        requiresAuth: true,
        permissions: ['document.template.manage', 'system.setting.manage'],
        title: 'ออกแบบแบบฟอร์มเอกสาร',
        parent: 'ตั้งค่าระบบ',
      }
    },
    {
      path: '/admin/modules',
      name: 'admin-modules',
      component: () => import('../views/admin/AdminModulesView.vue'),
      meta: { requiresAuth: true, permission: 'system.setting.manage', title: 'โมดูลระบบ', parent: 'ตั้งค่าระบบ' }
    },
    {
      // INT-15 — ปลายทาง webhook ขาออก (SoT §17)
      path: '/admin/integrations',
      name: 'admin-integrations',
      component: () => import('../views/admin/IntegrationsView.vue'),
      meta: { requiresAuth: true, permission: 'system.setting.manage', title: 'การเชื่อมต่อระบบภายนอก', parent: 'ตั้งค่าระบบ' }
    },

    /*
     * CLEANUP-01 (D1) — เส้นทางเดิมที่เคยถูกลงทะเบียนซ้ำใน `main.js`
     *
     * `main.js` เคยเรียก `router.addRoute()` อีก 8 เส้นทางโดยใช้ "ชื่อ route เดียวกัน"
     * กับที่ประกาศไว้ในไฟล์นี้ ผลคือเส้นทางในไฟล์นี้ถูกแทนที่เงียบ ๆ พร้อมกับ meta
     * ที่ต่างกัน — เงื่อนไขสิทธิ์ ชื่อหน้า และแม้แต่ path ก็ไม่ตรงกับที่เขียนไว้ที่นี่
     *
     * ตอนนี้ทุกเส้นทางอยู่ในไฟล์นี้ไฟล์เดียว ส่วน path เดิมที่เคยเข้าถึงได้จริง
     * คงไว้เป็น redirect เพื่อไม่ให้บุ๊กมาร์กเสีย
     */
    { path: '/my-access-history', redirect: { name: 'my-access-history' } },
    { path: '/admin/personnel-types', redirect: { name: 'admin-personnel-assignments' } },
    { path: '/admin/leave-calendar', redirect: { name: 'admin-work-calendar' } },
    {
      path: '/forbidden',
      name: 'forbidden',
      component: () => import('../views/ForbiddenView.vue'),
      meta: { requiresAuth: true, title: 'ไม่มีสิทธิ์เข้าถึง (403)' }
    },
    // Catch-all 404 page
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('../views/NotFoundView.vue'),
      meta: { title: 'ไม่พบหน้า (404)' }
    }
  ]
})

router.beforeEach(async (to) => {
  const authStore = useAuthStore()

  if (authStore.isAuthenticated && !authStore.user) {
    try {
      await authStore.fetchUser()
    } catch (e) {
      console.error(e)
    }
  }

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    return { name: 'login' }
  } else if (to.meta.requiresGuest && authStore.isAuthenticated) {
    return { name: 'dashboard' }
  }

  // Permission checks
  if (!authStore.isAdmin) {
    if (to.meta.permission && !authStore.hasPermission(to.meta.permission)) {
      return { name: 'forbidden' }
    }
    if (to.meta.permissions && !authStore.hasAnyPermission(to.meta.permissions)) {
      return { name: 'forbidden' }
    }
  }
})

export default router

