<template>
  <div class="space-y-6">
    <div class="flex flex-wrap justify-between items-center gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">โครงสร้างหน่วยงานและหัวหน้าหน่วยงาน</h1>
        <p class="text-sm text-gray-500 mt-1">
          จัดฝ่าย งาน และแผนกวิชาตามระเบียบการบริหารสถานศึกษา และบันทึกหัวหน้าหน่วยงานตามคำสั่งแต่งตั้ง
          หัวหน้าหน่วยงานคือผู้ที่ระบบใช้หาผู้อนุมัติใบลา
        </p>
      </div>
      <button type="button" class="bg-[#10b981] hover:bg-[#059669] text-white px-4 py-2 rounded-md font-medium text-sm disabled:opacity-50" :disabled="!root" @click="openUnitModal(null, root)">
        เพิ่มฝ่าย
      </button>
    </div>

    <div v-if="loading" class="bg-white shadow rounded-lg p-6">
      <LoadingState type="skeleton-table" :rows="5" :cols="4" message="กำลังโหลดโครงสร้างหน่วยงาน..." />
    </div>

    <div v-else-if="loadError" class="bg-white shadow rounded-lg p-6">
      <ErrorState :message="loadError" @retry="loadData" />
    </div>

    <div v-else class="bg-white shadow rounded-lg overflow-hidden">
      <div class="flex flex-wrap items-center gap-3 px-4 py-3 bg-gray-50 border-b border-gray-200 text-sm">
        <label class="flex items-center gap-2 text-gray-600">
          <input v-model="showInactive" type="checkbox">
          แสดงหน่วยงานที่ปิดใช้งาน
        </label>
        <span class="text-gray-400">·</span>
        <span class="text-gray-500">แผนกที่เพิ่มจากหน้า "จัดการแผนก" จะอยู่ใต้ระดับสถานศึกษา ย้ายไปไว้ใต้ฝ่ายได้ด้วยปุ่ม "แก้ไข"</span>
      </div>

      <ul class="divide-y divide-gray-100">
        <li v-for="row in rows" :key="row.unit.uuid" class="px-4 py-3" :class="row.unit.is_active ? '' : 'bg-gray-50 text-gray-400'">
          <div class="flex flex-wrap items-start justify-between gap-3" :style="{ paddingLeft: `${row.depth * 1.5}rem` }">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium text-gray-800">{{ row.unit.name }}</span>
                <span class="text-xs rounded-full px-2 py-0.5" :class="typeClass(row.unit.type)">{{ typeLabel(row.unit.type) }}</span>
                <span v-if="!row.unit.is_active" class="text-xs rounded-full bg-gray-200 text-gray-600 px-2 py-0.5">ปิดใช้งาน</span>
              </div>
              <div class="mt-1 flex flex-wrap gap-2">
                <span v-if="row.unit.managers.length === 0" class="text-xs text-amber-700">ยังไม่มีหัวหน้าหน่วยงาน</span>
                <span
                  v-for="m in row.unit.managers"
                  :key="m.uuid"
                  class="inline-flex items-center gap-1 text-xs rounded border px-2 py-0.5"
                  :class="m.in_effect ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-gray-200 bg-gray-50 text-gray-500'"
                >
                  {{ assignmentLabel(m.assignment_type) }}: {{ m.user?.name || '-' }}
                  <span class="text-gray-400">({{ m.effective_from }}{{ m.effective_to ? ` – ${m.effective_to}` : '' }}{{ m.in_effect ? '' : ' · ยังไม่ถึงวันมีผล' }})</span>
                  <button v-if="canManage" type="button" class="ml-1 text-red-600 hover:underline" @click="openEndModal(m, row.unit)">ปิดวาระ</button>
                </span>
              </div>
            </div>
            <div v-if="canManage" class="flex flex-wrap gap-2 text-sm">
              <button v-if="row.unit.is_active" type="button" class="text-emerald-700 hover:underline" @click="openManagerModal(row.unit)">ตั้งหัวหน้า</button>
              <button v-if="row.unit.is_active && canHaveChildren(row.unit)" type="button" class="text-blue-600 hover:underline" @click="openUnitModal(null, row.unit)">เพิ่มงาน/หน่วยย่อย</button>
              <button v-if="row.unit.parent_id" type="button" class="text-gray-700 hover:underline" @click="openUnitModal(row.unit)">แก้ไข</button>
              <button type="button" class="text-gray-500 hover:underline" @click="openHistory(row.unit)">ประวัติ</button>
            </div>
          </div>
        </li>
      </ul>
    </div>

    <!-- เพิ่ม/แก้ไขหน่วยงาน -->
    <AppModal v-model="unitModal.show" :title="unitModal.uuid ? 'แก้ไขหน่วยงาน' : 'เพิ่มหน่วยงาน'">
      <form id="unit-form" class="space-y-4" @submit.prevent="saveUnit">
        <div>
          <label for="unit-name" class="block text-sm font-medium text-gray-700 mb-1">ชื่อหน่วยงาน</label>
          <input id="unit-name" v-model="unitModal.name" type="text" required maxlength="255" :disabled="unitModal.linked" class="w-full border rounded px-3 py-2 text-sm disabled:bg-gray-100" placeholder="เช่น ฝ่ายบริหารทรัพยากร / งานบริหารและพัฒนาทรัพยากรบุคคล">
          <p v-if="unitModal.linked" class="text-xs text-gray-500 mt-1">หน่วยงานนี้ผูกกับแผนก แก้ชื่อที่หน้าจัดการแผนก</p>
        </div>
        <div v-if="!unitModal.uuid">
          <label for="unit-type" class="block text-sm font-medium text-gray-700 mb-1">ชนิด</label>
          <select id="unit-type" v-model="unitModal.type" class="w-full border rounded px-3 py-2 text-sm">
            <option value="division">ฝ่าย</option>
            <option value="section">งาน</option>
          </select>
        </div>
        <div>
          <label for="unit-parent" class="block text-sm font-medium text-gray-700 mb-1">อยู่ภายใต้</label>
          <select id="unit-parent" v-model="unitModal.parent_uuid" required class="w-full border rounded px-3 py-2 text-sm">
            <option v-for="option in parentOptions" :key="option.uuid" :value="option.uuid">{{ option.label }}</option>
          </select>
        </div>
        <label v-if="unitModal.uuid" class="flex items-center gap-2 text-sm text-gray-700">
          <input v-model="unitModal.is_active" type="checkbox">
          ใช้งาน (ปิดได้เมื่อไม่มีหน่วยย่อย บุคลากร หัวหน้าที่ยังมีผล และไม่ถูกใช้ในลำดับการอนุมัติ)
        </label>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2">
          <button type="button" class="px-4 py-2 rounded border border-gray-300 text-sm" @click="unitModal.show = false">ยกเลิก</button>
          <button type="submit" form="unit-form" :disabled="saving" class="px-4 py-2 rounded bg-[#800000] hover:bg-[#660000] text-white text-sm disabled:opacity-50">บันทึก</button>
        </div>
      </template>
    </AppModal>

    <!-- ตั้งหัวหน้าหน่วยงาน -->
    <AppModal v-model="managerModal.show" :title="`ตั้งหัวหน้า: ${managerModal.unitName}`" size="lg">
      <form id="manager-form" class="grid grid-cols-1 sm:grid-cols-2 gap-4" @submit.prevent="saveManager">
        <div class="sm:col-span-2">
          <label for="manager-user" class="block text-sm font-medium text-gray-700 mb-1">บุคลากร</label>
          <select id="manager-user" v-model="managerModal.user_uuid" required class="w-full border rounded px-3 py-2 text-sm">
            <option value="" disabled>เลือกบุคลากร</option>
            <option v-for="u in users" :key="u.uuid" :value="u.uuid">{{ u.full_name_with_title || u.name }}</option>
          </select>
        </div>
        <div>
          <label for="manager-type" class="block text-sm font-medium text-gray-700 mb-1">ฐานะ</label>
          <select id="manager-type" v-model="managerModal.assignment_type" class="w-full border rounded px-3 py-2 text-sm">
            <option value="MANAGER">หัวหน้า (ตามคำสั่งแต่งตั้ง)</option>
            <option value="ACTING">รักษาการ</option>
            <option value="DELEGATE">รับมอบอำนาจเฉพาะช่วง</option>
          </select>
        </div>
        <div>
          <label for="manager-scope" class="block text-sm font-medium text-gray-700 mb-1">ขอบเขต</label>
          <select id="manager-scope" v-model="managerModal.scope" class="w-full border rounded px-3 py-2 text-sm">
            <option value="UNIT">เฉพาะหน่วยงานนี้</option>
            <option value="DESCENDANTS">หน่วยงานนี้และหน่วยย่อยทั้งหมด</option>
          </select>
        </div>
        <div>
          <label for="manager-order-no" class="block text-sm font-medium text-gray-700 mb-1">เลขที่คำสั่ง</label>
          <input id="manager-order-no" v-model="managerModal.order_number" type="text" required maxlength="100" class="w-full border rounded px-3 py-2 text-sm" placeholder="เช่น 123/2569">
        </div>
        <div>
          <label for="manager-order-date" class="block text-sm font-medium text-gray-700 mb-1">ลงวันที่</label>
          <input id="manager-order-date" v-model="managerModal.order_date" type="date" required class="w-full border rounded px-3 py-2 text-sm">
        </div>
        <div>
          <label for="manager-from" class="block text-sm font-medium text-gray-700 mb-1">มีผลตั้งแต่</label>
          <input id="manager-from" v-model="managerModal.effective_from" type="date" required class="w-full border rounded px-3 py-2 text-sm">
        </div>
        <div>
          <label for="manager-to" class="block text-sm font-medium text-gray-700 mb-1">ถึง (เว้นว่าง = ไม่กำหนด)</label>
          <input id="manager-to" v-model="managerModal.effective_to" type="date" class="w-full border rounded px-3 py-2 text-sm">
        </div>
        <div class="sm:col-span-2">
          <label for="manager-note" class="block text-sm font-medium text-gray-700 mb-1">หมายเหตุ</label>
          <input id="manager-note" v-model="managerModal.note" type="text" maxlength="255" class="w-full border rounded px-3 py-2 text-sm">
        </div>
        <p class="sm:col-span-2 text-xs text-gray-500">
          การตั้งหัวหน้าทำให้ผู้นั้นอนุมัติใบลาของบุคลากรในหน่วยงานตามลำดับการอนุมัติ และเห็นข้อมูลตามขอบเขตที่เลือก
          หน่วยงานหนึ่งมีหัวหน้าได้มากกว่าหนึ่งคน และหนึ่งคนเป็นหัวหน้าได้หลายหน่วยงาน
        </p>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2">
          <button type="button" class="px-4 py-2 rounded border border-gray-300 text-sm" @click="managerModal.show = false">ยกเลิก</button>
          <button type="submit" form="manager-form" :disabled="saving" class="px-4 py-2 rounded bg-[#800000] hover:bg-[#660000] text-white text-sm disabled:opacity-50">บันทึก</button>
        </div>
      </template>
    </AppModal>

    <!-- ปิดวาระ -->
    <AppModal v-model="endModal.show" :title="`ปิดวาระ: ${endModal.label}`" size="sm">
      <form id="end-form" class="space-y-4" @submit.prevent="saveEnd">
        <div>
          <label for="end-date" class="block text-sm font-medium text-gray-700 mb-1">วันสุดท้ายที่มีผล</label>
          <input id="end-date" v-model="endModal.effective_to" type="date" required class="w-full border rounded px-3 py-2 text-sm">
        </div>
        <div>
          <label for="end-note" class="block text-sm font-medium text-gray-700 mb-1">เหตุผล / คำสั่งที่ให้พ้นหน้าที่</label>
          <input id="end-note" v-model="endModal.note" type="text" maxlength="255" class="w-full border rounded px-3 py-2 text-sm">
        </div>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2">
          <button type="button" class="px-4 py-2 rounded border border-gray-300 text-sm" @click="endModal.show = false">ยกเลิก</button>
          <button type="submit" form="end-form" :disabled="saving" class="px-4 py-2 rounded bg-red-600 hover:bg-red-700 text-white text-sm disabled:opacity-50">ปิดวาระ</button>
        </div>
      </template>
    </AppModal>

    <!-- ประวัติหัวหน้า -->
    <AppModal v-model="history.show" :title="`ประวัติหัวหน้า: ${history.unitName}`" size="lg">
      <LoadingState v-if="history.loading" type="skeleton-table" :rows="3" :cols="4" message="กำลังโหลด..." />
      <p v-else-if="history.items.length === 0" class="text-sm text-gray-500">ยังไม่มีประวัติ</p>
      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm divide-y divide-gray-200">
          <thead class="bg-gray-100 text-gray-600">
            <tr>
              <th scope="col" class="px-3 py-2 text-left font-medium">บุคลากร</th>
              <th scope="col" class="px-3 py-2 text-left font-medium">ฐานะ</th>
              <th scope="col" class="px-3 py-2 text-left font-medium">คำสั่ง</th>
              <th scope="col" class="px-3 py-2 text-left font-medium">ช่วงเวลา</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="m in history.items" :key="m.uuid">
              <td class="px-3 py-2">{{ m.user?.name || '-' }}</td>
              <td class="px-3 py-2">{{ assignmentLabel(m.assignment_type) }} · {{ m.scope === 'DESCENDANTS' ? 'รวมหน่วยย่อย' : 'เฉพาะหน่วย' }}</td>
              <td class="px-3 py-2">{{ m.order_number || '-' }}{{ m.order_date ? ` ลว. ${m.order_date}` : '' }}</td>
              <td class="px-3 py-2">{{ m.effective_from }} – {{ m.effective_to || 'ปัจจุบัน' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppModal>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { useAuthStore } from '../../stores/auth'
import { getErrorMessage } from '../../utils/errorHandler'
import AppModal from '../../components/ui/AppModal.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'

const alertStore = useAlertStore()
const authStore = useAuthStore()
const units = ref([])
const users = ref([])
const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const showInactive = ref(false)

const canManage = computed(() => authStore.isAdmin || authStore.hasPermission('system.org_structure.manage'))
const root = computed(() => units.value.find(u => u.parent_id === null) || null)

const TYPE_LABELS = { organization: 'สถานศึกษา', division: 'ฝ่าย', section: 'งาน', department: 'แผนก' }
const typeLabel = type => TYPE_LABELS[type] || type
const typeClass = type => ({
  organization: 'bg-purple-100 text-purple-800',
  division: 'bg-blue-100 text-blue-800',
  section: 'bg-amber-100 text-amber-800',
  department: 'bg-emerald-100 text-emerald-800',
}[type] || 'bg-gray-100 text-gray-700')
const assignmentLabel = type => ({ MANAGER: 'หัวหน้า', ACTING: 'รักษาการ', DELEGATE: 'รับมอบ' }[type] || type)
const canHaveChildren = unit => ['organization', 'division'].includes(unit.type)

// เรียงเป็นต้นไม้แบบแบน (ไม่ใช้ component ซ้อน) — หน่วยงานที่ปิดแล้วซ่อนตามตัวเลือก
const rows = computed(() => {
  const byParent = new Map()
  for (const unit of units.value) {
    if (!showInactive.value && !unit.is_active) continue
    const key = unit.parent_id ?? 'root'
    if (!byParent.has(key)) byParent.set(key, [])
    byParent.get(key).push(unit)
  }
  const order = { division: 0, section: 1, department: 2 }
  const out = []
  const walk = (key, depth) => {
    const children = (byParent.get(key) || []).slice().sort((a, b) => (order[a.type] ?? 9) - (order[b.type] ?? 9) || a.name.localeCompare(b.name, 'th'))
    for (const child of children) {
      out.push({ unit: child, depth })
      walk(child.id, depth + 1)
    }
  }
  walk('root', 0)
  return out
})

const unitModal = reactive({ show: false, uuid: null, name: '', type: 'division', parent_uuid: '', is_active: true, linked: false, selfId: null })

// หน่วยแม่ที่เลือกได้: สถานศึกษาและฝ่ายที่ยังใช้งาน ไม่รวมตัวเอง
const parentOptions = computed(() => units.value
  .filter(u => u.is_active && canHaveChildren(u) && u.id !== unitModal.selfId)
  .map(u => ({ uuid: u.uuid, label: u.parent_id === null ? `${u.name} (ระดับสถานศึกษา)` : u.name })))

const openUnitModal = (unit = null, parent = null) => {
  const parentUnit = unit ? units.value.find(u => u.id === unit.parent_id) : parent
  Object.assign(unitModal, {
    show: true,
    uuid: unit?.uuid ?? null,
    name: unit?.name ?? '',
    type: unit ? unit.type : (parent?.parent_id === null ? 'division' : 'section'),
    parent_uuid: parentUnit?.uuid ?? root.value?.uuid ?? '',
    is_active: unit?.is_active ?? true,
    linked: unit?.linked_department ?? false,
    selfId: unit?.id ?? null,
  })
}

const saveUnit = async () => {
  saving.value = true
  try {
    if (unitModal.uuid) {
      const payload = { parent_uuid: unitModal.parent_uuid, is_active: unitModal.is_active }
      if (!unitModal.linked) payload.name = unitModal.name
      await api.put(`/admin/org-units/${unitModal.uuid}`, payload)
    } else {
      await api.post('/admin/org-units', { name: unitModal.name, type: unitModal.type, parent_uuid: unitModal.parent_uuid })
    }
    alertStore.success('บันทึกหน่วยงานเรียบร้อยแล้ว')
    unitModal.show = false
    await loadData()
  } catch (e) {
    alertStore.error(getErrorMessage(e, 'บันทึกหน่วยงานไม่สำเร็จ'))
  } finally {
    saving.value = false
  }
}

const today = () => new Date().toISOString().slice(0, 10)
const managerModal = reactive({ show: false, unitUuid: '', unitName: '', user_uuid: '', assignment_type: 'MANAGER', scope: 'UNIT', order_number: '', order_date: '', effective_from: '', effective_to: '', note: '' })

const openManagerModal = async unit => {
  Object.assign(managerModal, {
    show: true,
    unitUuid: unit.uuid,
    unitName: unit.name,
    user_uuid: '',
    assignment_type: 'MANAGER',
    // หัวหน้าระดับสถานศึกษาและฝ่ายดูแลทั้งสาย หัวหน้างาน/แผนกดูเฉพาะหน่วย
    scope: canHaveChildren(unit) ? 'DESCENDANTS' : 'UNIT',
    order_number: '',
    order_date: today(),
    effective_from: today(),
    effective_to: '',
    note: '',
  })
  if (users.value.length === 0) {
    try {
      const res = await api.get('/users/list')
      users.value = Array.isArray(res.data) ? res.data : []
    } catch (e) {
      alertStore.error(getErrorMessage(e, 'โหลดรายชื่อบุคลากรไม่สำเร็จ'))
    }
  }
}

const saveManager = async () => {
  saving.value = true
  try {
    const { unitUuid, show, unitName, ...payload } = managerModal
    if (!payload.effective_to) payload.effective_to = null
    await api.post(`/admin/org-units/${unitUuid}/managers`, payload)
    alertStore.success('บันทึกหัวหน้าหน่วยงานเรียบร้อยแล้ว')
    managerModal.show = false
    await loadData()
  } catch (e) {
    alertStore.error(getErrorMessage(e, 'บันทึกหัวหน้าหน่วยงานไม่สำเร็จ'))
  } finally {
    saving.value = false
  }
}

const endModal = reactive({ show: false, uuid: '', label: '', effective_to: '', note: '' })
const openEndModal = (manager, unit) => {
  Object.assign(endModal, { show: true, uuid: manager.uuid, label: `${manager.user?.name || ''} · ${unit.name}`, effective_to: today(), note: '' })
}
const saveEnd = async () => {
  saving.value = true
  try {
    await api.post(`/admin/org-unit-managers/${endModal.uuid}/end`, { effective_to: endModal.effective_to, note: endModal.note || null })
    alertStore.success('ปิดวาระเรียบร้อยแล้ว')
    endModal.show = false
    await loadData()
  } catch (e) {
    alertStore.error(getErrorMessage(e, 'ปิดวาระไม่สำเร็จ'))
  } finally {
    saving.value = false
  }
}

const history = reactive({ show: false, unitName: '', loading: false, items: [] })
const openHistory = async unit => {
  Object.assign(history, { show: true, unitName: unit.name, loading: true, items: [] })
  try {
    const res = await api.get(`/admin/org-units/${unit.uuid}/managers`)
    history.items = Array.isArray(res.data) ? res.data : []
  } catch (e) {
    alertStore.error(getErrorMessage(e, 'โหลดประวัติไม่สำเร็จ'))
  } finally {
    history.loading = false
  }
}

const loadData = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get('/admin/org-units')
    units.value = Array.isArray(res.data?.units) ? res.data.units : []
  } catch (e) {
    loadError.value = getErrorMessage(e, 'ไม่สามารถโหลดโครงสร้างหน่วยงานได้')
  } finally {
    loading.value = false
  }
}

onMounted(loadData)
</script>
