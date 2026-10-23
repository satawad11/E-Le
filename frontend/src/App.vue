<template>
  <AlertList />
  <router-view v-if="isBareLayout" />
  <AppShell v-else>
    <router-view />
  </AppShell>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AlertList from './components/AlertList.vue'
import AppShell from './layouts/AppShell.vue'
import { useAuthStore } from './stores/auth'
import './styles/dmtc.css'

/**
 * โครงหน้า (R-06): หน้าเข้าสู่ระบบ/กู้รหัสผ่าน/ตรวจสอบเอกสารสาธารณะไม่มีเมนู
 * หน้าอื่นใช้ AppShell ตามต้นแบบ "ระบบบุคลากร DMTC" — เมนูมาจาก /api/me/menu เท่านั้น (แถบเมนูของระบบเดิมถูกถอดแล้ว)
 */
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const isBareLayout = computed(() => Boolean(route.meta?.requiresGuest || route.meta?.bare || route.meta?.public)
  || ['login', 'forgot-password', 'reset-password', 'verify-leave'].includes(route.name))

// ออกจากระบบอัตโนมัติเมื่อไม่ใช้งาน 30 นาที (คงพฤติกรรมเดิม)
const IDLE_TIMEOUT_MS = 30 * 60 * 1000
let idleTimer = null
const resetIdleTimer = () => {
  if (isBareLayout.value || !authStore.isAuthenticated) return
  if (idleTimer) clearTimeout(idleTimer)
  idleTimer = setTimeout(() => {
    authStore.logout()
    router.push({ name: 'login' })
  }, IDLE_TIMEOUT_MS)
}
const EVENTS = ['mousemove', 'keydown', 'mousedown', 'touchstart']

onMounted(() => {
  EVENTS.forEach((e) => window.addEventListener(e, resetIdleTimer, { passive: true }))
  resetIdleTimer()
})
onUnmounted(() => {
  EVENTS.forEach((e) => window.removeEventListener(e, resetIdleTimer))
  if (idleTimer) clearTimeout(idleTimer)
})
</script>
