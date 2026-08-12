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
      meta: { requiresGuest: true }
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: ForgotPasswordView,
      meta: { requiresGuest: true }
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: ResetPasswordView,
      meta: { requiresGuest: true }
    },
    {
      path: '/',
      name: 'dashboard',
      component: DashboardView,
      meta: { requiresAuth: true }
    },
    {
      path: '/request',
      name: 'request',
      component: LeaveRequestView,
      meta: { requiresAuth: true }
    },
    {
      path: '/leaves',
      name: 'my-leaves',
      component: () => import('../views/MyLeavesView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/reports',
      name: 'reports',
      component: () => import('../views/ReportsView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/team-calendar',
      name: 'team-calendar',
      component: () => import('../views/TeamCalendarView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/approvals',
      name: 'approvals',
      component: ApprovalsView,
      props: { isCancel: false },
      meta: { requiresAuth: true }
    },
    {
      path: '/cancel-approvals',
      name: 'cancel-approvals',
      component: ApprovalsView,
      props: { isCancel: true },
      meta: { requiresAuth: true }
    },
    {
      path: '/profile',
      name: 'profile',
      component: () => import('../views/UserProfileView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/attendance/check-in',
      name: 'attendance-check-in',
      component: () => import('../views/Attendance/CheckInView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/attendance/history',
      name: 'attendance-history',
      component: () => import('../views/Attendance/MyHistoryView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/admin/users',
      name: 'admin-users',
      component: () => import('../views/admin/UsersManagementView.vue'),
      meta: { requiresAuth: true, permission: 'view_users' }
    },
    {
      path: '/admin/menus',
      name: 'admin-menus',
      component: () => import('../views/admin/MenusManagementView.vue'),
      meta: { requiresAuth: true, permission: 'edit_users' }
    },
    {
      path: '/admin/roles',
      name: 'admin-roles',
      component: () => import('../views/admin/RolesManagementView.vue'),
      meta: { requiresAuth: true, permission: 'edit_users' }
    },
    {
      path: '/admin/attendances',
      name: 'admin-attendances',
      component: () => import('../views/admin/AttendanceReportView.vue'),
      meta: { requiresAuth: true, permission: 'view_all_attendances' }
    },
    {
      path: '/admin/attendances/summary',
      name: 'admin-attendances-summary',
      component: () => import('../views/admin/AttendanceSummaryView.vue'),
      meta: { requiresAuth: true, permission: 'view_all_attendances' }
    },
    {
      path: '/admin/ot-summary',
      name: 'admin-ot-summary',
      component: () => import('../views/admin/AttendanceOTSummaryView.vue'),
      meta: { requiresAuth: true, permission: 'view_all_attendances' }
    },
    {
      path: '/admin/leaves-management',
      name: 'admin-leaves-management',
      component: () => import('../views/admin/LeaveManagementView.vue'),
      meta: { requiresAuth: true, permission: 'approve_leave' }
    },
    {
      path: '/admin/departments',
      name: 'admin-departments',
      component: () => import('../views/admin/DepartmentsManagementView.vue'),
      meta: { requiresAuth: true, permission: 'manage_departments' }
    },
    {
      path: '/admin/workflow',
      name: 'admin-workflow',
      component: () => import('../views/admin/WorkflowManagementView.vue'),
      meta: { requiresAuth: true, permission: 'manage_workflow_steps' }
    },
    {
      path: '/admin/leave-types',
      name: 'admin-leave-types',
      component: () => import('../views/admin/LeaveTypesManagementView.vue'),
      meta: { requiresAuth: true, permission: 'manage_leave_types' }
    },
    {
      path: '/admin/fiscal-years',
      name: 'admin-fiscal-years',
      component: () => import('../views/admin/FiscalYearManagementView.vue'),
      meta: { requiresAuth: true, permission: 'manage_settings' }
    },
    {
      path: '/admin/export-settings',
      name: 'admin-export-settings',
      component: () => import('../views/admin/ExportSettingsView.vue'),
      meta: { requiresAuth: true, permission: 'manage_settings' }
    },
    {
      path: '/admin/document-templates',
      name: 'admin-document-templates',
      component: () => import('../views/admin/DocumentTemplatesView.vue'),
      meta: { requiresAuth: true, permission: 'manage_settings' }
    },
    {
      path: '/admin/settings',
      name: 'admin-settings',
      component: () => import('../views/admin/AdminSettingsView.vue'),
      meta: { requiresAuth: true, permission: 'manage_settings' }
    },
    {
      path: '/admin/notifications',
      name: 'admin-notifications',
      component: () => import('../views/admin/TelegramSettingsView.vue'),
      meta: { requiresAuth: true, permission: 'manage_settings' }
    },
    {
      path: '/admin/modules',
      name: 'admin-modules',
      component: () => import('../views/admin/AdminModulesView.vue'),
      meta: { requiresAuth: true, permission: 'manage_settings' }
    },
    {
      path: '/saraban',
      name: 'saraban-list',
      component: () => import('../views/saraban/DocumentListView.vue'),
      meta: { requiresAuth: true, permission: 'saraban.document.view' }
    },
    {
      path: '/saraban/create',
      name: 'saraban-create',
      component: () => import('../views/saraban/DocumentFormView.vue'),
      meta: { requiresAuth: true, permission: 'saraban.document.create' }
    },
    {
      path: '/saraban/incoming',
      name: 'saraban-incoming',
      component: () => import('../views/saraban/DocumentListView.vue'),
      meta: { requiresAuth: true, permission: 'saraban.document.view' }
    },
    {
      path: '/saraban/outgoing',
      name: 'saraban-outgoing',
      component: () => import('../views/saraban/DocumentListView.vue'),
      meta: { requiresAuth: true, permission: 'saraban.document.view' }
    },
    {
      path: '/saraban/circulars',
      name: 'saraban-circulars',
      component: () => import('../views/saraban/CircularsView.vue'),
      meta: { requiresAuth: true, permission: 'saraban.document.view' }
    },
    {
      path: '/saraban/reports',
      name: 'saraban-reports',
      component: () => import('../views/saraban/ReportsView.vue'),
      meta: { requiresAuth: true, permission: 'saraban.document.report' }
    },
    // ต้องอยู่ก่อน /saraban/:id ไม่งั้น "types" จะถูกตีความเป็น :id
    {
      path: '/saraban/types',
      name: 'saraban-types',
      component: () => import('../views/saraban/DocumentTypeListView.vue'),
      meta: { requiresAuth: true, permission: 'saraban.document.manage_types' }
    },
    {
      path: '/saraban/settings',
      name: 'saraban-settings',
      component: () => import('../views/admin/SarabanSettingsView.vue'),
      meta: { requiresAuth: true, permission: 'manage_settings' }
    },
    {
      path: '/saraban/workflow-templates',
      name: 'saraban-workflow-templates',
      component: () => import('../views/admin/WorkflowTemplatesView.vue'),
      meta: { requiresAuth: true, permission: 'manage_workflow_steps' }
    },
    {
      path: '/saraban/:id',
      name: 'saraban-detail',
      component: () => import('../views/saraban/DocumentDetailView.vue'),
      meta: { requiresAuth: true, permission: 'saraban.document.view' }
    },
    {
      path: '/saraban/:id/edit',
      name: 'saraban-edit',
      component: () => import('../views/saraban/DocumentFormView.vue'),
      meta: { requiresAuth: true, permission: 'saraban.document.edit' }
    }
  ]
})

router.beforeEach(async (to, from) => {
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
  } else if (to.meta.permission && !authStore.isAdmin && !authStore.hasPermission(to.meta.permission)) {
    // ไม่มีทางลัดตาม role อีกต่อไป — สิทธิ์ saraban.* มาจาก SarabanPermissionSeeder
    // ซึ่ง grant ให้ role เจ้าหน้าที่สารบัญไว้ครบแล้ว การ bypass ตาม role
    // ทำให้การ gate รายสิทธิ์ไร้ผล
    return { name: 'dashboard' }
  }
})

export default router

