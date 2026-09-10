<template>
  <div class="max-w-7xl mx-auto space-y-6" aria-labelledby="report-title">
    <header>
      <h1 id="report-title" class="text-2xl font-bold text-gray-900">รายงานการลาราชการ</h1>
      <p class="text-sm text-gray-500 mt-1">ทะเบียนการลา สรุปตามประเภท และส่งออก CSV</p>
    </header>

    <!-- Summary Cards — ใช้ตัวกรองชุดเดียวกับตารางด้านล่างเสมอ -->
    <p class="text-xs text-gray-500">
      สรุปตามตัวกรองปัจจุบัน: {{ date(filters.from) }} – {{ date(filters.to) }}
      <template v-if="filters.department_id"> · เฉพาะหน่วยงานที่เลือก</template>
      <template v-if="filters.personnel_type_id"> · เฉพาะประเภทบุคลากรที่เลือก</template>
    </p>
    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <article v-for="row in summary" :key="row.leave_type" class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm text-gray-500 font-medium">{{ row.leave_type }}</p>
        <p class="mt-1 text-2xl font-bold text-gray-800">{{ row.approved_days || 0 }} วัน</p>
        <p class="text-xs text-gray-400 mt-1">อนุมัติ {{ row.approved }} จาก {{ row.requests }} รายการ · ยื่นรวม {{ row.days || 0 }} วัน</p>
      </article>
    </section>

    <!-- Filter Form -->
    <form class="flex flex-wrap items-end gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" @submit.prevent="loadRegister">
      <label class="text-sm font-medium text-gray-700">
        ตั้งแต่
        <input v-model="filters.from" required type="date" class="mt-1 block rounded-xl border border-gray-200 p-2 text-sm focus:ring-2 focus:ring-[#800000]/20 focus:border-[#800000]">
      </label>
      <label class="text-sm font-medium text-gray-700">
        ถึง
        <input v-model="filters.to" required type="date" class="mt-1 block rounded-xl border border-gray-200 p-2 text-sm focus:ring-2 focus:ring-[#800000]/20 focus:border-[#800000]">
      </label>
      <label class="text-sm font-medium text-gray-700">
        หน่วยงาน
        <select v-model="filters.department_id" class="mt-1 block min-w-48 rounded-xl border border-gray-200 p-2 text-sm focus:ring-2 focus:ring-[#800000]/20 focus:border-[#800000]">
          <option value="">ทั้งหมด</option>
          <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
        </select>
      </label>
      <label class="text-sm font-medium text-gray-700">
        ประเภทบุคลากร
        <select v-model="filters.personnel_type_id" class="mt-1 block min-w-48 rounded-xl border border-gray-200 p-2 text-sm focus:ring-2 focus:ring-[#800000]/20 focus:border-[#800000]">
          <option value="">ทั้งหมด</option>
          <option v-for="p in personnelTypes" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </label>
      <button type="submit" class="rounded-xl bg-[#800000] hover:bg-[#660000] px-5 py-2.5 text-white font-medium text-sm shadow-sm transition-colors">
        แสดงรายงาน
      </button>
      <button type="button" class="rounded-xl border border-gray-200 hover:bg-gray-50 px-4 py-2.5 text-gray-700 font-medium text-sm transition-colors" @click="exportCsv">
        ส่งออก CSV
      </button>
    </form>

    <!-- Loading State -->
    <div v-if="loading" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
      <LoadingState type="skeleton-table" :rows="4" :cols="6" message="กำลังประมวลผลรายงาน..." />
    </div>

    <!-- Error State -->
    <div v-else-if="loadError" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
      <ErrorState :message="loadError" @retry="loadRegister" />
    </div>

    <!-- Results Section -->
    <section v-else class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
      <EmptyState
        v-if="!rows.length"
        title="ไม่พบข้อมูลตามเงื่อนไข"
        description="ไม่มีรายการข้อมูลการลาที่ตรงกับช่วงวันที่และตัวกรองที่เลือก"
        :icon="ICONS.document"
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-100 text-gray-600">
            <tr>
              <th class="p-3 text-left font-semibold">เลขที่</th>
              <th class="p-3 text-left font-semibold">บุคลากร</th>
              <th class="p-3 text-left font-semibold">หน่วยงาน</th>
              <th class="p-3 text-left font-semibold">ประเภทการลา</th>
              <th class="p-3 text-left font-semibold">ช่วงวันที่</th>
              <th class="p-3 text-right font-semibold">วัน</th>
              <th class="p-3 text-left font-semibold">สถานะ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="row in rows" :key="row.id" class="hover:bg-gray-50 transition-colors">
              <td class="p-3 font-medium text-gray-900">{{ row.document_number }}</td>
              <td class="p-3">
                <p class="font-medium text-gray-900">{{ row.user?.name }}</p>
                <p class="text-xs text-gray-500">{{ row.user?.personnel_type?.name }}</p>
              </td>
              <td class="p-3 text-gray-600">{{ row.user?.department?.name }}</td>
              <td class="p-3 text-gray-600">{{ row.type?.name }}</td>
              <td class="p-3 text-gray-600">{{ date(row.start_date) }} – {{ date(row.end_date) }}</td>
              <td class="p-3 text-right font-medium">{{ row.total_days }}</td>
              <td class="p-3">
                <template v-if="row.is_deleted">
                  <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">ลบแล้ว</span>
                  <span class="block text-xs text-gray-400">เดิม: {{ status[row.status] || row.status }}</span>
                </template>
                <span v-else class="text-xs font-medium">{{ status[row.status] || row.status }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="truncated" class="border-t border-amber-100 bg-amber-50 p-3 text-center text-xs text-amber-800">
        แสดง {{ rows.length }} รายการแรกจากทั้งหมด {{ total }} รายการ — กด “ส่งออก CSV” เพื่อดูข้อมูลทั้งหมด
      </p>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../axios'
import { useAlertStore } from '../stores/alert'
import { buildFilename, filenameFromHeaders } from '../utils/downloadFilename'
import { getErrorMessage } from '../utils/errorHandler'
import { ICONS } from '../utils/icons'
import LoadingState from '../components/ui/LoadingState.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import ErrorState from '../components/ui/ErrorState.vue'

const alerts = useAlertStore()
const loading = ref(false)
const loadError = ref('')
const rows = ref([])
const total = ref(0)
const truncated = computed(() => total.value > rows.value.length)
const summary = ref([])
const departments = ref([])
const personnelTypes = ref([])

const today = new Date()
const start = new Date(today.getFullYear(), 0, 1)
const iso = (d) => d.toISOString().slice(0, 10)

const filters = reactive({
  from: iso(start),
  to: iso(today),
  department_id: '',
  personnel_type_id: ''
})

const status = {
  Pending: 'รออนุมัติ',
  Approved: 'อนุมัติ',
  Rejected: 'ไม่อนุมัติ',
  Cancelled: 'ยกเลิก',
  Voided: 'ยกเลิกเอกสาร'
}

const params = () => Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))

/*
 * การ์ดสรุปต้องใช้ตัวกรองชุดเดียวกับตารางทะเบียน
 *
 * เดิมสรุปโหลดครั้งเดียวตอนเปิดหน้าด้วยช่วง "ย้อนหลัง 12 เดือน" เสมอ เมื่อผู้ใช้เปลี่ยนช่วงวันที่
 * หรือกรองหน่วยงาน ตัวเลขบนการ์ดกับตารางจึงเป็นคนละชุดข้อมูลโดยไม่มีอะไรบอก
 */
const loadSummary = async () => {
  try {
    const { data } = await api.get('/reports/government-leave-summary', { params: params() })
    summary.value = data
  } catch (e) {
    console.warn('Failed to load summary', e)
  }
}

const loadRegister = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const [{ data }] = await Promise.all([
      api.get('/reports/government-leave-register', { params: params() }),
      loadSummary(),
    ])
    rows.value = data.data || data
    total.value = data.total ?? rows.value.length
  } catch (e) {
    console.error(e)
    loadError.value = getErrorMessage(e, 'ไม่สามารถโหลดข้อมูลรายงานได้')
    alerts.error(loadError.value)
  } finally {
    loading.value = false
  }
}

const exportCsv = async () => {
  try {
    const r = await api.get('/reports/government-leave-register.csv', { params: params(), responseType: 'blob' })
    const u = URL.createObjectURL(r.data)
    const a = document.createElement('a')
    a.href = u
    // ชื่อไฟล์ที่เซิร์ฟเวอร์ประกาศเป็นภาษาไทยและบอกช่วงข้อมูลอยู่แล้ว
    a.download = filenameFromHeaders(r.headers)
      || buildFilename(['ทะเบียนคุมการลา', filters.from, 'ถึง', filters.to], 'csv')
    a.click()
    URL.revokeObjectURL(u)
    alerts.success('ส่งออกรายงานเรียบร้อยแล้ว')
  } catch (e) {
    alerts.error(getErrorMessage(e, 'ส่งออกรายงานไม่สำเร็จ'))
  }
}

const date = (v) => new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium' }).format(new Date(v))

onMounted(async () => {
  try {
    const [d, p] = await Promise.all([
      api.get('/departments'),
      api.get('/personnel-types')
    ])
    departments.value = d.data.data || d.data
    personnelTypes.value = p.data
  } catch (e) {
    console.warn('Failed to load filter metadata', e)
  }
  await loadRegister()
})
</script>

