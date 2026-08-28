<template>
  <nav class="space-y-1" :aria-label="ariaLabel">
    <template v-for="item in menus" :key="item.code">
      <!-- เมนูปลายทาง (ไม่มีเมนูย่อย) -->
      <router-link
        v-if="!item.children?.length"
        :to="item.route_path"
        class="nav-link flex items-center justify-between"
        :class="collapsed ? 'justify-center px-0' : ''"
        active-class="nav-link-active"
        :title="collapsed ? item.title_th : ''"
        @click="$emit('navigate')"
      >
        <span class="flex items-center gap-3 min-w-0">
          <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="iconPath(item.icon)"/>
          </svg>
          <span v-show="!collapsed" class="whitespace-nowrap">{{ item.title_th }}</span>
        </span>
        <span v-if="!collapsed && badgeFor(item)" class="bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">
          {{ badgeFor(item) }}
        </span>
      </router-link>

      <!-- กลุ่มเมนูที่พับได้ -->
      <div v-else>
        <button
          type="button"
          class="nav-link w-full flex justify-between items-center"
          :class="[isGroupActive(item) ? 'bg-white/10 text-white' : '', collapsed ? 'justify-center px-0' : '']"
          :title="collapsed ? item.title_th : ''"
          :aria-expanded="isOpen(item.code) ? 'true' : 'false'"
          @click="toggle(item.code)"
        >
          <span class="flex items-center gap-3 min-w-0">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="iconPath(item.icon)"/>
            </svg>
            <span v-show="!collapsed" class="whitespace-nowrap">{{ item.title_th }}</span>
          </span>
          <span v-if="!collapsed" class="flex items-center gap-2">
            <span v-if="groupBadge(item)" class="bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">
              {{ groupBadge(item) }}
            </span>
            <svg
              class="w-4 h-4 transition-transform duration-200 flex-shrink-0"
              :class="{ 'rotate-180': isOpen(item.code) }"
              fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
          </span>
        </button>

        <div v-show="isOpen(item.code) && !collapsed" class="mt-1 space-y-1 pl-9">
          <router-link
            v-for="child in item.children"
            :key="child.code"
            :to="child.route_path"
            class="nav-link !py-2 !text-sm flex items-center justify-between"
            active-class="nav-link-active"
            @click="$emit('navigate')"
          >
            <span>{{ child.title_th }}</span>
            <span v-if="badgeFor(child)" class="bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">
              {{ badgeFor(child) }}
            </span>
          </router-link>
        </div>
      </div>
    </template>
  </nav>
</template>

<script setup>
/**
 * แถบเมนูที่วาดจากตาราง `menus` (CLEANUP-01 · D1)
 *
 * ก่อนหน้านี้เมนูถูกเขียนตายตัวใน `App.vue` สองก๊อป (จอใหญ่กับจอมือถือ) ต้องแก้คู่กัน
 * ทุกครั้งและเงื่อนไขสิทธิ์ก็ถูกคัดลอกซ้ำ ตอนนี้ทั้งสองจอใช้ component เดียวกัน
 * และรายการเมนูมาจาก `/api/user/menus` ซึ่งกรองตามสิทธิ์และโมดูลที่เปิดใช้งานให้แล้ว
 *
 * ป้ายตัวเลข (badge) เป็นสถานะ runtime ไม่ใช่การตั้งค่าเมนู จึงส่งเข้ามาทาง prop
 * โดยอ้างชื่อ route ไม่ใช่เก็บไว้ในฐานข้อมูล
 */
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ICONS } from '../utils/icons'

const props = defineProps({
  menus: { type: Array, default: () => [] },
  collapsed: { type: Boolean, default: false },
  badges: { type: Object, default: () => ({}) },
  ariaLabel: { type: String, default: 'เมนูหลัก' },
})

defineEmits(['navigate'])

const route = useRoute()
const openGroups = ref(new Set())

const iconPath = (icon) => ICONS[icon] || ICONS.document

const badgeFor = (item) => props.badges[item.route_name] || 0

const groupBadge = (group) =>
  (group.children || []).reduce((total, child) => total + badgeFor(child), 0)

const isGroupActive = (group) =>
  (group.children || []).some((child) => route.path === child.route_path || route.path.startsWith(`${child.route_path}/`))

const isOpen = (code) => openGroups.value.has(code)

const toggle = (code) => {
  const next = new Set(openGroups.value)
  next.has(code) ? next.delete(code) : next.add(code)
  openGroups.value = next
}

// เปิดกลุ่มที่มีหน้าปัจจุบันอยู่ข้างในให้อัตโนมัติ
watch(
  () => [route.path, props.menus],
  () => {
    const next = new Set(openGroups.value)
    props.menus.forEach((group) => {
      if (group.children?.length && isGroupActive(group)) next.add(group.code)
    })
    openGroups.value = next
  },
  { immediate: true, deep: true },
)
</script>

<style scoped>
.nav-link {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.625rem 0.75rem;
  border-radius: 0.75rem;
  color: rgba(255, 255, 255, 0.7);
  font-size: 0.875rem;
  font-weight: 500;
  transition: background 0.15s, color 0.15s;
  text-decoration: none;
  cursor: pointer;
}
.nav-link:hover {
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
}
.nav-link-active {
  background: rgba(255, 255, 255, 0.2) !important;
  color: #fff !important;
  font-weight: 600;
}
</style>
