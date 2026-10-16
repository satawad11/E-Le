<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">การรักษาราชการแทน / ปฏิบัติราชการแทน</h1>
        <p class="text-sm text-gray-500 mt-1">
          ทะเบียนผู้ทำหน้าที่แทนตามคำสั่งแต่งตั้ง — ผู้ทำหน้าที่แทนที่มีผลอยู่ลงนามอนุมัติใบลาแทนผู้มีอำนาจได้
          และแบบฟอร์มจะพิมพ์ "รักษาราชการแทน/ปฏิบัติราชการแทน …" ใต้ตำแหน่ง
        </p>
      </div>
      <button type="button" @click="openCreate" class="px-4 py-2.5 bg-[#800000] hover:bg-[#600000] text-white text-sm font-semibold rounded-xl shadow-sm focus-visible:ring-2 focus-visible:ring-[#800000]">
        + บันทึกตามคำสั่ง
      </button>
    </div>

    <div class="flex flex-wrap gap-2" role="tablist" aria-label="กรองตามสถานะ">
      <button v-for="f in filters" :key="f.value" type="button" role="tab" :aria-selected="statusFilter === f.value" @click="statusFilter = f.value"
        :class="['px-3 py-1.5 text-xs font-semibold rounded-full border', statusFilter === f.value ? 'bg-[#800000] text-white border-[#800000]' : 'bg-white text-gray-600 border-gray-300 hover:border-[#800000]']">
        {{ f.label }}<span v-if="f.value === 'pending' && pendingCount"> ({{ pendingCount }})</span>
      </button>
    </div>

    <LoadingState v-if="loading" />
    <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
    <EmptyState v-else-if="!visibleRows.length" title="ยังไม่มีรายการ" description="บันทึกการทำหน้าที่แทนตามคำสั่งแต่งตั้ง หรือรอคำขอจากผู้ใช้" />

    <div v-else class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-xs">
          <tr>
            <th class="text-left px-4 py-3">ผู้มอบ / ตำแหน่ง</th>
            <th class="text-left px-4 py-3">ผู้ทำหน้าที่แทน</th>
            <th class="text-left px-4 py-3">ช่วงเวลา / คำสั่ง</th>
            <th class="text-left px-4 py-3">สถานะ</th>
            <th class="px-4 py-3"><span class="sr-only">การจัดการ</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="d in visibleRows" :key="d.id" class="align-top">
            <td class="px-4 py-3">
              <div class="font-semibold text-gray-900">{{ d.user?.name }}</div>
              <div class="text-xs text-gray-500">{{ d.user?.position || '-' }}</div>
            </td>
            <td class="px-4 py-3">
              <div class="font-semibold text-gray-900">{{ d.delegate?.name }}</div>
              <div class="text-xs text-indigo-700">{{ d.acting_line }}</div>
            </td>
            <td class="px-4 py-3 text-xs text-gray-600">
              <div>{{ thaiDate(d.start_date) }} – {{ thaiDate(d.end_date) }}</div>
              <div v-if="d.order_number">คำสั่งที่ {{ d.order_number }}<span v-if="d.order_date"> ลงวันที่ {{ thaiDate(d.order_date) }}</span></div>
              <div v-else-if="d.status !== 'revoked'" class="text-amber-700">ยังไม่ระบุเลขที่คำสั่ง</div>
              <div v-if="d.note" class="text-gray-500 mt-1">{{ d.note }}</div>
            </td>
            <td class="px-4 py-3">
              <span :class="['text-xs font-semibold px-2 py-0.5 rounded-full', statusClass(d)]">{{ statusLabel(d) }}</span>
              <div v-if="d.status === 'pending' && d.requester" class="text-xs text-gray-500 mt-1">ขอโดย {{ d.requester.name }}</div>
              <div v-if="d.status === 'approved' && d.approver" class="text-xs text-gray-500 mt-1">รับรองโดย {{ d.approver.name }}</div>
              <div v-if="d.status === 'revoked' && d.revoker" class="text-xs text-gray-500 mt-1">ยกเลิกโดย {{ d.revoker.name }}</div>
            </td>
            <td class="px-4 py-3 text-right whitespace-nowrap space-x-1">
              <button v-if="d.status === 'pending'" type="button" @click="openApprove(d)" class="px-3 py-1.5 text-xs font-semibold bg-green-600 hover:bg-green-700 text-white rounded-lg">รับรอง</button>
              <button v-if="d.status !== 'revoked'" type="button" @click="openEdit(d)" class="px-3 py-1.5 text-xs font-semibold bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg">แก้ไข</button>
              <button v-if="d.status !== 'revoked'" type="button" @click="revoke(d)" class="px-3 py-1.5 text-xs font-semibold border border-red-200 text-red-600 hover:bg-red-50 rounded-lg">{{ d.status === 'pending' ? 'ไม่รับรอง' : 'ยกเลิก' }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ฟอร์มบันทึก/แก้ไข/รับรอง -->
    <div v-if="dialog.show" role="dialog" aria-modal="true" aria-labelledby="delegation-dialog-title" @keydown.escape="dialog.show = false" tabindex="-1"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4 focus:outline-none">
      <form @submit.prevent="submit" class="bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6 space-y-3 max-h-[90vh] overflow-y-auto">
        <h3 id="delegation-dialog-title" class="font-bold text-gray-900 text-lg">{{ dialogTitle }}</h3>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label class="block sm:col-span-2 text-xs text-gray-700">ประเภท
            <select v-model="form.delegation_type" required class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
              <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </label>
          <label class="block text-xs text-gray-700">ผู้มอบ (เจ้าของตำแหน่ง)
            <select v-model="form.user_id" :disabled="dialog.mode === 'approve'" required class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
              <option :value="null" disabled>— เลือก —</option>
              <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </label>
          <label class="block text-xs text-gray-700">ผู้ทำหน้าที่แทน
            <select v-model="form.delegate_id" :disabled="dialog.mode === 'approve'" required class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
              <option :value="null" disabled>— เลือก —</option>
              <option v-for="u in users" :key="u.id" :value="u.id" :disabled="u.id === form.user_id">{{ u.name }}</option>
            </select>
          </label>
          <label class="block sm:col-span-2 text-xs text-gray-700">ตำแหน่งที่ทำหน้าที่แทน (เว้นว่าง = ตำแหน่งของผู้มอบ)
            <input v-model="form.acting_position" type="text" maxlength="255" placeholder="เช่น ผู้อำนวยการวิทยาลัยเทคนิคดอนเมือง" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
          </label>
          <label class="block text-xs text-gray-700">เลขที่คำสั่ง
            <input v-model="form.order_number" type="text" maxlength="100" placeholder="เช่น 123/2569" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
          </label>
          <label class="block text-xs text-gray-700">ลงวันที่ (คำสั่ง)
            <input v-model="form.order_date" type="date" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
          </label>
          <label class="block text-xs text-gray-700">ตั้งแต่วันที่
            <input v-model="form.start_date" type="date" required :disabled="dialog.mode === 'approve'" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
          </label>
          <label class="block text-xs text-gray-700">ถึงวันที่
            <input v-model="form.end_date" type="date" required :disabled="dialog.mode === 'approve'" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
          </label>
          <label v-if="dialog.mode !== 'approve'" class="block sm:col-span-2 text-xs text-gray-700">หมายเหตุ
            <textarea v-model="form.note" rows="2" maxlength="500" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm resize-none"></textarea>
          </label>
        </div>

        <p class="text-xs text-gray-500 bg-gray-50 rounded-lg p-2">
          พิมพ์บนแบบฟอร์ม: <span class="font-semibold text-gray-800">{{ previewLine || '—' }}</span>
        </p>

        <div class="flex gap-3 pt-2">
          <button type="button" @click="dialog.show = false" :disabled="saving" class="flex-1 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl text-sm">ยกเลิก</button>
          <button type="submit" :disabled="saving" class="flex-1 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm disabled:opacity-50">
            {{ saving ? 'กำลังบันทึก...' : (dialog.mode === 'approve' ? 'รับรอง' : 'บันทึก') }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { getErrorMessage } from '../../utils/errorHandler'
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'
import LoadingState from '../../components/ui/LoadingState.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'

const alertStore = useAlertStore()
const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const rows = ref([])
const users = ref([])
const types = ref([
  { value: 'on_behalf', label: 'ปฏิบัติราชการแทน' },
  { value: 'acting', label: 'รักษาราชการแทน' }
])
const statusFilter = ref('active')

const filters = [
  { value: 'active', label: 'มีผล/รอรับรอง' },
  { value: 'pending', label: 'รอรับรอง' },
  { value: 'approved', label: 'รับรองแล้ว' },
  { value: 'revoked', label: 'ยกเลิกแล้ว' },
  { value: 'all', label: 'ทั้งหมด' }
]

const pendingCount = computed(() => rows.value.filter(d => d.status === 'pending').length)
const visibleRows = computed(() => {
  if (statusFilter.value === 'all') return rows.value
  if (statusFilter.value === 'active') return rows.value.filter(d => d.status !== 'revoked')
  return rows.value.filter(d => d.status === statusFilter.value)
})

const thaiDate = (d) => (d ? ThaiDateFormatter.toThaiSlash(d) : '-')

const statusLabel = (d) => {
  if (d.status === 'pending') return 'รอรับรอง'
  if (d.status === 'revoked') return 'ยกเลิกแล้ว'
  return d.in_effect ? 'มีผลอยู่' : 'รับรองแล้ว (ไม่อยู่ในช่วงเวลา)'
}
const statusClass = (d) => {
  if (d.status === 'pending') return 'bg-amber-100 text-amber-800'
  if (d.status === 'revoked') return 'bg-gray-100 text-gray-600'
  return d.in_effect ? 'bg-green-100 text-green-800' : 'bg-blue-50 text-blue-700'
}

const emptyForm = () => ({
  user_id: null, delegate_id: null, delegation_type: 'on_behalf', acting_position: '',
  order_number: '', order_date: '', start_date: '', end_date: '', note: ''
})
const form = reactive(emptyForm())
const dialog = reactive({ show: false, mode: 'create', id: null })
const dialogTitle = computed(() => ({ create: 'บันทึกการทำหน้าที่แทนตามคำสั่ง', edit: 'แก้ไขการทำหน้าที่แทน', approve: 'รับรองคำขอทำหน้าที่แทน' }[dialog.mode]))

const previewLine = computed(() => {
  const label = types.value.find(t => t.value === form.delegation_type)?.label || ''
  const owner = rows.value.find(d => d.user_id === form.user_id)?.user?.position || ''
  const position = form.acting_position || owner
  return [label, position].filter(Boolean).join(' ')
})

const fill = (d) => {
  Object.assign(form, emptyForm(), {
    user_id: d.user_id, delegate_id: d.delegate_id, delegation_type: d.delegation_type,
    acting_position: d.acting_position || '', order_number: d.order_number || '', order_date: d.order_date || '',
    start_date: d.start_date || '', end_date: d.end_date || '', note: d.note || ''
  })
}

const openCreate = () => { Object.assign(form, emptyForm()); Object.assign(dialog, { show: true, mode: 'create', id: null }) }
const openEdit = (d) => { fill(d); Object.assign(dialog, { show: true, mode: 'edit', id: d.id }) }
const openApprove = (d) => { fill(d); Object.assign(dialog, { show: true, mode: 'approve', id: d.id }) }

const payload = () => {
  const data = { ...form }
  for (const key of ['acting_position', 'order_number', 'order_date', 'note']) {
    if (data[key] === '') data[key] = null
  }
  return data
}

const submit = async () => {
  saving.value = true
  try {
    let res
    if (dialog.mode === 'create') {
      res = await api.post('/admin/delegations', payload())
    } else if (dialog.mode === 'edit') {
      res = await api.put(`/admin/delegations/${dialog.id}`, payload())
    } else {
      const { delegation_type, acting_position, order_number, order_date } = payload()
      res = await api.post(`/admin/delegations/${dialog.id}/approve`, { delegation_type, acting_position, order_number, order_date })
    }
    alertStore.success(res.data?.message || 'บันทึกเรียบร้อยแล้ว')
    dialog.show = false
    await load()
  } catch (e) {
    alertStore.error(getErrorMessage(e, 'บันทึกไม่สำเร็จ'))
  } finally {
    saving.value = false
  }
}

const revoke = async (d) => {
  const reason = window.prompt(`${d.status === 'pending' ? 'ไม่รับรอง' : 'ยกเลิก'}การทำหน้าที่แทนของ ${d.delegate?.name} — ระบุเหตุผล (ไม่บังคับ)`, '')
  if (reason === null) return
  try {
    const res = await api.post(`/admin/delegations/${d.id}/revoke`, { reason: reason || null })
    alertStore.success(res.data?.message || 'ยกเลิกเรียบร้อยแล้ว')
    await load()
  } catch (e) {
    alertStore.error(getErrorMessage(e, 'ยกเลิกไม่สำเร็จ'))
  }
}

const load = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const [res, userRes] = await Promise.all([api.get('/admin/delegations'), users.value.length ? null : api.get('/users/list')])
    rows.value = res.data?.data || []
    if (res.data?.types?.length) types.value = res.data.types
    if (userRes) users.value = userRes.data || []
  } catch (e) {
    loadError.value = getErrorMessage(e, 'โหลดทะเบียนการทำหน้าที่แทนไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
