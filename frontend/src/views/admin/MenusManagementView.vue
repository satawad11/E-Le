<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h2 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
          <span class="p-2 bg-red-50 text-[#800000] rounded-xl">
            <svg class="w-6 h-6 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.folder || 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z'"/>
            </svg>
          </span>
          สิทธิ์การเข้าถึงเมนู (Menu Access Permissions)
        </h2>
        <p class="text-gray-500 text-sm mt-1">
          กำหนดว่าเมนูใดแสดงกับใคร — ผู้ใช้จะเห็นเมนูเมื่อมีสิทธิ์อย่างน้อยหนึ่งข้อที่ผูกไว้
          เมนูที่ไม่ผูกสิทธิ์ใดเลยจะเห็นได้ทุกคนที่เข้าสู่ระบบ
        </p>
      </div>
      <button
        type="button"
        :disabled="loading"
        class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold text-slate-700 bg-white border border-slate-300 rounded-xl hover:bg-slate-50 transition shadow-2xs disabled:opacity-50 cursor-pointer"
        @click="loadMenus"
      >
        <svg class="w-4 h-4 flex-shrink-0" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.refresh"/>
        </svg>
        รีเฟรชข้อมูล
      </button>
    </div>

    <!-- Info Banner -->
    <div class="p-4 bg-amber-50/70 border border-amber-200 rounded-2xl flex items-start gap-3 shadow-2xs">
      <svg class="w-5 h-5 text-amber-700 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.info"/>
      </svg>
      <div class="text-sm text-amber-900 leading-relaxed">
        รายการเมนูและลำดับโครงสร้างมาจากระบบกลาง (<code class="bg-amber-100/80 px-1.5 py-0.5 rounded font-mono text-xs font-bold text-amber-950">MenuSeeder</code>)
        หน้านี้ปรับได้สองอย่างคือ <strong>เปิด/ปิดการแสดงผลเมนู</strong> และ <strong>กำหนดสิทธิ์ (Permissions) สำหรับการมองเห็น</strong>
        โดยระบบจะคำนวณบทบาท (Roles) ที่มองเห็นเมนูให้อัตโนมัติ และมีผลทันทีหลังบันทึก
      </div>
    </div>

    <!-- Search filter bar -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs flex flex-col sm:flex-row gap-3 items-center justify-between">
      <div class="w-full sm:w-80 relative">
        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
          <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.search"/>
          </svg>
        </div>
        <input
          v-model="menuSearchQuery"
          type="text"
          placeholder="ค้นหาเมนู หรือเส้นทาง (Route)..."
          class="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none transition"
        />
        <button
          v-if="menuSearchQuery"
          @click="menuSearchQuery = ''"
          class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.x"/>
          </svg>
        </button>
      </div>

      <div class="text-xs text-slate-500 font-medium">
        เมนูทั้งหมด: <span class="font-bold text-slate-800">{{ menus.length }}</span> รายการ (กลุ่มหลัก {{ tree.length }} กลุ่ม)
      </div>
    </div>

    <div v-if="loading" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
      <LoadingState type="skeleton-table" :rows="6" :cols="3" message="กำลังโหลดรายการเมนู..." />
    </div>

    <div v-else-if="loadError" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
      <ErrorState :message="loadError" @retry="loadMenus" />
    </div>

    <!-- Menu tree -->
    <div v-else class="space-y-4">
      <section v-for="group in filteredTree" :key="group.id" class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <!-- Group Header -->
        <header class="px-6 py-4 bg-slate-50/70 border-b border-slate-200 flex flex-wrap items-center gap-3">
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
              <span class="w-2.5 h-2.5 rounded-full bg-[#800000]"></span>
              <h3 class="font-bold text-slate-900 text-base">{{ group.title_th }}</h3>
            </div>
            <code class="text-[11px] text-slate-400 font-mono mt-0.5 block">{{ group.code }} · โมดูล: {{ group.module }}</code>
          </div>
          <div class="flex items-center gap-3">
            <span class="text-xs font-semibold" :class="group.is_active ? 'text-emerald-600' : 'text-slate-400'">
              {{ group.is_active ? 'เปิดใช้งาน' : 'ปิดไว้' }}
            </span>
            <button
              type="button"
              role="switch"
              :aria-checked="group.is_active"
              :aria-label="`เปิดหรือปิดเมนู ${group.title_th}`"
              :disabled="saving === group.id"
              class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors disabled:opacity-50 cursor-pointer"
              :class="group.is_active ? 'bg-emerald-500' : 'bg-slate-300'"
              @click="toggleActive(group)"
            >
              <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow-xs transition-transform" :class="group.is_active ? 'translate-x-6' : 'translate-x-1'"/>
            </button>
          </div>
        </header>

        <!-- Menu items in this group -->
        <ul class="divide-y divide-slate-100">
          <li v-for="item in [group, ...group.children]" :key="item.id" class="px-6 py-4 hover:bg-slate-50/50 transition-colors">
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                  <span v-if="item.id !== group.id" class="text-slate-300 font-bold">└</span>
                  <p class="text-sm font-bold text-slate-900">
                    {{ item.title_th }}
                  </p>
                  <span v-if="item.id === group.id" class="text-[10px] font-semibold text-slate-500 bg-slate-200/70 px-2 py-0.5 rounded-full">
                    หัวข้อกลุ่ม
                  </span>
                </div>
                <code class="text-[11px] text-slate-400 font-mono mt-0.5 block">{{ item.route_path || 'ไม่มีหน้าเฉพาะ (ใช้เป็นกลุ่มย่อย)' }}</code>
              </div>

              <!-- Active Toggle for Sub-item -->
              <div v-if="item.id !== group.id" class="flex items-center gap-3 flex-shrink-0">
                <span class="text-xs font-semibold" :class="item.is_active ? 'text-emerald-600' : 'text-slate-400'">
                  {{ item.is_active ? 'เปิดใช้งาน' : 'ปิดไว้' }}
                </span>
                <button
                  type="button"
                  role="switch"
                  :aria-checked="item.is_active"
                  :aria-label="`เปิดหรือปิดเมนู ${item.title_th}`"
                  :disabled="saving === item.id"
                  class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors disabled:opacity-50 cursor-pointer"
                  :class="item.is_active ? 'bg-emerald-500' : 'bg-slate-300'"
                  @click="toggleActive(item)"
                >
                  <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow-xs transition-transform" :class="item.is_active ? 'translate-x-6' : 'translate-x-1'"/>
                </button>
              </div>
            </div>

            <!-- Permission Chips & Accessible Roles Preview -->
            <div class="mt-3 space-y-2">
              <!-- Required Permissions -->
              <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-[11px] font-semibold text-slate-500 mr-1">สิทธิ์ที่ต้องมี:</span>
                <span v-if="!item.permissions.length" class="text-xs text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 rounded-lg font-medium">
                  ทุกคนที่เข้าสู่ระบบมองเห็นได้ (ไม่มีเงื่อนไขสิทธิ์)
                </span>
                <span
                  v-for="permission in item.permissions"
                  :key="permission.id"
                  class="text-[11px] bg-slate-100 text-slate-800 border border-slate-200 px-2.5 py-1 rounded-lg font-medium flex items-center gap-1.5"
                >
                  <span>{{ permissionLabel(permission.name) }}</span>
                  <code class="text-[10px] text-slate-400 font-mono font-normal">({{ permission.name }})</code>
                </span>
                <button
                  type="button"
                  class="inline-flex items-center gap-1 text-xs font-bold text-[#800000] hover:text-[#600000] hover:underline ml-2 cursor-pointer"
                  @click="openEditor(item)"
                >
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.pencil"/>
                  </svg>
                  แก้ไขสิทธิ์
                </button>
              </div>

              <!-- Accessible Roles Preview Badge -->
              <div class="flex flex-wrap items-center gap-1.5 pt-1">
                <span class="text-[11px] font-semibold text-indigo-700 mr-1 flex items-center gap-1">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.users"/>
                  </svg>
                  บทบาทที่มองเห็นเมนูนี้:
                </span>
                <template v-if="!item.permissions.length">
                  <span class="px-2 py-0.5 text-[11px] font-semibold rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200">
                    ทุกบทบาทในระบบ (All Roles)
                  </span>
                </template>
                <template v-else>
                  <span
                    v-for="roleName in getAccessibleRolesForPermissions(item.permissions)"
                    :key="roleName"
                    class="px-2 py-0.5 text-[11px] font-semibold rounded-full bg-indigo-50 text-indigo-800 border border-indigo-200"
                  >
                    {{ roleLabel(roleName) }}
                  </span>
                </template>
              </div>
            </div>
          </li>
        </ul>
      </section>
    </div>

    <!-- Permission editor Modal -->
    <AppModal
      :model-value="!!editing"
      :title="`กำหนดสิทธิ์การเข้าถึงเมนู “${editing?.title_th}”`"
      @update:model-value="editing = null"
    >
      <div class="space-y-4">
        <p class="text-sm text-gray-600 leading-relaxed">
          เลือกชุดสิทธิ์ (Permissions) ที่ทำให้เห็นเมนูนี้ ผู้ใช้ที่มี <strong>อย่างน้อยหนึ่งข้อ</strong> จะสามารถมองเห็นเมนูได้
          หากไม่เลือกเลย เมนูนี้จะเปิดให้ <strong>ทุกคนที่เข้าสู่ระบบมองเห็นได้</strong>
        </p>

        <!-- Live Preview of Accessible Roles in Modal -->
        <div class="p-3 bg-indigo-50/70 rounded-xl border border-indigo-100 space-y-1.5">
          <div class="text-xs font-bold text-indigo-900 flex items-center gap-1.5">
            <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.users"/>
            </svg>
            พรีวิวบทบาทที่จะมองเห็นเมนูนี้ (Live Role Access Preview):
          </div>
          <div class="flex flex-wrap gap-1.5">
            <span
              v-if="!selectedPermissionIds.length"
              class="px-2.5 py-0.5 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-800"
            >
              ✓ ทุกคนที่เข้าสู่ระบบ (All Roles)
            </span>
            <span
              v-for="roleName in modalAccessibleRoles"
              :key="roleName"
              class="px-2.5 py-0.5 text-xs font-semibold rounded-full bg-indigo-100 text-indigo-800"
            >
              {{ roleLabel(roleName) }}
            </span>
          </div>
        </div>

        <!-- Filter input -->
        <input
          v-model="permissionFilter"
          type="search"
          placeholder="ค้นหาสิทธิ์ตามชื่อหรือรหัส…"
          aria-label="ค้นหาสิทธิ์"
          class="w-full border border-gray-300 rounded-xl text-sm px-3 py-2 focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none transition"
        >

        <!-- Grouped Permissions List -->
        <div class="max-h-72 overflow-y-auto border border-slate-200 rounded-2xl divide-y divide-slate-100 p-2 space-y-3">
          <div v-for="(perms, groupName) in modalGroupedPermissions" :key="groupName" class="space-y-1">
            <div class="flex items-center justify-between px-2 py-1 bg-slate-100 rounded-lg">
              <span class="text-xs font-bold text-slate-800 uppercase tracking-wider">{{ groupName }}</span>
              <button
                type="button"
                @click="toggleModalGroup(perms)"
                class="text-[11px] font-semibold text-[#800000] hover:underline cursor-pointer"
              >
                {{ isModalGroupAllSelected(perms) ? 'ยกเลิกทั้งกลุ่ม' : 'เลือกทั้งกลุ่ม' }}
              </button>
            </div>
            <div class="grid grid-cols-1 gap-1 pl-1">
              <label
                v-for="permission in perms"
                :key="permission.id"
                class="flex items-start gap-2.5 px-2.5 py-2 hover:bg-slate-50 rounded-lg cursor-pointer transition-colors"
              >
                <input
                  v-model="selectedPermissionIds"
                  type="checkbox"
                  :value="permission.id"
                  class="mt-0.5 rounded border-slate-300 text-[#800000] focus:ring-[#800000] cursor-pointer"
                >
                <div>
                  <span class="text-xs font-semibold text-slate-800 block">{{ permissionLabel(permission.name) }}</span>
                  <span class="text-[10px] font-mono text-slate-400 block">{{ permission.name }}</span>
                </div>
              </label>
            </div>
          </div>
          <p v-if="!Object.keys(modalGroupedPermissions).length" class="px-3 py-6 text-sm text-slate-400 text-center">ไม่พบสิทธิ์ที่ค้นหา</p>
        </div>
      </div>

      <template #footer>
        <button type="button" class="px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-800 cursor-pointer" @click="editing = null">ยกเลิก</button>
        <button
          type="button"
          :disabled="saving === editing?.id"
          class="px-5 py-2 rounded-xl bg-[#800000] hover:bg-[#660000] text-white text-sm font-bold shadow-xs disabled:opacity-50 transition cursor-pointer"
          @click="savePermissions"
        >
          {{ saving === editing?.id ? 'กำลังบันทึก…' : 'บันทึกการตั้งค่า' }}
        </button>
      </template>
    </AppModal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { useMenuStore } from '../../stores/menuStore'
import { getErrorMessage } from '../../utils/errorHandler'
import { ICONS } from '../../utils/icons'
import { roleLabel, permissionLabel, groupPermissions } from '../../utils/roleLabel'
import AppModal from '../../components/ui/AppModal.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'

const alerts = useAlertStore()
const menuStore = useMenuStore()

const menus = ref([])
const permissions = ref([])
const roles = ref([])
const loading = ref(false)
const loadError = ref('')
const saving = ref(null)
const editing = ref(null)
const selectedPermissionIds = ref([])
const permissionFilter = ref('')
const menuSearchQuery = ref('')

const tree = computed(() => {
  const roots = menus.value.filter((menu) => !menu.parent_id)
  return roots.map((root) => ({
    ...root,
    children: menus.value.filter((menu) => menu.parent_id === root.id).sort((a, b) => a.sort_order - b.sort_order),
  })).sort((a, b) => a.sort_order - b.sort_order)
})

const filteredTree = computed(() => {
  if (!menuSearchQuery.value) return tree.value
  const q = menuSearchQuery.value.toLowerCase()

  return tree.value.map(group => {
    const groupMatches = group.title_th.toLowerCase().includes(q) || (group.code && group.code.toLowerCase().includes(q))
    const matchedChildren = group.children.filter(child => 
      child.title_th.toLowerCase().includes(q) || 
      (child.route_path && child.route_path.toLowerCase().includes(q)) ||
      (child.code && child.code.toLowerCase().includes(q))
    )
    if (groupMatches || matchedChildren.length > 0) {
      return {
        ...group,
        children: groupMatches ? group.children : matchedChildren,
      }
    }
    return null
  }).filter(Boolean)
})

const modalGroupedPermissions = computed(() => {
  const grouped = groupPermissions(permissions.value)
  if (!permissionFilter.value) return grouped
  const term = permissionFilter.value.trim().toLowerCase()
  const res = {}

  Object.keys(grouped).forEach(gName => {
    const matched = grouped[gName].filter(p => 
      p.name.toLowerCase().includes(term) || 
      permissionLabel(p.name).toLowerCase().includes(term)
    )
    if (matched.length > 0) {
      res[gName] = matched
    }
  })

  return res
})

const isModalGroupAllSelected = (perms) => {
  return perms.every(p => selectedPermissionIds.value.includes(p.id))
}

const toggleModalGroup = (perms) => {
  const allSelected = isModalGroupAllSelected(perms)
  const ids = perms.map(p => p.id)

  if (allSelected) {
    selectedPermissionIds.value = selectedPermissionIds.value.filter(id => !ids.includes(id))
  } else {
    selectedPermissionIds.value = Array.from(new Set([...selectedPermissionIds.value, ...ids]))
  }
}

// Role Access Calculation
const getAccessibleRolesForPermissions = (permList) => {
  if (!permList || !permList.length) return ['ทุกคนที่เข้าสู่ระบบ']
  const permIds = permList.map(p => typeof p === 'object' ? p.id : p)

  const accessible = []
  roles.value.forEach(role => {
    // SuperAdmin or system.menu.manage always has access
    if (role.name === 'SuperAdmin' || role.name === 'Super Admin') {
      accessible.push(role.name)
      return
    }
    const rolePermIds = (role.permissions || []).map(p => p.id)
    const hasAny = permIds.some(id => rolePermIds.includes(id))
    if (hasAny) {
      accessible.push(role.name)
    }
  })

  return accessible
}

const modalAccessibleRoles = computed(() => {
  if (!selectedPermissionIds.value.length) return ['ทุกคนที่เข้าสู่ระบบ']
  return getAccessibleRolesForPermissions(selectedPermissionIds.value)
})

const loadMenus = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/admin/menus')
    menus.value = data.data.menus || []
    permissions.value = data.data.available_permissions || []
    roles.value = data.data.roles || []
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
  selectedPermissionIds.value = (menu.permissions || []).map((permission) => permission.id)
}

const savePermissions = async () => {
  const menu = editing.value
  await persist(menu, { permission_ids: selectedPermissionIds.value }, 'บันทึกสิทธิ์ของเมนูเรียบร้อยแล้ว')
  editing.value = null
}

onMounted(loadMenus)
</script>

