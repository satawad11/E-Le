<template>
  <div class="max-w-7xl mx-auto space-y-6">
    <div class="flex justify-between items-center mb-6">
      <h2 class="text-2xl font-bold text-gray-800">การลงเวลาบุคลากร</h2>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-6 mb-6">
      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-4">
        <div class="relative z-50">
          <label class="block text-sm font-medium text-gray-700 mb-1">วันที่</label>
          <ThaiDatePicker v-model="filters.date" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">เดือน</label>
          <select v-model="filters.month" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
            <option value="">ทั้งหมด</option>
            <option v-for="(name, index) in months" :key="index" :value="index + 1">{{ name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">แผนก</label>
          <select v-model="filters.org_unit_id" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
            <option value="">ทั้งหมด</option>
            <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">ค้นหารายบุคคล</label>
          <select v-model="filters.user_id" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
            <option value="">ทั้งหมด</option>
            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }} {{ user.last_name }}</option>
          </select>
        </div>
        <div class="flex items-end gap-2 sm:col-span-2 md:col-span-1">
          <button @click="fetchRecords(1)" class="w-full bg-[#800000] text-white px-4 py-2 rounded-lg text-sm hover:bg-[#600000] font-semibold">
            ค้นหา
          </button>
        </div>
      </div>
      <div class="mt-4 flex flex-wrap justify-end gap-2">
        <button @click="syncLeaves" :disabled="isSyncing" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-700 flex items-center gap-2 disabled:opacity-50">
          <svg class="w-4 h-4" :class="{'animate-spin': isSyncing}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
          {{ isSyncing ? 'กำลังซิงค์...' : 'ซิงค์ข้อมูลการลา' }}
        </button>
        <button @click="exportReport('pdf')" class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-red-700 flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
          ดาวน์โหลด PDF
        </button>
        <button @click="exportReport('word')" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
          ดาวน์โหลด Word
        </button>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-gray-50 border-b border-gray-100 text-gray-600 text-sm">
              <th class="py-4 px-6 font-semibold">ชื่อ-สกุล</th>
              <th class="py-4 px-6 font-semibold">แผนก</th>
              <th class="py-4 px-4 font-semibold w-24">วันที่</th>
              <th class="py-4 px-4 font-semibold text-center">เวลาเข้า</th>
              <th class="py-4 px-4 font-semibold text-center">เวลาออก</th>
              <th class="py-4 px-4 font-semibold text-center">เข้า OT</th>
              <th class="py-4 px-4 font-semibold text-center">ออก OT</th>
              <th class="py-4 px-4 font-semibold text-center">สถานะ</th>
              <th class="py-4 px-4 font-semibold">หมายเหตุ</th>
            </tr>
          </thead>
          <tbody class="text-sm">
            <tr v-if="loading">
              <td colspan="8" class="py-8 text-center text-gray-500">กำลังโหลดข้อมูล...</td>
            </tr>
            <tr v-else-if="records.data && records.data.length === 0">
              <td colspan="8" class="py-8 text-center text-gray-500">ไม่พบข้อมูลการลงเวลา</td>
            </tr>
            <tr v-else v-for="record in records.data" :key="record.id" class="border-b border-gray-50 hover:bg-gray-50/50">
              <td class="py-4 px-6 font-medium text-gray-900">
                {{ record.user?.name || '-' }}
              </td>
              <td class="py-4 px-6 text-gray-600">
                {{ record.user?.department?.name || '-' }}
              </td>
              <td class="py-4 px-4 text-gray-600">
                {{ formatDate(record.date) }}
              </td>
              <td class="py-4 px-4 text-center">
                <div v-if="record.check_in_time">
                  <span class="inline-flex items-center gap-1 text-green-700 bg-green-50 px-2 py-1 rounded text-xs font-medium border border-green-100">
                    {{ formatTime(record.check_in_time) }}
                  </span>
                  <div v-if="record.late_minutes > 0" class="text-[10px] text-red-500 mt-1">สาย {{ record.late_minutes }} นาที</div>
                </div>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td class="py-4 px-4 text-center">
                <div v-if="record.check_out_time">
                  <span class="inline-flex items-center gap-1 text-orange-700 bg-orange-50 px-2 py-1 rounded text-xs font-medium border border-orange-100">
                    {{ formatTime(record.check_out_time) }}
                  </span>
                  <div v-if="record.early_leave_minutes > 0" class="text-[10px] text-yellow-600 mt-1">ออกก่อน {{ record.early_leave_minutes }} นาที</div>
                </div>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td class="py-4 px-4 text-center">
                <span v-if="record.ot_check_in_time" class="inline-flex items-center gap-1 text-blue-700 bg-blue-50 px-2 py-1 rounded text-xs font-medium border border-blue-100">
                  {{ formatTime(record.ot_check_in_time) }}
                </span>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td class="py-4 px-4 text-center">
                <div v-if="record.ot_check_out_time">
                  <span class="inline-flex items-center gap-1 text-purple-700 bg-purple-50 px-2 py-1 rounded text-xs font-medium border border-purple-100">
                    {{ formatTime(record.ot_check_out_time) }}
                  </span>
                  <div v-if="record.ot_minutes > 0" class="text-[10px] text-purple-600 mt-1">
                    รวม {{ Math.floor(record.ot_minutes / 60) }} ชม. {{ record.ot_minutes % 60 }} นาที
                  </div>
                </div>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td class="py-4 px-4 text-center">
                <span class="inline-block px-2.5 py-1 rounded-full text-xs font-medium" :class="statusClass(record.status)">
                  {{ statusText(record.status) }}
                </span>
              </td>
              <td class="py-4 px-4 text-gray-600 truncate max-w-[150px]" :title="record.notes">
                {{ record.notes || '-' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      
      <!-- Pagination -->
      <div v-if="records.last_page > 1" class="px-6 py-4 border-t border-gray-100 flex items-center justify-between">
        <span class="text-sm text-gray-500">
          แสดง {{ records.from }} ถึง {{ records.to }} จาก {{ records.total }} รายการ
        </span>
        <div class="flex gap-1">
          <button v-for="link in records.links" :key="link.label"
                  @click="link.url && fetchRecords(getPage(link.url))"
                  :disabled="!link.url"
                  class="px-3 py-1 text-sm rounded-md transition-colors"
                  :class="link.active ? 'bg-[#800000] text-white' : (link.url ? 'bg-gray-100 text-gray-700 hover:bg-gray-200' : 'text-gray-400 cursor-not-allowed')">
            {{ paginationLabel(link.label) }}
          </button>
        </div>
      </div>
    </div>

    <!--
      คำขอแก้ไขเวลาที่รอพิจารณา (CLEANUP-02)
      สายอนุมัติและ audit มีครบมาตั้งแต่ LEGACY-08 แต่ไม่มีหน้าจอให้ผู้ตรวจสอบตัดสินเลย
    -->
    <section class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <header class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center gap-3">
        <div>
          <h3 class="font-semibold text-gray-800">คำขอแก้ไขเวลา</h3>
          <p class="text-sm text-gray-500 mt-0.5">อนุมัติแล้วระบบจะปรับข้อมูลลงเวลาให้ทันที พร้อมบันทึกผู้อนุมัติไว้ในประวัติ</p>
        </div>
        <select
          v-model="correctionStatus"
          aria-label="กรองสถานะคำขอแก้ไขเวลา"
          class="ml-auto border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]"
          @change="fetchCorrections"
        >
          <option value="pending">รอพิจารณา</option>
          <option value="approved">อนุมัติแล้ว</option>
          <option value="rejected">ไม่อนุมัติ</option>
          <option value="all">ทั้งหมด</option>
        </select>
      </header>

      <div v-if="corrections.length === 0" class="px-5 py-8 text-center text-gray-500 text-sm">
        ไม่มีคำขอในสถานะนี้
      </div>

      <ul v-else class="divide-y divide-gray-50">
        <li v-for="correction in corrections" :key="correction.id" class="px-5 py-4 flex flex-wrap items-start gap-4">
          <div class="min-w-0 flex-1">
            <p class="font-medium text-gray-900 text-sm">
              {{ correction.user?.name }}
              <span class="text-gray-400 font-normal">· {{ correction.user?.department?.name || '—' }}</span>
            </p>
            <p class="text-sm text-gray-600 mt-0.5">
              {{ formatDate(correction.attendance?.date) }} —
              <span class="line-through text-gray-400">{{ describeValues(correction.before_values) }}</span>
              <span class="mx-1">→</span>
              <span class="font-medium">{{ describeValues(correction.proposed_values) }}</span>
            </p>
            <p class="text-xs text-gray-500 mt-0.5">เหตุผล: {{ correction.reason }}</p>
          </div>

          <div v-if="correction.status === 'pending'" class="flex gap-2">
            <button
              type="button"
              :disabled="decidingId === correction.id"
              class="px-3 py-1.5 rounded-lg border border-green-200 text-xs font-semibold text-green-700 hover:bg-green-50 disabled:opacity-50"
              @click="decideCorrection(correction, 'approve')"
            >
              อนุมัติ
            </button>
            <button
              type="button"
              :disabled="decidingId === correction.id"
              class="px-3 py-1.5 rounded-lg border border-red-200 text-xs font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50"
              @click="decideCorrection(correction, 'reject')"
            >
              ไม่อนุมัติ
            </button>
          </div>
          <span v-else class="px-2.5 py-1 rounded-full text-xs font-medium" :class="correctionStatusClass(correction.status)">
            {{ correctionStatusText(correction.status) }}
          </span>
        </li>
      </ul>
    </section>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import ThaiDatePicker from '../../components/ThaiDatePicker.vue'

const alertStore = useAlertStore()
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'

const months = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม']
const paginationLabel = label => ({ '&laquo; Previous': 'ก่อนหน้า', 'Next &raquo;': 'ถัดไป' }[label] || String(label).replace(/<[^>]*>/g, ''))

const today = new Date()
const localDateStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0')

const filters = ref({
  date: localDateStr,
  month: '',
  year: today.getFullYear(),
  org_unit_id: '',
  user_id: ''
})

const records = ref({ data: [], links: [], last_page: 1 })
const loading = ref(false)
const departments = ref([])
const users = ref([])

const fetchDepartments = async () => {
  try {
    const res = await api.get('/org-units')
    departments.value = res.data
  } catch (error) {
    console.error(error)
  }
}

const fetchUsers = async () => {
  try {
    const res = await api.get('/users/list')
    users.value = res.data
  } catch (error) {
    console.error(error)
  }
}

const fetchRecords = async (page = 1) => {
  loading.value = true
  try {
    const res = await api.get('/admin/attendances', {
      params: { ...filters.value, page }
    })
    records.value = res.data
  } catch (error) {
    console.error(error)
  } finally {
    loading.value = false
  }
}

const exportReport = async (type) => {
  try {
    const params = { ...filters.value }
    const res = await api.get(`/admin/attendances/export/${type}`, { params, responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([res.data], { type: type === 'pdf' ? 'application/pdf' : 'application/vnd.ms-word' }))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', `รายงานการลงเวลา_${params.date}.${type === 'pdf' ? 'pdf' : 'doc'}`)
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (error) {
    console.error('Error exporting report:', error)
    alertStore.error('ไม่สามารถโหลดข้อมูลได้ หรือเกิดข้อผิดพลาดในการดาวน์โหลด')
  }
}

const isSyncing = ref(false)

const syncLeaves = async () => {
  if (!confirm('ต้องการซิงค์ข้อมูลการลาของเดือนนี้หรือไม่?')) return
  
  isSyncing.value = true
  try {
    const res = await api.post('/admin/attendances/sync-leaves', {
      month: filters.value.month || new Date().getMonth() + 1,
      year: filters.value.year || new Date().getFullYear()
    })
    alertStore.success(res.data.message || 'ซิงค์ข้อมูลสำเร็จ')
    fetchRecords(1)
  } catch (error) {
    console.error('Error syncing leaves:', error)
    alertStore.error(error.response?.data?.message || 'เกิดข้อผิดพลาดในการซิงค์ข้อมูล')
  } finally {
    isSyncing.value = false
  }
}

const getPage = (url) => {
  const match = url.match(/page=(\d+)/)
  return match ? parseInt(match[1]) : 1
}

const formatDate = (dateStr) => {
  if (!dateStr) return '-'
  return ThaiDateFormatter.toThaiShortText(dateStr)
}

const formatTime = (datetimeStr) => {
  if (!datetimeStr) return '-'
  const d = new Date(datetimeStr)
  return d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }) + ' น.'
}

const statusClass = (status) => {
  const map = {
    'Present': 'bg-green-100 text-green-700',
    'Late': 'bg-yellow-100 text-yellow-800',
    'Absent': 'bg-red-100 text-red-700',
    'Leave': 'bg-blue-100 text-blue-700'
  }
  return map[status] || 'bg-gray-100 text-gray-700'
}

const statusText = (status) => {
  const map = {
    'Present': 'มาปกติ',
    'Late': 'มาสาย',
    'Absent': 'ขาดงาน',
    'Leave': 'ลา'
  }
  return map[status] || status
}

/*
 * คำขอแก้ไขเวลา (CLEANUP-02)
 *
 * การอนุมัติเดินผ่าน workflow engine และปรับข้อมูลลงเวลาให้เองเมื่ออนุมัติ
 * (ดู `AttendanceCorrectionService::decide`) หน้านี้เป็นทางเข้าเดียวของผู้ตรวจสอบ
 */
const corrections = ref([])
const correctionStatus = ref('pending')
const decidingId = ref(null)

const CORRECTION_FIELD_LABELS = {
  check_in_time: 'เข้า',
  check_out_time: 'ออก',
  status: 'สถานะ',
  notes: 'หมายเหตุ',
}

const CORRECTION_STATUS = {
  pending: { text: 'รอพิจารณา', class: 'bg-yellow-100 text-yellow-800' },
  approved: { text: 'อนุมัติแล้ว', class: 'bg-green-100 text-green-700' },
  rejected: { text: 'ไม่อนุมัติ', class: 'bg-red-100 text-red-700' },
}

const correctionStatusText = (status) => CORRECTION_STATUS[status]?.text || status
const correctionStatusClass = (status) => CORRECTION_STATUS[status]?.class || 'bg-gray-100 text-gray-700'

const describeValues = (values) =>
  Object.entries(values || {})
    .map(([key, value]) => `${CORRECTION_FIELD_LABELS[key] || key} ${value ? formatTime(value) : 'ว่าง'}`)
    .join(' · ') || '—'

const fetchCorrections = async () => {
  try {
    const { data } = await api.get('/admin/attendance-corrections', { params: { status: correctionStatus.value } })
    corrections.value = data.data || data
  } catch (error) {
    console.error(error)
  }
}

const decideCorrection = async (correction, action) => {
  let comment = null

  if (action === 'reject') {
    comment = window.prompt('เหตุผลที่ไม่อนุมัติ')
    if (!comment) return
  }

  decidingId.value = correction.id
  try {
    const { data } = await api.post(`/admin/attendance-corrections/${correction.uuid}/${action}`, { comment })
    alertStore.success(data.message)
    await Promise.all([fetchCorrections(), fetchRecords()])
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'บันทึกผลการพิจารณาไม่สำเร็จ')
  } finally {
    decidingId.value = null
  }
}

onMounted(() => {
  Promise.all([
    fetchDepartments(),
    fetchUsers(),
    fetchRecords(),
    fetchCorrections()
  ])
})
</script>
