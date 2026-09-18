<template>
  <div class="h-screen flex bg-gray-50 overflow-hidden font-sans">
    <!-- Skip to main content link for keyboard and screen reader accessibility -->
    <a
      href="#main-content"
      class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:bg-[#800000] focus:text-white focus:px-4 focus:py-2 focus:rounded-xl focus:shadow-xl focus:outline-none focus:ring-2 focus:ring-white text-sm font-semibold"
    >
      ข้ามไปยังเนื้อหาหลัก
    </a>

    <AlertList />
    <ConfirmDialog />

    <!-- Dual-Rail Navigation (desktop) -->
    <aside
      v-if="!isBareLayout"
      class="hidden md:flex flex-row bg-[#800000] text-white shadow-2xl flex-shrink-0 z-20 h-screen select-none"
      aria-label="แถบนำทางแบบโมดูลและเมนูระบบ"
    >
      <!-- Rail 1: Primary Module Rail (72px) -->
      <div class="w-[72px] bg-[#750000] flex flex-col justify-between items-center py-4 border-r border-white/10 z-30 h-full flex-shrink-0">
        <!-- Top: Logo & Brand Emblem -->
        <div class="flex flex-col items-center gap-1">
          <router-link
            to="/"
            class="w-11 h-11 bg-white/15 hover:bg-white/25 rounded-2xl flex items-center justify-center transition-all duration-200 border border-white/20 shadow-sm group"
            title="ระบบบริหารสถานศึกษา E-la"
            aria-label="หน้าหลัก E-la"
          >
            <span class="font-bold text-white text-base tracking-tight font-sans group-hover:scale-110 transition-transform">E</span>
          </router-link>
        </div>

        <!-- Middle: Primary Module Tabs -->
        <nav class="flex-1 w-full px-1.5 py-4 space-y-1.5 overflow-y-auto no-scrollbar flex flex-col items-center" aria-label="แถบเลือกโมดูลหลัก">
          <button
            v-for="mod in availableModules"
            :key="mod.key"
            type="button"
            @click="selectModule(mod.key)"
            class="w-full flex flex-col items-center justify-center py-2 px-1 rounded-xl transition-all duration-200 relative group focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
            :class="activeModuleKey === mod.key
              ? 'bg-white/20 text-white font-semibold shadow-sm'
              : 'text-white/70 hover:bg-white/10 hover:text-white'"
            :title="mod.full_title"
            :aria-label="mod.full_title"
          >
            <!-- Active indicator pill on left edge -->
            <span
              v-if="activeModuleKey === mod.key"
              class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-6 bg-white rounded-r-full"
              aria-hidden="true"
            ></span>

            <!-- Module Icon -->
            <div class="relative">
              <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="iconPath(mod.icon)"/>
              </svg>
              <!-- Module badge pill -->
              <span
                v-if="moduleBadges[mod.key]"
                class="absolute -top-1.5 -right-2 bg-red-500 text-white text-[9px] font-bold px-1 py-0.2 rounded-full ring-2 ring-[#750000]"
              >
                {{ moduleBadges[mod.key] > 99 ? '99+' : moduleBadges[mod.key] }}
              </span>
            </div>

            <!-- Short Thai caption -->
            <span class="text-[10px] mt-1 text-center whitespace-nowrap leading-none tracking-tight">
              {{ mod.short_title }}
            </span>
          </button>
        </nav>

        <!-- Bottom: Actions & Collapse Toggle -->
        <div class="w-full px-1.5 pt-2 border-t border-white/10 flex flex-col items-center gap-2">
          <!-- Toggle Subpanel Button -->
          <button
            @click="toggleSubPanel"
            class="w-10 h-10 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white/80 hover:text-white transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
            :title="isSubPanelCollapsed ? 'ขยายแผงเมนูย่อย' : 'ย่อแผงเมนูย่อย'"
            :aria-label="isSubPanelCollapsed ? 'ขยายแผงเมนูย่อย' : 'ย่อแผงเมนูย่อย'"
          >
            <svg class="w-4 h-4 transition-transform duration-200" :class="{'rotate-180': isSubPanelCollapsed}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
            </svg>
          </button>

          <!-- User Profile Icon -->
          <router-link
            to="/profile"
            class="w-10 h-10 bg-white/20 hover:bg-white/30 rounded-full flex items-center justify-center font-bold text-xs text-white transition-colors"
            :title="authStore.user?.name || 'โปรไฟล์ส่วนตัว'"
            aria-label="โปรไฟล์ส่วนตัว"
          >
            {{ authStore.user?.name?.charAt(0)?.toUpperCase() }}
          </router-link>

          <!-- Logout Button -->
          <button
            @click="confirmLogout"
            class="w-10 h-10 rounded-xl hover:bg-red-800/80 text-white/70 hover:text-white flex items-center justify-center transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
            title="ออกจากระบบ"
            aria-label="ออกจากระบบ"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
          </button>
        </div>
      </div>

      <!-- Rail 2: Sub-Navigation Panel (240px) -->
      <div
        class="bg-[#820707] flex flex-col h-full border-r border-white/10 transition-all duration-300 ease-in-out overflow-hidden z-20"
        :class="isSubPanelCollapsed ? 'w-0 opacity-0 border-r-0' : 'w-60 opacity-100'"
      >
        <!-- Sub-panel Header -->
        <div class="px-4 py-4 border-b border-white/10 flex items-center justify-between flex-shrink-0">
          <div class="min-w-0 flex-1">
            <h2 class="font-bold text-sm text-white truncate flex items-center gap-1.5">
              <span>{{ activeModuleInfo.full_title }}</span>
            </h2>
            <p class="text-white/60 text-[11px] truncate">E-la สถานศึกษาอาชีวศึกษา</p>
          </div>
          <button
            @click="toggleSubPanel"
            class="p-1 rounded-lg text-white/60 hover:text-white hover:bg-white/10 transition-colors focus:outline-none"
            title="ย่อแผงเมนูย่อย"
            aria-label="ย่อแผงเมนูย่อย"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
          </button>
        </div>

        <!-- Sub-panel Menu Items -->
        <div class="flex-1 px-3 py-3 overflow-y-auto">
          <AppMenuNav
            :menus="activeModuleGroups"
            :badges="menuBadges"
            :aria-label="`เมนูย่อยของ${activeModuleInfo.full_title}`"
          />
        </div>

        <!-- Sub-panel Footer -->
        <div class="px-4 py-3 border-t border-white/10 text-xs text-white/50 text-center truncate flex-shrink-0">
          ออกอัตโนมัติใน: {{ formattedIdleTime }}
        </div>
      </div>
    </aside>

    <!-- Mobile Drawer Overlay & Panel -->
    <transition name="drawer-fade">
      <div
        v-if="mobileMenu && !isBareLayout"
        class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 md:hidden"
        @click="mobileMenu = false"
      ></div>
    </transition>

    <transition name="drawer-slide">
      <div
        v-if="mobileMenu && !isBareLayout"
        role="dialog"
        aria-modal="true"
        aria-label="เมนูนำทางบนอุปกรณ์มือถือ"
        @keydown.escape="mobileMenu = false"
        tabindex="-1"
        class="fixed inset-y-0 left-0 w-80 max-w-[90vw] bg-[#800000] text-white shadow-2xl z-50 md:hidden flex flex-col h-full overflow-hidden focus:outline-none"
      >
        <!-- Mobile Drawer Header -->
        <div class="flex items-center justify-between px-4 py-3.5 border-b border-white/10 flex-shrink-0">
          <div class="flex items-center gap-2.5">
            <div class="w-8 h-8 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
            </div>
            <div>
              <h1 class="font-bold text-base leading-tight">E-Leave</h1>
              <p class="text-white/60 text-xs">ระบบบริหารสถานศึกษา</p>
            </div>
          </div>
          <button @click="mobileMenu = false" class="p-1.5 rounded-lg text-white/80 hover:bg-white/10 hover:text-white transition-colors focus-visible:ring-2 focus-visible:ring-white focus:outline-none" aria-label="ปิดเมนู">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <!-- Mobile Drawer User Profile -->
        <router-link to="/profile" @click="mobileMenu = false" class="block px-4 py-3 border-b border-white/10 hover:bg-white/5 transition-colors cursor-pointer flex-shrink-0" aria-label="ดูข้อมูลโปรไฟล์ส่วนตัว">
          <div class="flex items-center gap-3">
            <div class="w-9 h-9 bg-white/20 rounded-full flex items-center justify-center font-bold text-sm flex-shrink-0">
              {{ authStore.user?.name?.charAt(0)?.toUpperCase() }}
            </div>
            <div class="min-w-0 flex-1">
              <p class="font-semibold text-sm truncate text-white">{{ authStore.user?.name }}</p>
              <span class="inline-block text-xs px-2 py-0.5 rounded-full mt-0.5 truncate max-w-full" :class="roleBadgeClass">
                {{ roleLabel }}
              </span>
            </div>
          </div>
        </router-link>

        <!-- Mobile Drawer Horizontal Module Selector -->
        <div class="px-3 py-2 border-b border-white/10 flex gap-1.5 overflow-x-auto no-scrollbar flex-shrink-0 bg-[#720000]" aria-label="เลือกโมดูลบนมือถือ">
          <button
            v-for="mod in availableModules"
            :key="mod.key"
            type="button"
            @click="selectModule(mod.key)"
            class="px-2.5 py-1.5 rounded-lg text-xs font-medium whitespace-nowrap flex items-center gap-1.5 transition-colors"
            :class="activeModuleKey === mod.key ? 'bg-white text-[#800000] font-bold shadow-sm' : 'bg-white/10 text-white/80 hover:bg-white/20 hover:text-white'"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="iconPath(mod.icon)"/>
            </svg>
            <span>{{ mod.short_title }}</span>
          </button>
        </div>

        <!-- Mobile Drawer Scrollable Nav for Active Module -->
        <div class="flex-1 px-3 py-3 overflow-y-auto">
          <div class="mb-2 pb-1 border-b border-white/10 text-xs font-semibold text-white/70 flex items-center gap-1.5">
            <span>{{ activeModuleInfo.full_title }}</span>
          </div>
          <AppMenuNav
            :menus="activeModuleGroups"
            :badges="menuBadges"
            aria-label="เมนูหลักบนมือถือ"
            @navigate="mobileMenu = false"
          />
        </div>

        <!-- Mobile Drawer Footer / Logout -->
        <div class="px-4 py-3 border-t border-white/10 bg-[#700000] flex-shrink-0">
          <div class="text-xs text-center text-white/50 mb-1.5 truncate">
            ออกอัตโนมัติใน: {{ formattedIdleTime }}
          </div>
          <button
            @click="confirmLogout"
            class="flex items-center justify-center gap-2 w-full px-3 py-2 rounded-xl text-white/90 hover:bg-red-900/60 hover:text-white transition-colors font-medium text-sm"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
            ออกจากระบบ
          </button>
        </div>
      </div>
    </transition>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col h-screen min-w-0 overflow-hidden">
      <!-- Mobile Top Header Bar -->
      <header
        v-if="!isBareLayout"
        class="md:hidden bg-[#800000] text-white px-4 py-3 flex justify-between items-center shadow-md z-30 flex-shrink-0 sticky top-0"
      >
        <div class="flex items-center gap-3">
          <button
            @click="mobileMenu = true"
            class="p-1.5 rounded-lg hover:bg-white/15 transition-colors focus:outline-none"
            aria-label="เปิดเมนูนำทาง"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
          </button>
          <span class="font-bold text-lg tracking-tight">E-Leave</span>
        </div>
        <div class="flex items-center gap-2">
          <div class="bg-white/10 rounded-full">
            <NotificationBell />
          </div>
          <router-link to="/profile" class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center font-bold text-xs" aria-label="โปรไฟล์ส่วนตัว">
            {{ authStore.user?.name?.charAt(0)?.toUpperCase() }}
          </router-link>
        </div>
      </header>

      <!-- Desktop Header with Breadcrumb -->
      <header
        v-if="!isBareLayout"
        class="hidden md:flex bg-white shadow-sm border-b border-gray-200 px-6 lg:px-8 py-3.5 justify-between items-center z-10 flex-shrink-0"
      >
        <!-- Breadcrumb Navigation -->
        <nav class="flex items-center gap-2 text-sm text-gray-500 font-medium" aria-label="แถบนำทางตำแหน่ง">
          <router-link to="/" class="hover:text-[#800000] flex items-center gap-1.5 transition-colors">
            <svg class="w-4 h-4 text-[#800000]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
            <span>หน้าหลัก</span>
          </router-link>
          <template v-if="route.path !== '/'">
            <span class="text-gray-300">/</span>
            <span class="text-[#800000] font-medium">{{ activeModuleInfo.short_title }}</span>
            <span class="text-gray-300">/</span>
            <span class="text-gray-900 font-semibold truncate max-w-[300px]">{{ currentRouteTitle }}</span>
          </template>
        </nav>

        <div class="flex items-center gap-4">
          <NotificationBell />
          <div class="h-5 w-px bg-gray-200"></div>
          <router-link to="/profile" class="flex items-center gap-2 text-sm text-gray-700 hover:text-gray-900" aria-label="ดูข้อมูลโปรไฟล์ส่วนตัว">
            <div class="w-8 h-8 bg-[#800000]/10 text-[#800000] font-bold rounded-full flex items-center justify-center text-xs">
              {{ authStore.user?.name?.charAt(0)?.toUpperCase() }}
            </div>
            <span class="font-medium truncate max-w-[140px]">{{ authStore.user?.name }}</span>
          </router-link>
        </div>
      </header>

      <!-- Page Content (Independent Scrolling Container) -->
      <main class="flex-1 p-4 md:p-6 lg:p-8 overflow-y-auto focus:outline-none" id="main-content" tabindex="-1">
        <router-view :key="$route.fullPath" />
      </main>
    </div>

    <!-- Logout Confirmation Modal -->
    <transition name="fade">
      <div
        v-if="showLogoutModal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="logout-modal-title"
        @keydown.escape="showLogoutModal = false"
        tabindex="-1"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 focus:outline-none"
      >
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
          <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
          </div>
          <h3 id="logout-modal-title" class="font-bold text-gray-900 text-lg mb-1">ออกจากระบบ?</h3>
          <p class="text-gray-500 text-sm mb-6">คุณต้องการออกจากระบบใช่หรือไม่?</p>
          <div class="flex gap-3">
            <button @click="showLogoutModal = false"
              class="flex-1 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl text-sm transition-colors focus-visible:ring-2 focus-visible:ring-gray-400 focus:outline-none">
              ยกเลิก
            </button>
            <button @click="doLogout"
              class="flex-1 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm transition-colors focus-visible:ring-2 focus-visible:ring-[#800000] focus:outline-none">
              ออกจากระบบ
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from './stores/auth'
import { useLeaveStore } from './stores/leave'
import { useSettingStore } from './stores/settingStore'
import { useMenuStore } from './stores/menuStore'
import { ICONS } from './utils/icons'
import AlertList from './components/AlertList.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import AppMenuNav from './components/AppMenuNav.vue'
import NotificationBell from './components/NotificationBell.vue'

const router = useRouter()
const route = useRoute()
// หน้าที่ไม่ต้องมีเมนู/แถบบน และไม่ต้องนับเวลาหมดอายุการใช้งาน (หน้าล็อกอิน, กู้รหัสผ่าน, และหน้าตรวจสอบเอกสารสาธารณะ)
const isBareLayout = computed(() => {
  if (route.meta?.requiresGuest || route.meta?.bare) return true
  return ['login', 'forgot-password', 'reset-password', 'verify-leave'].includes(route.name)
})

const authStore = useAuthStore()
const leaveStore = useLeaveStore()
const settingStore = useSettingStore()
const menuStore = useMenuStore()

const mobileMenu = ref(false)
const showLogoutModal = ref(false)

// Dual-Rail State
const activeModuleKey = ref('dashboard')
const isSubPanelCollapsed = ref(localStorage.getItem('e_leave_subpanel_collapsed') === 'true')

const toggleSubPanel = () => {
  isSubPanelCollapsed.value = !isSubPanelCollapsed.value
  localStorage.setItem('e_leave_subpanel_collapsed', isSubPanelCollapsed.value ? 'true' : 'false')
}

const iconPath = (icon) => ICONS[icon] || (icon === 'settings' ? ICONS.cog : null) || ICONS.document

const currentRouteTitle = computed(() => {
  if (route.meta?.title) return route.meta.title
  return 'ระบบบริหารสถานศึกษา E-la'
})

/*
 * ข้อมูล Meta ของแต่ละโมดูลหลัก
 */
const KNOWN_MODULE_METAS = {
  dashboard: {
    key: 'dashboard',
    short_title: 'หน้าหลัก',
    full_title: 'หน้าหลักและภาพรวม',
    icon: 'home',
    order: 10,
    route_path: '/',
  },
  leave: {
    key: 'leave',
    short_title: 'ลางาน',
    full_title: 'ระบบลางาน',
    icon: 'calendar',
    order: 20,
  },
  attendance: {
    key: 'attendance',
    short_title: 'ลงเวลา/OT',
    full_title: 'ระบบลงเวลาและ OT',
    icon: 'clock',
    order: 30,
  },
  saraban: {
    key: 'saraban',
    short_title: 'สารบรรณ',
    full_title: 'งานสารบรรณอิเล็กทรอนิกส์',
    icon: 'document',
    order: 40,
  },
  reporting: {
    key: 'reporting',
    short_title: 'รายงาน',
    full_title: 'ศูนย์รายงานและสถิติ',
    icon: 'chart',
    order: 50,
  },
  users: {
    key: 'users',
    short_title: 'บุคลากร',
    full_title: 'ผู้ใช้งานและบุคลากร',
    icon: 'users',
    order: 60,
  },
  system: {
    key: 'system',
    short_title: 'ตั้งค่า',
    full_title: 'การตั้งค่าระบบ',
    icon: 'cog',
    order: 70,
  },
}

/*
 * แยกเมนูจาก backend เข้าสู่โมดูลหลักอย่างเป็นระเบียบ (Dynamic Module Partitioning)
 */
const availableModules = computed(() => {
  const menus = menuStore.menus || []
  const moduleMap = new Map()

  // Always include dashboard
  moduleMap.set('dashboard', {
    ...KNOWN_MODULE_METAS.dashboard,
    groups: [],
  })

  for (const item of menus) {
    if (item.code === 'dashboard' || item.route_path === '/') {
      continue
    }

    let modKey = item.module || 'system'
    if (modKey === 'overtime') {
      modKey = 'attendance'
    }

    if (!moduleMap.has(modKey)) {
      const known = KNOWN_MODULE_METAS[modKey]
      moduleMap.set(modKey, {
        key: modKey,
        short_title: known?.short_title || item.title_th || modKey,
        full_title: known?.full_title || item.title_th || modKey,
        icon: known?.icon || item.icon || 'document',
        order: known?.order || 99,
        groups: [],
      })
    }

    const currentMod = moduleMap.get(modKey)
    currentMod.groups.push(item)
  }

  return Array.from(moduleMap.values()).sort((a, b) => a.order - b.order)
})

/*
 * ป้ายตัวเลขบนเมนู — runtime badges
 */
const menuBadges = computed(() => ({
  approvals: leaveStore.approvals?.filter((l) => l.cancellation_status !== 'Pending').length || 0,
  'cancel-approvals': leaveStore.approvals?.filter((l) => l.cancellation_status === 'Pending').length || 0,
}))

// คำนวณ badge รวมของแต่ละโมดูล
const moduleBadges = computed(() => {
  const badges = {}
  for (const mod of availableModules.value) {
    let sum = 0
    for (const group of mod.groups) {
      if (group.route_name && menuBadges.value[group.route_name]) {
        sum += menuBadges.value[group.route_name]
      }
      for (const child of group.children || []) {
        if (child.route_name && menuBadges.value[child.route_name]) {
          sum += menuBadges.value[child.route_name]
        }
      }
    }
    badges[mod.key] = sum
  }
  return badges
})

// ข้อมูลโมดูลที่เลือกอยู่
const activeModuleInfo = computed(() => {
  const mod = availableModules.value.find((m) => m.key === activeModuleKey.value)
  return mod || KNOWN_MODULE_METAS[activeModuleKey.value] || KNOWN_MODULE_METAS.dashboard
})

// กลุ่มเมนูของโมดูลที่เลือกอยู่
const activeModuleGroups = computed(() => {
  if (activeModuleKey.value === 'dashboard') {
    return [
      {
        code: 'dashboard_overview',
        title_th: 'หน้าหลักและภาพรวม',
        icon: 'home',
        children: [
          {
            code: 'dashboard_home',
            title_th: 'หน้าหลัก (Dashboard)',
            route_path: '/',
            icon: 'home',
          },
          {
            code: 'dashboard_profile',
            title_th: 'ข้อมูลโปรไฟล์ของฉัน',
            route_path: '/profile',
            icon: 'user',
          },
          {
            code: 'dashboard_access_history',
            title_th: 'ประวัติการเข้าถึงข้อมูลของฉัน',
            route_path: '/my-access-history',
            icon: 'shieldCheck',
          },
        ],
      },
    ]
  }

  const mod = availableModules.value.find((m) => m.key === activeModuleKey.value)
  return mod?.groups || []
})

const selectModule = (key) => {
  activeModuleKey.value = key
  if (isSubPanelCollapsed.value) {
    isSubPanelCollapsed.value = false
    localStorage.setItem('e_leave_subpanel_collapsed', 'false')
  }
  if (key === 'dashboard') {
    router.push('/')
  }
}

// ตรวจหาโมดูลอัตโนมัติจากเส้นทาง URL ปัจจุบัน
const detectModuleFromRoute = (path) => {
  if (path === '/' || path === '') return 'dashboard'

  for (const group of menuStore.menus || []) {
    let modKey = group.module || 'system'
    if (modKey === 'overtime') modKey = 'attendance'

    if (group.route_path === path) return modKey
    for (const child of group.children || []) {
      if (child.route_path === path || path.startsWith(child.route_path + '/')) {
        return modKey
      }
    }
  }

  if (path.startsWith('/saraban')) return 'saraban'
  if (path.startsWith('/attendance') || path.startsWith('/admin/ot') || path.startsWith('/admin/overtime') || path.startsWith('/admin/attendances')) return 'attendance'
  if (path.startsWith('/request') || path.startsWith('/leaves') || path.startsWith('/leave-card') || path.startsWith('/team-calendar') || path.startsWith('/approvals') || path.startsWith('/cancel-approvals') || path.startsWith('/reports/vocational') || path.startsWith('/admin/leave') || path.startsWith('/admin/promotion-screening')) return 'leave'
  if (path.startsWith('/reports') || path.startsWith('/admin/reports')) return 'reporting'
  if (path.startsWith('/admin/users') || path.startsWith('/admin/roles') || path.startsWith('/admin/org') || path.startsWith('/admin/personnel') || path.startsWith('/my-access-history')) return 'users'
  if (path.startsWith('/admin/settings') || path.startsWith('/admin/modules') || path.startsWith('/admin/fiscal') || path.startsWith('/admin/calendar') || path.startsWith('/admin/workflow') || path.startsWith('/admin/leave-types') || path.startsWith('/admin/document-templates')) return 'system'

  return 'dashboard'
}

watch(
  () => [route.path, menuStore.menus],
  ([newPath]) => {
    mobileMenu.value = false
    const matched = detectModuleFromRoute(newPath)
    if (matched) {
      activeModuleKey.value = matched
    }
  },
  { immediate: true },
)

const IDLE_TIMEOUT_MS = 30 * 60 * 1000 // 30 minutes
const idleTimeLeft = ref(IDLE_TIMEOUT_MS)
let idleTimer = null
let countdownInterval = null

const resetIdleTimer = () => {
  if (isBareLayout.value) return
  idleTimeLeft.value = IDLE_TIMEOUT_MS
  if (idleTimer) clearTimeout(idleTimer)
  idleTimer = setTimeout(() => {
    doLogout()
  }, IDLE_TIMEOUT_MS)
}

const updateCountdown = () => {
  if (isBareLayout.value) return
  idleTimeLeft.value = Math.max(0, idleTimeLeft.value - 1000)
}

const formattedIdleTime = computed(() => {
  const totalSeconds = Math.floor(idleTimeLeft.value / 1000)
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60
  return `${minutes}:${seconds.toString().padStart(2, '0')}`
})

watch(() => authStore.isAuthenticated, (signedIn) => {
  if (signedIn && !menuStore.loaded) menuStore.fetchUserMenus()
}, { immediate: true })

onMounted(async () => {
  await settingStore.fetchSettings()

  window.addEventListener('mousemove', resetIdleTimer)
  window.addEventListener('keydown', resetIdleTimer)
  window.addEventListener('mousedown', resetIdleTimer)
  window.addEventListener('touchstart', resetIdleTimer)

  resetIdleTimer()
  countdownInterval = setInterval(updateCountdown, 1000)
})

onUnmounted(() => {
  window.removeEventListener('mousemove', resetIdleTimer)
  window.removeEventListener('keydown', resetIdleTimer)
  window.removeEventListener('mousedown', resetIdleTimer)
  window.removeEventListener('touchstart', resetIdleTimer)
  if (idleTimer) clearTimeout(idleTimer)
  if (countdownInterval) clearInterval(countdownInterval)
})

const ROLE_LABELS_TH = {
  'Super Admin': 'ผู้ดูแลระบบสูงสุด',
  SuperAdmin: 'ผู้ดูแลระบบสูงสุด',
  Director: 'ผู้อำนวยการ',
  'Deputy Director': 'รองผู้อำนวยการ',
  Deputy: 'รองผู้อำนวยการ',
  'HR Head': 'หัวหน้างานบุคลากร',
  HRHead: 'หัวหน้างานบุคลากร',
  'Document Officer': 'เจ้าหน้าที่สารบรรณ',
  'Department Head': 'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย',
  Employee: 'บุคลากร / ครู / เจ้าหน้าที่',
}

const roleLabel = computed(() => {
  const role = authStore.user?.role?.name || authStore.roleName || ''
  if (role) return ROLE_LABELS_TH[role] || role
  return 'บุคลากร'
})

const roleBadgeClass = computed(() => {
  const map = {
    'ผู้ดูแลระบบสูงสุด': 'bg-yellow-400/30 text-yellow-200',
    'Super Admin': 'bg-yellow-400/30 text-yellow-200',
    'SuperAdmin':  'bg-yellow-400/30 text-yellow-200',
    'ผู้อำนวยการ': 'bg-blue-400/30 text-blue-200',
    'Director':    'bg-blue-400/30 text-blue-200',
    'รองผู้อำนวยการ': 'bg-purple-400/30 text-purple-200',
    'Deputy Director': 'bg-purple-400/30 text-purple-200',
    'Deputy':      'bg-purple-400/30 text-purple-200',
    'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย': 'bg-teal-400/30 text-teal-200',
    'เจ้าหน้าที่บุคลากร (HR)': 'bg-green-400/30 text-green-200',
    'HR Head':     'bg-green-400/30 text-green-200',
    'HRHead':      'bg-green-400/30 text-green-200',
    'เจ้าหน้าที่สารบรรณกลาง': 'bg-amber-400/30 text-amber-200',
    'Document Officer': 'bg-amber-400/30 text-amber-200',
    'บุคลากร / ครู / เจ้าหน้าที่': 'bg-white/10 text-white/70',
    'Employee':    'bg-white/10 text-white/70',
  }
  return map[authStore.user?.role?.name] || map[authStore.roleName] || 'bg-white/10 text-white/70'
})

const confirmLogout = () => {
  mobileMenu.value = false
  showLogoutModal.value = true
}

const doLogout = () => {
  showLogoutModal.value = false
  authStore.logout()
  router.push({ name: 'login' })
}
</script>

<style scoped>
/* ซ่อน Scrollbar แถบโมดูล แต่ยังเลื่อนได้ */
.no-scrollbar::-webkit-scrollbar {
  display: none;
}
.no-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}

/* Drawer transitions */
.drawer-fade-enter-active,
.drawer-fade-leave-active {
  transition: opacity 0.25s ease;
}
.drawer-fade-enter-from,
.drawer-fade-leave-to {
  opacity: 0;
}

.drawer-slide-enter-active,
.drawer-slide-leave-active {
  transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.drawer-slide-enter-from,
.drawer-slide-leave-to {
  transform: translateX(-100%);
}

/* Fade for modal */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>



