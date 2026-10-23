<template>
  <div class="dm shell">
    <a href="#main-content" class="skip">ข้ามไปยังเนื้อหาหลัก</a>

    <!-- แถบเมนู: สร้างจาก /api/me/menu ทั้งหมด (ชื่อตาม scope และ badge จาก API) -->
    <aside class="side" :class="{ open: drawer }" aria-label="เมนูหลัก">
      <div class="brand">
        <div class="mark" aria-hidden="true">DM</div>
        <div class="brand-text">
          <span class="brand-name">ระบบบุคลากร</span>
          <span class="sub">วิทยาลัยเทคนิคดอนเมือง</span>
        </div>
        <button type="button" class="close-drawer" aria-label="ปิดเมนู" @click="drawer = false">✕</button>
      </div>

      <nav class="nav">
        <p v-if="menu.error" class="sub nav-note">{{ menu.error }}</p>
        <template v-for="group in menu.groups" :key="group.code">
          <div class="grp">{{ group.name }}</div>
          <template v-for="item in group.items" :key="item.code">
            <component
              :is="hasPage(item.route) ? 'router-link' : 'span'"
              :to="hasPage(item.route) ? item.route : undefined"
              class="nav-item"
              :class="{ on: isActive(item.route), soon: !hasPage(item.route) }"
              :aria-disabled="hasPage(item.route) ? undefined : 'true'"
              @click="drawer = false"
            >
              <span class="icon" aria-hidden="true" v-html="iconOf(item.code, group.icon)"></span>
              <span class="label">{{ item.name }}</span>
              <span v-if="item.badge" class="cnt" :aria-label="`${item.badge} รายการ`">{{ item.badge }}</span>
              <span v-else-if="!hasPage(item.route)" class="soon-tag">เร็วๆ นี้</span>
            </component>
            <router-link
              v-for="child in item.children || []"
              :key="child.code"
              :to="child.route"
              class="nav-item child"
              :class="{ on: isActive(child.route) }"
              @click="drawer = false"
            >
              <span class="label">{{ child.name }}</span>
            </router-link>
          </template>
        </template>
      </nav>

      <div class="me">
        <div class="avatar" aria-hidden="true">{{ initials }}</div>
        <div class="me-text">
          <span class="me-name">{{ displayName }}</span>
          <span class="sub">{{ positionText }}</span>
        </div>
        <button type="button" class="logout" title="ออกจากระบบ" aria-label="ออกจากระบบ" @click="logout">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3" /><path d="M10 17l-5-5 5-5" /><path d="M5 12h11" /></svg>
        </button>
      </div>
    </aside>
    <div v-if="drawer" class="scrim" aria-hidden="true" @click="drawer = false"></div>

    <main class="main">
      <header class="top">
        <button type="button" class="hamburger" aria-label="เปิดเมนู" :aria-expanded="drawer" @click="drawer = true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
        </button>
        <nav class="crumbs" aria-label="ตำแหน่งของหน้า">
          <span v-for="(c, i) in crumbs" :key="i">
            <router-link v-if="c.to && i < crumbs.length - 1" :to="c.to">{{ c.label }}</router-link>
            <span v-else :aria-current="i === crumbs.length - 1 ? 'page' : undefined">{{ c.label }}</span>
            <span v-if="i < crumbs.length - 1" class="sep" aria-hidden="true"> / </span>
          </span>
        </nav>
      </header>
      <div id="main-content" class="content" tabindex="-1">
        <slot />
      </div>
    </main>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useMenuStore } from '../stores/menuStore'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const menu = useMenuStore()
const drawer = ref(false)

onMounted(() => menu.load())
// badge เช่น คำขอแก้ไขรอตรวจ ปรับตามงานที่เพิ่งทำ
watch(() => route.fullPath, () => menu.load(true))

/** เมนูที่ยังไม่มีหน้าในระบบ (เฟสถัดไป) แสดงเป็น "เร็วๆ นี้" — ตัดสินจาก router ไม่ใช่รายการเมนูในโค้ด */
const hasPage = (path) => {
  if (!path) return false
  const resolved = router.resolve(path)
  return resolved.matched.length > 0 && resolved.name !== 'not-found'
}
const isActive = (path) => path === '/' ? route.path === '/' : (route.path === path || route.path.startsWith(path + '/')) && !menu.flat.some((m) => m.route !== path && m.route.startsWith(path + '/') && route.path.startsWith(m.route))

const person = computed(() => auth.user?.personnel)
const displayName = computed(() => person.value?.full_name || auth.user?.username || '')
const initials = computed(() => {
  const p = person.value
  if (p?.first_name) return (p.first_name.slice(0, 1) + (p.last_name || '').slice(0, 1)).trim()
  return (auth.user?.username || '?').slice(0, 2).toUpperCase()
})
const positionText = computed(() => {
  const primary = person.value?.primary
  return primary ? [primary.position, primary.unit].filter(Boolean).join(' · ') : (auth.user?.account_type === 'personnel' ? '' : 'บัญชีระบบ')
})

const crumbs = computed(() => {
  const own = menu.byRoute(route.path)
  const parentPath = route.meta.parentRoute
  const parent = parentPath ? menu.byRoute(parentPath) : null
  const list = []
  const group = own?.group || parent?.group
  if (group && route.path !== '/') list.push({ label: group.name })
  if (parent) list.push({ label: parent.name, to: parentPath })
  list.push({ label: route.meta.dynamicTitle || own?.name || route.meta.title || '' })
  return list
})

const logout = async () => {
  auth.logout()
  router.push({ name: 'login' })
}

// ไอคอนของต้นแบบตามรหัสเมนู (การแสดงผลเท่านั้น — รายการเมนูมาจาก API)
const ICONS = {
  home: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/></svg>',
  person: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c.8-3.6 3.6-5.5 7-5.5s6.2 1.9 7 5.5"/></svg>',
  users: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.8c1.9.7 3.1 2.5 3.5 5.2"/></svg>',
  inbox: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 13l2-8h12l2 8v6H4z"/><path d="M4 13h5l1 2h4l1-2h5"/></svg>',
  edit: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6z"/><path d="M9 11h7M9 15h7M9 7h4"/></svg>',
  tree: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="3" width="6" height="5" rx="1"/><rect x="3" y="16" width="6" height="5" rx="1"/><rect x="15" y="16" width="6" height="5" rx="1"/><path d="M12 8v4M6 16v-2h12v2"/></svg>',
  chart: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>',
  bell: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21h4"/></svg>',
  shield: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6z"/></svg>',
  settings: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>',
}
const BY_CODE = {
  'core.home': 'home', 'core.me': 'person', 'core.inbox': 'inbox', 'core.notifications': 'bell',
  'personnel.list': 'users', 'personnel.change_requests': 'edit', 'personnel.reports': 'chart',
  'org.structure': 'tree', 'org.positions': 'tree', 'org.assignments': 'edit',
}
const iconOf = (code, groupIcon) => ICONS[BY_CODE[code]] || ICONS[groupIcon] || ICONS.home
</script>

<style scoped>
.shell { min-height: 100vh; display: flex; background: var(--bg); }
.skip { position: absolute; left: -9999px; }
.skip:focus { left: 12px; top: 12px; z-index: 60; background: #1B2430; color: #fff; padding: 8px 14px; border-radius: 8px; }

.side { width: 248px; flex: 0 0 248px; box-sizing: border-box; background: #fff; border-right: 1px solid var(--line); padding: 20px 14px; display: flex; flex-direction: column; gap: 4px; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
.brand { display: flex; align-items: center; gap: 12px; padding: 4px 8px 12px; }
.mark { width: 40px; height: 40px; border-radius: 10px; background: #1B2430; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; flex: none; }
.brand-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.brand-name { font-size: 15px; font-weight: 700; }
.close-drawer { display: none; margin-left: auto; border: 0; background: none; font-size: 18px; min-width: 44px; min-height: 44px; cursor: pointer; color: var(--muted); }

.nav { display: flex; flex-direction: column; gap: 2px; }
.nav-note { padding: 8px 12px; }
.grp { font-size: 12px; letter-spacing: .04em; color: var(--muted); padding: 18px 12px 6px; font-weight: 600; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; color: var(--ink-3); text-decoration: none; font-size: 14px; min-height: 40px; box-sizing: border-box; }
.nav-item:hover { background: var(--bg-3); color: var(--ink); }
.nav-item.on { background: #1B2430; color: #fff; }
.nav-item.soon { color: var(--muted); cursor: default; }
.nav-item.soon:hover { background: none; color: var(--muted); }
.nav-item.child { padding-left: 40px; font-size: 13px; min-height: 36px; }
.icon { display: inline-flex; flex: none; }
.label { min-width: 0; overflow: hidden; text-overflow: ellipsis; }
.cnt { margin-left: auto; background: #B54A2A; color: #fff; font-size: 11px; font-weight: 600; border-radius: 999px; padding: 1px 8px; }
.soon-tag { margin-left: auto; font-size: 11px; color: var(--muted); border: 1px solid var(--line-3); border-radius: 999px; padding: 0 8px; white-space: nowrap; }

.me { margin-top: auto; padding: 16px 8px 0; border-top: 1px solid var(--line-2); display: flex; align-items: center; gap: 10px; }
.avatar { width: 36px; height: 36px; border-radius: 999px; background: #D6E6F2; color: #1C4F73; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; flex: none; }
.me-text { display: flex; flex-direction: column; gap: 1px; min-width: 0; flex: 1; }
.me-name { font-size: 14px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.logout { border: 0; background: none; color: var(--muted); min-width: 40px; min-height: 40px; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
.logout:hover { background: var(--bg-3); color: var(--ink); }

.main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.top { display: flex; align-items: center; gap: 12px; padding: 14px 32px; background: #fff; border-bottom: 1px solid var(--line); min-height: 64px; box-sizing: border-box; }
.crumbs { font-size: 13px; color: var(--muted); min-width: 0; }
.crumbs a { color: var(--muted); text-decoration: none; }
.crumbs a:hover { color: var(--ink); text-decoration: underline; }
.hamburger { display: none; border: 0; background: none; min-width: 44px; min-height: 44px; border-radius: 8px; cursor: pointer; color: var(--ink); }
.content { flex: 1; outline: none; }
.scrim { display: none; }

@media (max-width: 960px) {
  .side { position: fixed; left: 0; top: 0; bottom: 0; z-index: 50; transform: translateX(-100%); transition: transform .2s ease; box-shadow: 0 10px 30px rgba(0, 0, 0, .15); }
  .side.open { transform: none; }
  .close-drawer { display: inline-flex; align-items: center; justify-content: center; }
  .hamburger { display: inline-flex; align-items: center; justify-content: center; }
  .scrim { display: block; position: fixed; inset: 0; background: rgba(27, 36, 48, .4); z-index: 40; }
  .top { padding: 10px 16px; }
}
</style>
