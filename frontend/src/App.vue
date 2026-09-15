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

    <!-- Sidebar (desktop) -->
    <aside
      v-if="!isBareLayout"
      class="hidden md:flex md:flex-col bg-[#800000] text-white shadow-2xl flex-shrink-0 transition-all duration-300 relative z-20 h-screen"
      :class="isSidebarCollapsed ? 'w-20' : 'w-64'"
      aria-label="แถบเมนูหลักของระบบ"
    >
      <!-- Collapse Button -->
      <button 
        @click="isSidebarCollapsed = !isSidebarCollapsed" 
        class="absolute -right-3 top-8 w-6 h-6 bg-white text-[#800000] rounded-full shadow-md flex items-center justify-center hover:bg-gray-100 z-30 transition-transform focus:outline-none focus-visible:ring-2 focus-visible:ring-[#800000]"
        :class="{'rotate-180': isSidebarCollapsed}"
        :title="isSidebarCollapsed ? 'ขยายแถบเมนู' : 'ย่อแถบเมนู'"
        :aria-label="isSidebarCollapsed ? 'ขยายแถบเมนู' : 'ย่อแถบเมนู'"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
      </button>
      <!-- Logo -->
      <div class="flex items-center gap-3 py-6 border-b border-white/10 transition-all duration-300" :class="isSidebarCollapsed ? 'justify-center px-2' : 'px-6'">
        <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
        </div>
        <div v-show="!isSidebarCollapsed" class="whitespace-nowrap overflow-hidden">
          <h1 class="font-bold text-lg leading-tight">E-Leave</h1>
          <p class="text-white/60 text-xs">ระบบลางานออนไลน์</p>
        </div>
      </div>

      <!-- User info -->
      <router-link to="/profile" class="block py-4 border-b border-white/10 hover:bg-white/5 transition-all duration-300 cursor-pointer" :class="isSidebarCollapsed ? 'px-2' : 'px-6'" aria-label="ดูข้อมูลโปรไฟล์ส่วนตัว">
        <div class="flex items-center gap-3" :class="isSidebarCollapsed ? 'justify-center' : ''">
          <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center font-bold text-sm flex-shrink-0" :title="authStore.user?.name">
            {{ authStore.user?.name?.charAt(0)?.toUpperCase() }}
          </div>
          <div class="min-w-0 flex-1 whitespace-nowrap overflow-hidden" v-show="!isSidebarCollapsed">
            <p class="font-semibold text-sm truncate group-hover:text-white">{{ authStore.user?.name }}</p>
            <span class="inline-block text-xs px-2 py-0.5 rounded-full mt-0.5 truncate max-w-full" :class="roleBadgeClass">
              {{ roleLabel }}
            </span>
          </div>
          <svg v-show="!isSidebarCollapsed" class="w-4 h-4 text-white/50 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </div>
      </router-link>

      <!-- Navigation -->
      <div class="flex-1 px-4 py-4 overflow-y-auto">
        <AppMenuNav
          :menus="menuStore.menus"
          :collapsed="isSidebarCollapsed"
          :badges="menuBadges"
          aria-label="เมนูหลัก"
          @navigate="isSidebarCollapsed = false"
        />
      </div>

      <!-- Desktop Sidebar Footer / Logout -->
      <div class="px-4 py-4 border-t border-white/10 flex-shrink-0">
        <div v-show="!isSidebarCollapsed" class="text-xs text-center text-white/50 mb-2 truncate">
          ออกอัตโนมัติใน: {{ formattedIdleTime }}
        </div>
        <button
          @click="confirmLogout"
          class="flex items-center gap-3 w-full px-3 py-2.5 rounded-xl text-white/70 hover:bg-red-800/60 hover:text-white transition-all duration-150 group"
          :class="isSidebarCollapsed ? 'justify-center px-0' : ''"
          :title="isSidebarCollapsed ? 'ออกจากระบบ' : ''"
        >
          <svg class="w-5 h-5 group-hover:translate-x-0.5 transition-transform flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
          </svg>
          <span v-show="!isSidebarCollapsed" class="whitespace-nowrap">ออกจากระบบ</span>
        </button>
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
        class="fixed inset-y-0 left-0 w-72 max-w-[85vw] bg-[#800000] text-white shadow-2xl z-50 md:hidden flex flex-col h-full overflow-hidden focus:outline-none"
      >
        <!-- Mobile Drawer Header -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-white/10 flex-shrink-0">
          <div class="flex items-center gap-3">
            <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
            </div>
            <div>
              <h1 class="font-bold text-base leading-tight">E-Leave</h1>
              <p class="text-white/60 text-xs">ระบบลางานออนไลน์</p>
            </div>
          </div>
          <button @click="mobileMenu = false" class="p-1.5 rounded-lg text-white/80 hover:bg-white/10 hover:text-white transition-colors focus-visible:ring-2 focus-visible:ring-white focus:outline-none" aria-label="ปิดเมนู">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <!-- Mobile Drawer User Profile -->
        <router-link to="/profile" @click="mobileMenu = false" class="block px-5 py-3.5 border-b border-white/10 hover:bg-white/5 transition-colors cursor-pointer flex-shrink-0" aria-label="ดูข้อมูลโปรไฟล์ส่วนตัว">
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

        <!-- Mobile Drawer Scrollable Nav -->
        <div class="flex-1 px-3 py-3 overflow-y-auto">
          <AppMenuNav
            :menus="menuStore.menus"
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
          <router-link to="/profile" class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center font-bold text-xs">
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
            <span v-if="route.meta?.parent" class="text-gray-500">{{ route.meta.parent }}</span>
            <span v-if="route.meta?.parent" class="text-gray-300">/</span>
            <span class="text-gray-900 font-semibold truncate max-w-[300px]">{{ currentRouteTitle }}</span>
          </template>
        </nav>

        <div class="flex items-center gap-4">
          <NotificationBell />
          <div class="h-5 w-px bg-gray-200"></div>
          <router-link to="/profile" class="flex items-center gap-2 text-sm text-gray-700 hover:text-gray-900">
            <div class="w-8 h-8 bg-[#800000]/10 text-[#800000] font-bold rounded-full flex items-center justify-center text-xs">
              {{ authStore.user?.name?.charAt(0)?.toUpperCase() }}
            </div>
            <span class="font-medium truncate max-w-[140px]">{{ authStore.user?.name }}</span>
          </router-link>
        </div>
      </header>

      <!-- Page Content (Independent Scrolling Container) -->
      <main class="flex-1 p-4 md:p-6 lg:p-8 overflow-y-auto focus:outline-none" id="main-content" tabindex="-1">
        <router-view />
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
import AlertList from './components/AlertList.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import AppMenuNav from './components/AppMenuNav.vue'
import NotificationBell from './components/NotificationBell.vue'

const router = useRouter()
const route  = useRoute()
// หน้าที่ไม่ต้องมีเมนู/แถบบน และไม่ต้องนับเวลาหมดอายุการใช้งาน (หน้าล็อกอิน, กู้รหัสผ่าน, และหน้าตรวจสอบเอกสารสาธารณะ)
const isBareLayout = computed(() => {
  if (route.meta?.requiresGuest || route.meta?.bare) return true
  return ['login', 'forgot-password', 'reset-password', 'verify-leave'].includes(route.name)
})

const authStore = useAuthStore()
const leaveStore = useLeaveStore()
const settingStore = useSettingStore()
const mobileMenu = ref(false)
const showLogoutModal = ref(false)
const isSidebarCollapsed = ref(false)

const menuStore = useMenuStore()

const currentRouteTitle = computed(() => {
  if (route.meta?.title) return route.meta.title
  return 'ระบบบริหารสถานศึกษา E-la'
})

/*
 * ป้ายตัวเลขบนเมนู — เป็นสถานะ runtime ไม่ใช่การตั้งค่าเมนู จึงอยู่ที่นี่
 * ไม่ได้เก็บในตาราง `menus` อ้างอิงด้วยชื่อ route ของแต่ละเมนู
 */
const menuBadges = computed(() => ({
  approvals: leaveStore.approvals?.filter((l) => l.cancellation_status !== 'Pending').length || 0,
  'cancel-approvals': leaveStore.approvals?.filter((l) => l.cancellation_status === 'Pending').length || 0,
}))

// ปิดลิ้นชักเมนูบนมือถือเมื่อเปลี่ยนหน้า
watch(() => route.path, () => {
  mobileMenu.value = false
})

const IDLE_TIMEOUT_MS = 30 * 60 * 1000; // 30 minutes
const idleTimeLeft = ref(IDLE_TIMEOUT_MS);
let idleTimer = null;
let countdownInterval = null;

const resetIdleTimer = () => {
  if (isBareLayout.value) return;
  idleTimeLeft.value = IDLE_TIMEOUT_MS;
  if (idleTimer) clearTimeout(idleTimer);
  idleTimer = setTimeout(() => {
    doLogout();
  }, IDLE_TIMEOUT_MS);
};

const updateCountdown = () => {
  if (isBareLayout.value) return;
  idleTimeLeft.value = Math.max(0, idleTimeLeft.value - 1000);
};

const formattedIdleTime = computed(() => {
  const totalSeconds = Math.floor(idleTimeLeft.value / 1000);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${minutes}:${seconds.toString().padStart(2, '0')}`;
});

/*
 * โหลดเมนูของผู้ใช้จาก `/api/user/menus` — กรองตามสิทธิ์และโมดูลที่เปิดใช้งานมาแล้ว
 * ต้องโหลดใหม่ทุกครั้งที่มีการเข้าสู่ระบบ ไม่ใช่แค่ตอน mount ครั้งแรก
 * เพราะหน้าเข้าสู่ระบบไม่ได้ unmount แอปทั้งตัว
 */
watch(() => authStore.isAuthenticated, (signedIn) => {
  if (signedIn && !menuStore.loaded) menuStore.fetchUserMenus();
}, { immediate: true })

onMounted(async () => {
  await settingStore.fetchSettings();

  window.addEventListener('mousemove', resetIdleTimer);
  window.addEventListener('keydown', resetIdleTimer);
  window.addEventListener('mousedown', resetIdleTimer);
  window.addEventListener('touchstart', resetIdleTimer);

  resetIdleTimer();
  countdownInterval = setInterval(updateCountdown, 1000);
})

onUnmounted(() => {
  window.removeEventListener('mousemove', resetIdleTimer);
  window.removeEventListener('keydown', resetIdleTimer);
  window.removeEventListener('mousedown', resetIdleTimer);
  window.removeEventListener('touchstart', resetIdleTimer);
  if (idleTimer) clearTimeout(idleTimer);
  if (countdownInterval) clearInterval(countdownInterval);
})


/*
 * ป้ายบทบาทที่แสดงบนหน้าจอ
 *
 * UI-THAI — `roles.name` ในฐานข้อมูลเป็น "ตัวระบุ" ภาษาอังกฤษที่โค้ดฝั่ง server
 * ใช้ตัดสินสิทธิ์ (Super Admin, Document Officer, …) เปลี่ยนไม่ได้
 * เดิมหน้าจอเอาค่านั้นมาแสดงตรง ๆ ผู้ใช้จึงเห็นคำว่า "Document Officer"
 * บนแถบด้านข้าง ทั้งที่ UI ต้องเป็นภาษาไทยทั้งหมด
 *
 * แปลงเป็นชื่อไทยตรงนี้ที่เดียว ค่าที่ไม่รู้จักยังแสดงตามเดิมเพื่อไม่ให้ป้ายหาย
 */
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

// Role badge color
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
.nav-link {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.625rem 0.75rem;
  border-radius: 0.75rem;
  color: rgba(255,255,255,0.7);
  font-size: 0.875rem;
  font-weight: 500;
  transition: background 0.15s, color 0.15s;
  text-decoration: none;
  cursor: pointer;
}
.nav-link:hover {
  background: rgba(255,255,255,0.1);
  color: #fff;
}
.nav-link-active {
  background: rgba(255,255,255,0.2) !important;
  color: #fff !important;
  font-weight: 600;
}

/* หัวข้อคั่นกลุ่มย่อยในเมนูตั้งค่าระบบ */
.settings-group-label {
  padding: 0.5rem 0.75rem 0.125rem;
  font-size: 0.625rem;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: rgba(255,255,255,0.4);
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


