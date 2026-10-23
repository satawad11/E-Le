import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { usePermissionStore } from '../stores/permissions'
import LoginView from '../views/LoginView.vue'
import legacyRoutes from './legacy'

/**
 * route ของระบบใหม่ (R-05/R-06) — `meta.permission` (อย่างใดอย่างหนึ่ง) ตรงกับสิทธิ์ของเมนูใน manifest
 * `meta.scopes` = scope ที่ต้องมีอย่างน้อยหนึ่งแบบ (เช่น รายชื่อบุคลากรต้องเห็นมากกว่าตนเอง)
 * เข้า URL ตรงที่ไม่มีสิทธิ์ → หน้า 403 · การตรวจจริงอยู่ที่ server ทุก endpoint
 */
const routes = [
  { path: '/login', name: 'login', component: LoginView, meta: { requiresGuest: true, title: 'เข้าสู่ระบบ' } },
  { path: '/forgot-password', name: 'forgot-password', component: () => import('../views/ForgotPasswordView.vue'), meta: { requiresGuest: true, title: 'ลืมรหัสผ่าน' } },
  { path: '/reset-password', name: 'reset-password', component: () => import('../views/ResetPasswordView.vue'), meta: { requiresGuest: true, title: 'ตั้งรหัสผ่านใหม่' } },
  { path: '/verify-leave', name: 'verify-leave', component: () => import('../views/VerifyLeaveView.vue'), meta: { bare: true, title: 'ตรวจสอบใบลา' } },
  { path: '/verify-leave/:hash', name: 'verify-leave-hash', component: () => import('../views/VerifyLeaveView.vue'), meta: { public: true, title: 'ตรวจสอบใบลา' } },

  // ก. ส่วนบุคคล
  { path: '/', name: 'dashboard', component: () => import('../views/core/HomeView.vue'), meta: { requiresAuth: true, title: 'หน้าหลัก' } },
  { path: '/me', name: 'me', component: () => import('../views/personnel/PersonnelProfileView.vue'), props: { self: true }, meta: { requiresAuth: true, permission: ['personnel.profile.view'], title: 'ข้อมูลของฉัน' } },
  { path: '/me/access-history', name: 'me-access-history', component: () => import('../views/core/MyAccessHistoryView.vue'), meta: { requiresAuth: true, permission: ['personnel.profile.view'], title: 'ประวัติการเข้าถึงข้อมูลของฉัน', parentRoute: '/me' } },

  // ข. ข้อมูลบุคลากร
  { path: '/personnel', name: 'personnel', component: () => import('../views/personnel/PersonnelListView.vue'), meta: { requiresAuth: true, permission: ['personnel.profile.view'], scopes: ['unit', 'subtree', 'all', 'assigned'], title: 'รายชื่อบุคลากร' } },
  { path: '/personnel/change-requests', name: 'personnel-change-requests', component: () => import('../views/personnel/ChangeRequestsView.vue'), meta: { requiresAuth: true, permission: ['personnel.change_request.review'], title: 'คำขอแก้ไขรอตรวจ' } },
  { path: '/personnel/reports', name: 'personnel-reports', component: () => import('../views/personnel/PersonnelReportsView.vue'), meta: { requiresAuth: true, permission: ['personnel.report.view'], title: 'รายงานบุคลากร' } },
  { path: '/personnel/:uuid', name: 'personnel-show', component: () => import('../views/personnel/PersonnelProfileView.vue'), props: true, meta: { requiresAuth: true, permission: ['personnel.profile.view'], scopes: ['unit', 'subtree', 'all', 'assigned'], title: 'ประวัติบุคลากร', parentRoute: '/personnel' } },
  { path: '/org', name: 'org', component: () => import('../views/org/OrgStructureView.vue'), meta: { requiresAuth: true, permission: ['org.unit.view', 'org.unit.manage'], title: 'โครงสร้างองค์กร' } },
  { path: '/org/positions', name: 'org-positions', component: () => import('../views/org/PositionsView.vue'), meta: { requiresAuth: true, permission: ['org.position.manage'], title: 'ตำแหน่ง' } },
  { path: '/org/assignments', name: 'org-assignments', component: () => import('../views/org/AssignmentsView.vue'), meta: { requiresAuth: true, permission: ['personnel.assignment.manage'], title: 'คำสั่งแต่งตั้งและมอบหมาย' } },

  // ช. บัญชีและสิทธิ์ (R-05)
  { path: '/access/accounts', name: 'access-accounts', component: () => import('../views/access/AccountsView.vue'), meta: { requiresAuth: true, permission: ['access.account.view'], title: 'บัญชีผู้ใช้' } },

  // route เดิม → route ใหม่ (PLAN §8.4)
  { path: '/admin/users', redirect: '/access/accounts' },
  { path: '/admin/org-structure', redirect: '/org' },
  { path: '/admin/departments', redirect: '/org' },
  { path: '/admin/positions', redirect: '/org/positions' },
  { path: '/admin/personnel-assignments', redirect: '/org/assignments' },
  { path: '/profile', redirect: '/me' },
  { path: '/profile/access-history', redirect: '/me/access-history' },
  { path: '/my-access-history', redirect: '/me/access-history' },

  { path: '/forbidden', name: 'forbidden', component: () => import('../views/ForbiddenView.vue'), meta: { requiresAuth: true, title: 'ไม่มีสิทธิ์เข้าถึง' } },
  { path: '/not-migrated', name: 'not-migrated', component: () => import('../views/core/NotMigratedView.vue'), meta: { requiresAuth: true, title: 'ยังไม่เปิดใช้งาน' } },
]

const taken = new Set(routes.map((r) => r.path))
const legacy = legacyRoutes
  .filter((r) => !taken.has(r.path))
  .map((r) => ({ ...r, meta: { ...(r.meta || {}), legacy: true, requiresAuth: true } }))

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    ...routes,
    ...legacy,
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('../views/NotFoundView.vue'), meta: { title: 'ไม่พบหน้า' } },
  ],
})

router.beforeEach(async (to) => {
  const authStore = useAuthStore()

  if (authStore.isAuthenticated && !authStore.user) {
    try {
      await authStore.fetchUser()
    } catch (e) {
      // fetchUser ออกจากระบบเองเมื่อ token ใช้ไม่ได้
    }
  }

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    return { name: 'login' }
  }
  if (to.meta.requiresGuest && authStore.isAuthenticated) {
    return { name: 'dashboard' }
  }
  if (to.meta.legacy) {
    return { name: 'not-migrated', query: { from: to.fullPath } }
  }

  const required = to.meta.permission
  if (required && authStore.isAuthenticated) {
    const perms = usePermissionStore()
    try {
      await perms.load()
    } catch (e) {
      return { name: 'forbidden', query: { from: to.fullPath } }
    }
    const scopes = to.meta.scopes
    const ok = required.some((name) => (scopes ? perms.scopesOf(name).some((s) => scopes.includes(s)) : perms.can(name)))
    if (!ok) {
      return { name: 'forbidden', query: { from: to.fullPath } }
    }
  }
})

router.afterEach((to) => {
  document.title = [to.meta.title, 'ระบบบุคลากร DMTC'].filter(Boolean).join(' · ')
})

export default router
