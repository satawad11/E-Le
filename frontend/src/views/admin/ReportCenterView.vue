<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-3">
      <div>
        <h2 class="text-2xl font-bold text-gray-800">ศูนย์รายงานกลาง</h2>
        <p class="text-gray-500 text-sm mt-1">รายงานมาตรฐานของระบบ กรองตามช่วงเวลาและหน่วยงาน แล้วส่งออกได้ทั้ง PDF, Excel, Word และ CSV</p>
      </div>
      <div v-if="activeReport" class="flex flex-wrap gap-2">
        <button
          v-for="fmt in formats"
          :key="fmt.code"
          type="button"
          :disabled="exporting === fmt.code || loading"
          class="inline-flex items-center gap-2 px-4 py-2 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm disabled:opacity-50"
          :class="fmt.class"
          @click="download(fmt.code)"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
          {{ exporting === fmt.code ? 'กำลังเตรียมไฟล์…' : fmt.label }}
        </button>
      </div>
    </div>

    <PermissionDenied v-if="catalogueLoaded && !reports.length" message="บัญชีของคุณยังไม่มีสิทธิ์เปิดดูรายงานใดในศูนย์รายงานกลาง" />

    <template v-else>
      <!-- Report picker -->
      <nav class="flex flex-wrap gap-2" aria-label="เลือกรายงาน">
        <button
          v-for="report in reports"
          :key="report.code"
          type="button"
          class="px-4 py-2 rounded-xl text-sm font-medium border transition-colors"
          :class="report.code === activeReport
            ? 'bg-[#800000] border-[#800000] text-white shadow-sm'
            : 'bg-white border-gray-200 text-gray-700 hover:bg-gray-50'"
          @click="selectReport(report.code)"
        >
          {{ report.title }}
        </button>
      </nav>

      <!-- Filters -->
      <form class="bg-white rounded-xl shadow-sm border border-gray-100 p-6" @submit.prevent="loadReport">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div>
            <label for="report-from" class="block text-sm font-medium text-gray-700 mb-1">ตั้งแต่วันที่</label>
            <input id="report-from" v-model="filters.from" type="date" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
          </div>
          <div>
            <label for="report-to" class="block text-sm font-medium text-gray-700 mb-1">ถึงวันที่</label>
            <input id="report-to" v-model="filters.to" type="date" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
          </div>
          <div>
            <label for="report-org" class="block text-sm font-medium text-gray-700 mb-1">หน่วยงาน</label>
            <select id="report-org" v-model="filters.org_unit_id" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
              <option value="">ทั้งหมดตามสิทธิ์ที่มี</option>
              <option v-for="unit in orgUnits" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
            </select>
          </div>
          <div>
            <label for="report-personnel-type" class="block text-sm font-medium text-gray-700 mb-1">ประเภทบุคลากร</label>
            <select id="report-personnel-type" v-model="filters.personnel_type_id" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
              <option value="">ทั้งหมด</option>
              <option v-for="type in personnelTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
            </select>
          </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
          <button type="submit" class="rounded-xl bg-[#800000] hover:bg-[#660000] px-5 py-2.5 text-white font-medium text-sm shadow-sm transition-colors">
            แสดงรายงาน
          </button>
          <button type="button" class="rounded-xl border border-gray-200 hover:bg-gray-50 px-4 py-2.5 text-gray-700 font-medium text-sm transition-colors" @click="resetFilters">
            ล้างตัวกรอง
          </button>
          <p v-if="dataset" class="ml-auto self-center text-sm text-gray-500">
            ปีงบประมาณ {{ dataset.meta?.fiscal_year }} ·
            {{ thaiDate(dataset.meta?.from) }} – {{ thaiDate(dataset.meta?.to) }} ·
            {{ dataset.rows.length }} รายการ
          </p>
        </div>
      </form>

      <!-- Result -->
      <div v-if="loading" class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
        <LoadingState type="skeleton-table" :rows="5" :cols="5" message="กำลังประมวลผลรายงาน..." />
      </div>

      <div v-else-if="loadError" class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
        <ErrorState :message="loadError" @retry="loadReport" />
      </div>

      <section v-else-if="dataset" class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <header class="px-6 py-4 border-b border-gray-100">
          <h3 class="font-semibold text-gray-800">{{ dataset.title }}</h3>
        </header>

        <EmptyState
          v-if="!dataset.rows.length"
          title="ไม่พบข้อมูลตามเงื่อนไข"
          description="ลองขยายช่วงวันที่ หรือเอาตัวกรองหน่วยงานและประเภทบุคลากรออก"
          :icon="ICONS.document"
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100 text-gray-600">
              <tr>
                <th scope="col" class="p-3 text-right font-semibold w-16">ลำดับ</th>
                <th v-for="(label, key) in dataset.columns" :key="key" scope="col" class="p-3 font-semibold" :class="isNumericColumn(key) ? 'text-right' : 'text-left'">
                  {{ label }}
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="(row, index) in dataset.rows" :key="index" class="hover:bg-gray-50 transition-colors">
                <td class="p-3 text-right text-gray-400 tabular-nums">{{ index + 1 }}</td>
                <td v-for="(label, key) in dataset.columns" :key="key" class="p-3" :class="isNumericColumn(key) ? 'text-right tabular-nums' : 'text-gray-700'">
                  {{ cell(row[key]) }}
                </td>
              </tr>
            </tbody>
            <tfoot v-if="totals.length" class="bg-gray-50 border-t border-gray-100">
              <tr>
                <td :colspan="Object.keys(dataset.columns).length + 1" class="p-3 text-gray-700">
                  <span class="font-semibold">รวม:</span>
                  <span v-for="(total, i) in totals" :key="total.key">
                    {{ total.label }} {{ cell(total.value) }}<span v-if="i < totals.length - 1"> · </span>
                  </span>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { buildFilename, filenameFromHeaders } from '../../utils/downloadFilename'
import { getErrorMessage } from '../../utils/errorHandler'
import { ICONS } from '../../utils/icons'
import LoadingState from '../../components/ui/LoadingState.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import PermissionDenied from '../../components/ui/PermissionDenied.vue'

const alerts = useAlertStore()

const reports = ref([])
const catalogueLoaded = ref(false)
const activeReport = ref('')
const dataset = ref(null)
const orgUnits = ref([])
const personnelTypes = ref([])
const loading = ref(false)
const loadError = ref('')
const exporting = ref('')

const formats = [
  { code: 'pdf', label: 'PDF', class: 'bg-red-600 hover:bg-red-700' },
  { code: 'xlsx', label: 'Excel', class: 'bg-green-600 hover:bg-green-700' },
  { code: 'docx', label: 'Word', class: 'bg-blue-600 hover:bg-blue-700' },
  { code: 'csv', label: 'CSV', class: 'bg-gray-600 hover:bg-gray-700' },
]

const defaults = () => ({ from: '', to: '', org_unit_id: '', personnel_type_id: '' })
const filters = reactive(defaults())

// ไม่ส่งค่าว่างไปให้ API — ตัวกรองที่ไม่ได้เลือกต้องไม่ปรากฏใน query string
// และ from/to ต้องมาคู่กันเสมอ ไม่งั้น validation ฝั่ง server จะตีกลับ
const params = () => {
  const active = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
  if (!active.from || !active.to) {
    delete active.from
    delete active.to
  }
  return active
}

const totals = computed(() => {
  if (!dataset.value?.totals) return []
  return Object.entries(dataset.value.totals).map(([key, value]) => ({
    key,
    // ยอดรวมบางตัวไม่มีคอลัมน์คู่กัน ฝั่ง server จึงส่งชื่อไทยมาให้ใน total_labels
    label: dataset.value.columns?.[key] || dataset.value.total_labels?.[key] || key,
    value,
  }))
})

const isNumericColumn = (key) => {
  const first = dataset.value?.rows?.[0]?.[key]
  return typeof first === 'number'
}

const cell = (value) => {
  if (value === null || value === undefined || value === '') return '-'
  if (typeof value === 'boolean') return value ? 'ใช้งาน' : 'ปิดใช้งาน'
  if (typeof value === 'number') return new Intl.NumberFormat('th-TH', { maximumFractionDigits: 2 }).format(value)
  return value
}

const thaiDate = (value) => (value ? new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium' }).format(new Date(value)) : '-')

const loadReport = async () => {
  if (!activeReport.value) return
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get(`/reporting/${activeReport.value}`, { params: params() })
    dataset.value = data
  } catch (error) {
    dataset.value = null
    loadError.value = getErrorMessage(error, 'ไม่สามารถโหลดรายงานได้')
  } finally {
    loading.value = false
  }
}

const selectReport = (code) => {
  activeReport.value = code
  loadReport()
}

const resetFilters = () => {
  Object.assign(filters, defaults())
  loadReport()
}

const download = async (format) => {
  exporting.value = format
  try {
    const response = await api.get(`/reporting/${activeReport.value}/export/${format}`, {
      params: params(),
      responseType: 'blob',
    })
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = filenameFromHeaders(response.headers)
      || buildFilename([dataset.value?.title || activeReport.value], format)
    link.click()
    URL.revokeObjectURL(url)
    alerts.success('ส่งออกรายงานเรียบร้อยแล้ว')
  } catch (error) {
    alerts.error(getErrorMessage(error, 'ส่งออกรายงานไม่สำเร็จ'))
  } finally {
    exporting.value = ''
  }
}

onMounted(async () => {
  try {
    const { data } = await api.get('/reporting')
    reports.value = data.reports || []
    activeReport.value = reports.value[0]?.code || ''
  } catch (error) {
    loadError.value = getErrorMessage(error, 'ไม่สามารถโหลดสารบัญรายงานได้')
  } finally {
    catalogueLoaded.value = true
  }

  // ตัวกรองเป็นของเสริม ถ้าโหลดไม่ได้ก็ยังดูรายงานได้ตามขอบเขตปกติ
  const [units, types] = await Promise.allSettled([
    api.get('/reporting-org-units'),
    api.get('/personnel-types'),
  ])
  if (units.status === 'fulfilled') orgUnits.value = units.value.data
  if (types.status === 'fulfilled') personnelTypes.value = types.value.data

  await loadReport()
})
</script>
