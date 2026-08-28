<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h2 class="text-2xl font-bold text-gray-800">จัดการเมนูตามสิทธิ์</h2>
        <p class="text-gray-500 text-sm mt-1">
          กำหนดว่าเมนูใดแสดงกับใคร — ผู้ใช้จะเห็นเมนูเมื่อมีสิทธิ์อย่างน้อยหนึ่งข้อที่ผูกไว้
          เมนูที่ไม่ผูกสิทธิ์ใดเลยจะเห็นได้ทุกคนที่เข้าสู่ระบบ
        </p>
      </div>
      <button
        type="button"
        :disabled="loading"
        class="flex items-center gap-2 px-4 py-2.5 text-sm font-semibold text-slate-600 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition shadow-sm disabled:opacity-50"
        @click="loadMenus"
      >
        <svg class="w-4 h-4" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        รีเฟรชข้อมูล
      </button>
    </div>

    <div class="p-4 bg-blue-50 border border-blue-200 rounded-xl flex items-start gap-3">
      <svg class="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.info"/>
      </svg>
      <div class="text-sm text-blue-800">
        รายการเมนูและลำดับมาจากโครงเมนูของระบบ (<code class="bg-blue-100 px-1 rounded font-mono text-xs">MenuSeeder</code>)
        หน้านี้ปรับได้สองอย่างคือ <strong>เปิด/ปิดเมนู</strong> และ <strong>สิทธิ์ที่ใช้มองเห็นเมนู</strong>
        การเปลี่ยนแปลงมีผลกับผู้ใช้ทุกคนทันทีหลังบันทึก
      </div>
    </div>

    <div v-if="loading" class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
      <LoadingState type="skeleton-table" :rows="6" :cols="3" message="กำลังโหลดรายการเมนู..." />
    </div>

    <div v-else-if="loadError" class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
      <ErrorState :message="loadError" @retry="loadMenus" />
    </div>

    <!-- Menu tree -->
    <div v-else class="space-y-4">
      <section v-for="group in tree" :key="group.id" class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center gap-3">
          <div class="min-w-0">
            <h3 class="font-bold text-slate-900">{{ group.title_th }}</h3>
            <code class="text-[11px] text-slate-400 font-mono">{{ group.code }} · โมดูล {{ group.module }}</code>
          </div>
          <div class="ml-auto flex items-center gap-3">
            <span class="text-xs" :class="group.is_active ? 'text-emerald-600' : 'text-slate-400'">
              {{ group.is_active ? 'เปิดใช้งาน' : 'ปิดไว้' }}
            </span>
            <button
              type="button"
              role="switch"
              :aria-checked="group.is_active"
              :aria-label="`เปิดหรือปิดเมนู ${group.title_th}`"
              :disabled="saving === group.id"
              class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors disabled:opacity-50"
              :class="group.is_active ? 'bg-emerald-500' : 'bg-slate-300'"
              @click="toggleActive(group)"
            >
              <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform" :class="group.is_active ? 'translate-x-6' : 'translate-x-1'"/>
            </button>
          </div>
        </header>

        <ul class="divide-y divide-slate-100">
          <li v-for="item in [group, ...group.children]" :key="item.id" class="px-5 py-4">
            <div class="flex flex-wrap items-start gap-3">
              <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-slate-800">
                  <span v-if="item.id !== group.id" class="text-slate-300 mr-1">└</span>
                  {{ item.title_th }}
                  <span v-if="item.id === group.id" class="text-xs font-normal text-slate-400">(หัวข้อกลุ่ม)</span>
                </p>
                <code class="text-[11px] text-slate-400 font-mono">{{ item.route_path || 'ไม่มีหน้าเป็นของตัวเอง' }}</code>
              </div>

              <div v-if="item.id !== group.id" class="flex items-center gap-3">
                <span class="text-xs" :class="item.is_active ? 'text-emerald-600' : 'text-slate-400'">
                  {{ item.is_active ? 'เปิดใช้งาน' : 'ปิดไว้' }}
                </span>
                <button
                  type="button"
                  role="switch"
                  :aria-checked="item.is_active"
                  :aria-label="`เปิดหรือปิดเมนู ${item.title_th}`"
                  :disabled="saving === item.id"
                  class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors disabled:opacity-50"
                  :class="item.is_active ? 'bg-emerald-500' : 'bg-slate-300'"
                  @click="toggleActive(item)"
                >
                  <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform" :class="item.is_active ? 'translate-x-6' : 'translate-x-1'"/>
                </button>
              </div>
            </div>

            <!-- Permission chips -->
            <div class="mt-3 flex flex-wrap items-center gap-2">
              <span v-if="!item.permissions.length" class="text-xs text-slate-500 bg-slate-100 px-2 py-1 rounded-lg">
                ทุกคนที่เข้าสู่ระบบเห็นเมนูนี้
              </span>
              <span
                v-for="permission in item.permissions"
                :key="permission.id"
                class="text-[11px] font-mono bg-indigo-50 text-indigo-700 border border-indigo-100 px-2 py-1 rounded-lg"
              >
                {{ permission.name }}
              </span>
              <button
                type="button"
                class="text-xs font-semibold text-[#800000] hover:underline"
                @click="openEditor(item)"
              >
                แก้ไขสิทธิ์
              </button>
            </div>
          </li>
        </ul>
      </section>
    </div>

    <!-- Permission editor -->
    <AppModal
      :model-value="!!editing"
      :title="`สิทธิ์ที่มองเห็นเมนู “${editing?.title_th}”`"
      @update:model-value="editing = null"
    >
      <p class="text-sm text-gray-500 mb-4">
        เลือกสิทธิ์ที่ทำให้เห็นเมนูนี้ ผู้ใช้ที่มี <strong>อย่างน้อยหนึ่งข้อ</strong> จะเห็นเมนู
        ถ้าไม่เลือกเลย เมนูนี้จะเห็นได้ทุกคนที่เข้าสู่ระบบ
      </p>
      <input
        v-model="permissionFilter"
        type="search"
        placeholder="ค้นหาสิทธิ์…"
        aria-label="ค้นหาสิทธิ์"
        class="w-full border-gray-300 rounded-lg text-sm mb-3 focus:ring-[#800000] focus:border-[#800000]"
      >
      <div class="max-h-72 overflow-y-auto border border-slate-100 rounded-xl divide-y divide-slate-50">
        <label
          v-for="permission in filteredPermissions"
          :key="permission.id"
          class="flex items-center gap-3 px-3 py-2 hover:bg-slate-50 cursor-pointer"
        >
          <input v-model="selectedPermissionIds" type="checkbox" :value="permission.id" class="rounded text-[#800000] focus:ring-[#800000]">
          <span class="text-sm font-mono text-slate-700">{{ permission.name }}</span>
        </label>
        <p v-if="!filteredPermissions.length" class="px-3 py-4 text-sm text-slate-400 text-center">ไม่พบสิทธิ์ที่ค้นหา</p>
      </div>

      <template #footer>
        <button type="button" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-800" @click="editing = null">ยกเลิก</button>
        <button
          type="button"
          :disabled="saving === editing?.id"
          class="px-5 py-2 rounded-xl bg-[#800000] hover:bg-[#660000] text-white text-sm font-semibold disabled:opacity-50"
          @click="savePermissions"
        >
          {{ saving === editing?.id ? 'กำลังบันทึก…' : 'บันทึก' }}
        </button>
      </template>
    </AppModal>
  </div>
</template>

<script setup>
/**
 * CLEANUP-01 (D1) — หน้าจัดการเมนูที่จัดการเมนูได้จริง
 *
 * เดิมหน้านี้ชื่อ "จัดการเมนูตามสิทธิ์" แต่เรียกแค่ `/admin/permissions/modules`
 * และ `/admin/permissions/user-counts` — เป็นหน้าสรุปสิทธิ์รายโมดูล ไม่ได้แตะเมนูเลย
 * ขณะที่แถบเมนูจริงก็เขียนตายตัวอยู่ใน App.vue การตั้งค่าที่นี่จึงไม่มีผลกับใคร
 *
 * ตอนนี้แถบเมนูอ่านจากตาราง `menus` แล้ว หน้านี้จึงแก้ได้จริงสองอย่าง:
 * เปิด/ปิดเมนู และสิทธิ์ที่ใช้มองเห็นเมนู
 * ส่วนรายการเมนูและลำดับยังมาจาก `MenuSeeder` เพื่อให้มีแหล่งความจริงเดียว
 */
import { computed, onMounted, ref } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { useMenuStore } from '../../stores/menuStore'
import { getErrorMessage } from '../../utils/errorHandler'
import { ICONS } from '../../utils/icons'
import AppModal from '../../components/ui/AppModal.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'

const alerts = useAlertStore()
const menuStore = useMenuStore()

const menus = ref([])
const permissions = ref([])
const loading = ref(false)
const loadError = ref('')
const saving = ref(null)
const editing = ref(null)
const selectedPermissionIds = ref([])
const permissionFilter = ref('')

const tree = computed(() => {
  const roots = menus.value.filter((menu) => !menu.parent_id)
  return roots.map((root) => ({
    ...root,
    children: menus.value.filter((menu) => menu.parent_id === root.id).sort((a, b) => a.sort_order - b.sort_order),
  })).sort((a, b) => a.sort_order - b.sort_order)
})

const filteredPermissions = computed(() => {
  const term = permissionFilter.value.trim().toLowerCase()
  if (!term) return permissions.value
  return permissions.value.filter((permission) => permission.name.toLowerCase().includes(term))
})

const loadMenus = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/admin/menus')
    menus.value = data.data.menus
    permissions.value = data.data.available_permissions
  } catch (error) {
    loadError.value = getErrorMessage(error, 'ไม่สามารถโหลดรายการเมนูได้')
  } finally {
    loading.value = false
  }
}

const persist = async (menu, payload, successMessage) => {
  saving.value = menu.id
  try {
    const { data } = await api.put(`/admin/menus/${menu.id}/permissions`, payload)
    Object.assign(menu, data.data)
    alerts.success(successMessage)
    // แถบเมนูของผู้ดูแลเองต้องสะท้อนการเปลี่ยนแปลงทันที ไม่ต้องรอรีเฟรชหน้า
    await menuStore.fetchUserMenus()
  } catch (error) {
    alerts.error(getErrorMessage(error, 'บันทึกไม่สำเร็จ'))
    await loadMenus()
  } finally {
    saving.value = null
  }
}

const toggleActive = (menu) =>
  persist(
    menu,
    { is_active: !menu.is_active },
    !menu.is_active ? 'เปิดใช้งานเมนูแล้ว' : 'ปิดเมนูแล้ว',
  )

const openEditor = (menu) => {
  editing.value = menu
  permissionFilter.value = ''
  selectedPermissionIds.value = menu.permissions.map((permission) => permission.id)
}

const savePermissions = async () => {
  const menu = editing.value
  await persist(menu, { permission_ids: selectedPermissionIds.value }, 'บันทึกสิทธิ์ของเมนูเรียบร้อยแล้ว')
  editing.value = null
}

onMounted(loadMenus)
</script>
