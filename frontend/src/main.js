import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'
import canDirective from './directives/can'
import secureSrcDirective from './directives/secureSrc'

router.addRoute({ path: '/leave-services', name: 'leave-services', component: () => import('./views/LeaveServicesView.vue'), meta: { requiresAuth: true } })
// หน้าตรวจสอบใบลาจาก QR — เปิดสาธารณะ ไม่ต้องล็อกอิน
router.addRoute({ path: '/verify-leave/:hash', name: 'verify-leave', component: () => import('./views/VerifyLeaveView.vue'), meta: { public: true } })
router.addRoute({ path: '/my-access-history', name: 'my-access-history', component: () => import('./views/MyAccessHistoryView.vue'), meta: { requiresAuth: true } })
router.addRoute({ path: '/leave-card', name: 'leave-card', component: () => import('./views/LeaveCardView.vue'), meta: { requiresAuth: true } })
router.addRoute({ path: '/admin/promotion-screening', name: 'admin-promotion-screening', component: () => import('./views/admin/PromotionScreeningView.vue'), meta: { requiresAuth: true, permission: 'leave.report.view' } })
// สิทธิ์ต้องตรงกับ backend (LeaveGovernanceWorklistController): งานบุคลากรถือ leave.management.manage หรือ leave.approval.hr
// เดิมรับแค่ leave.approval.hr และทับค่าใน router/index.js หัวหน้างานบุคลากรจึงเห็นเมนูแต่เปิดหน้าไม่ได้
router.addRoute({ path: '/admin/leave-governance', name: 'admin-leave-governance', component: () => import('./views/admin/LeaveGovernanceView.vue'), meta: { requiresAuth: true, permissions: ['leave.management.manage', 'leave.approval.hr'], title: 'ศูนย์งานกำกับการลา', parent: 'ระบบลางาน' } })
router.addRoute({ path: '/admin/leave-policies', name: 'admin-leave-policies', component: () => import('./views/admin/LeavePoliciesView.vue'), meta: { requiresAuth: true, permission: 'leave.policy.manage' } })
router.addRoute({ path: '/admin/personnel-types', name: 'admin-personnel-types', component: () => import('./views/admin/PersonnelAssignmentsView.vue'), meta: { requiresAuth: true, permission: 'edit_users' } })
router.addRoute({ path: '/admin/leave-calendar', name: 'admin-leave-calendar', component: () => import('./views/admin/WorkCalendarView.vue'), meta: { requiresAuth: true, permission: 'leave.calendar.manage' } })

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)
app.directive('can', canDirective)
app.directive('secure-src', secureSrcDirective)

// Wait for the initial auth guard and lazy route component before replacing
// the startup screen. This prevents a partially rendered shell after F5.
await router.isReady()
app.mount('#app')
