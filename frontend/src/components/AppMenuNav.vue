<template>
  <nav class="space-y-2" :aria-label="ariaLabel">
    <template v-for="item in menus" :key="item.code">
      <!-- กรณีที่ 1: เมนูปลายทางเดี่ยว (ไม่มีเมนูย่อย) -->
      <router-link
        v-if="!item.children?.length"
        :to="item.route_path"
        class="nav-link flex items-center justify-between group"
        :class="collapsed ? 'justify-center px-0' : ''"
        active-class="nav-link-active"
        :title="collapsed ? item.title_th : ''"
        @click="$emit('navigate')"
      >
        <span class="flex items-center gap-2.5 min-w-0">
          <svg class="w-4 h-4 flex-shrink-0 opacity-80 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="iconPath(item.icon)"/>
          </svg>
          <span v-show="!collapsed" class="whitespace-nowrap text-sm">{{ item.title_th }}</span>
        </span>
        <span v-if="!collapsed && badgeFor(item)" class="bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full shadow-sm">
          {{ badgeFor(item) }}
        </span>
      </router-link>

      <!-- กรณีที่ 2: กลุ่มเมนูที่มีเมนูย่อย -->
      <div v-else class="space-y-1">
        <!-- หัวข้อกลุ่ม (แสดงเมื่อมีหลายกลุ่มในโมดูล หรือไม่ได้เปิด singleGroupDirect) -->
        <div v-if="shouldShowGroupHeader(item)" class="pt-2 pb-1">
          <button
            type="button"
            class="nav-group-header w-full flex justify-between items-center text-xs font-semibold uppercase tracking-wider text-white/60 hover:text-white px-2 py-1 rounded-md transition-colors"
            :aria-expanded="isOpen(item.code) ? 'true' : 'false'"
            @click="toggle(item.code)"
          >
            <span class="flex items-center gap-2 truncate">
              <svg class="w-3.5 h-3.5 flex-shrink-0 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="iconPath(item.icon)"/>
              </svg>
              <span>{{ item.title_th }}</span>
            </span>
            <span class="flex items-center gap-1.5 flex-shrink-0">
              <span v-if="groupBadge(item)" class="bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">
                {{ groupBadge(item) }}
              </span>
              <svg
                class="w-3.5 h-3.5 transition-transform duration-200"
                :class="{ 'rotate-180': isOpen(item.code) }"
                fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
              </svg>
            </span>
          </button>
        </div>

        <!-- รายการเมนูย่อย -->
        <div v-show="isOpen(item.code) && !collapsed" class="space-y-0.5" :class="shouldShowGroupHeader(item) ? 'pl-2 border-l border-white/10 ml-2' : ''">
          <router-link
            v-for="child in item.children"
            :key="child.code"
            :to="child.route_path"
            class="nav-link !py-1.5 !px-2.5 !text-xs flex items-center justify-between group"
            active-class="nav-link-active"
            @click="$emit('navigate')"
          >
            <span class="flex items-center gap-2 truncate">
              <span class="w-1.5 h-1.5 rounded-full bg-white/40 group-hover:bg-white flex-shrink-0"></span>
              <span class="truncate">{{ child.title_th }}</span>
            </span>
            <span v-if="badgeFor(child)" class="bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.2 rounded-full flex-shrink-0">
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
 * แถบเมนูย่อยรองรับ Dual-Rail Sub-Panel & Mobile Drawer (CLEANUP-01 · D1 · DUAL-RAIL)
 *
 * ดึงรายการเมนูของโมดูลที่เลือกมาเรนเดอร์อย่างเป็นระเบียบ แบ่งกลุ่มชัดเจน
 * และเปิดค้างไว้ตามค่าเริ่มต้นเพื่อความสะดวกในการเข้าถึง
 */
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ICONS } from '../utils/icons'

const props = defineProps({
  menus: { type: Array, default: () => [] },
  collapsed: { type: Boolean, default: false },
  badges: { type: Object, default: () => ({}) },
  ariaLabel: { type: String, default: 'เมนูย่อยของโมดูล' },
  showSingleHeader: { type: Boolean, default: false },
})

defineEmits(['navigate'])

const route = useRoute()
const openGroups = ref(new Set())

const iconPath = (icon) => ICONS[icon] || (icon === 'settings' ? ICONS.cog : null) || ICONS.document

const badgeFor = (item) => props.badges[item.route_name] || 0

const groupBadge = (group) =>
  (group.children || []).reduce((total, child) => total + badgeFor(child), 0)

const isOpen = (code) => openGroups.value.has(code)

const toggle = (code) => {
  const next = new Set(openGroups.value)
  next.has(code) ? next.delete(code) : next.add(code)
  openGroups.value = next
}

const shouldShowGroupHeader = (_group) => {
  // หากในโมดูลมีมากกว่า 1 กลุ่ม หรือตั้งค่าให้แสดงหัวข้อ ให้แสดง header
  if (props.showSingleHeader) return true
  return props.menus.length > 1
}

// เปิดกลุ่มเมนูทั้งหมดของโมดูลที่เลือกโดยอัตโนมัติ
watch(
  () => [route.path, props.menus],
  () => {
    const next = new Set(openGroups.value)
    props.menus.forEach((group) => {
      if (group.children?.length) {
        next.add(group.code)
      }
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
  padding: 0.5rem 0.625rem;
  border-radius: 0.5rem;
  color: rgba(255, 255, 255, 0.75);
  font-size: 0.8125rem;
  font-weight: 500;
  transition: all 0.15s ease;
  text-decoration: none;
  cursor: pointer;
}
.nav-link:hover {
  background: rgba(255, 255, 255, 0.12);
  color: #ffffff;
  transform: translateX(2px);
}
.nav-link-active {
  background: rgba(255, 255, 255, 0.22) !important;
  color: #ffffff !important;
  font-weight: 600;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}
</style>
