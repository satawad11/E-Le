<template>
  <div>
    <!-- Header -->
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">รายงานสถิติการลา</h1>
      <p class="text-gray-500 mt-1 text-sm">สรุปข้อมูลการลาในองค์กร</p>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-6">
      <div class="flex flex-wrap gap-4 items-end">
        <div>
          <label class="block text-xs font-semibold text-gray-500 mb-1.5">ปี (พ.ศ.)</label>
          <select v-model="selectedYear" @change="loadReport"
            class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#800000]/30 focus:border-[#800000]">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y + 543 }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-500 mb-1.5">เดือน</label>
          <select v-model="selectedMonth" @change="loadReport"
            class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#800000]/30 focus:border-[#800000]">
            <option value="">ทั้งปี</option>
            <option v-for="(name, idx) in thaiMonths" :key="idx+1" :value="idx+1">{{ name }}</option>
          </select>
        </div>
        <div v-if="canSelectUser">
          <label class="block text-xs font-semibold text-gray-500 mb-1.5">บุคคล</label>
          <select v-model="selectedUser" @change="loadReport"
            class="border border-gray-200 rounded-xl px-3 py-2 text-sm max-w-[200px] truncate focus:outline-none focus:ring-2 focus:ring-[#800000]/30 focus:border-[#800000]">
            <option value="">ทุกคน</option>
            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
          </select>
        </div>
        <div class="flex gap-2 ml-auto">
          <button @click="exportExcel"
            :disabled="exporting === 'excel'"
            class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-xl transition-colors disabled:opacity-60">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            {{ exporting === 'excel' ? 'กำลัง Export...' : 'Export CSV' }}
          </button>
          <button @click="exportPdf"
            :disabled="exporting === 'pdf'"
            class="inline-flex items-center gap-2 px-4 py-2 bg-[#800000] hover:bg-[#600000] text-white text-sm font-semibold rounded-xl transition-colors disabled:opacity-60">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            {{ exporting === 'pdf' ? 'กำลัง Export...' : 'Export PDF' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex justify-center py-16">
      <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <!-- Error / No Data -->
    <div v-else-if="!summary" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-10 text-center">
      <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
      </svg>
      <h3 class="text-lg font-bold text-gray-900">ไม่สามารถโหลดข้อมูลได้</h3>
      <p class="text-gray-500 text-sm mt-1">กรุณาลองใหม่อีกครั้ง หรือไม่มีข้อมูลสำหรับช่วงเวลานี้</p>
    </div>

    <div v-else>
      <!-- Summary cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">คำขอทั้งหมด</p>
          <p class="text-3xl font-extrabold text-[#800000]">{{ summary.total_requests }}</p>
          <p class="text-xs text-gray-400 mt-0.5">รายการ</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">วันลาทั้งหมด</p>
          <p class="text-3xl font-extrabold text-amber-600">{{ summary.total_days }}</p>
          <p class="text-xs text-gray-400 mt-0.5">วัน</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">ประเภทหลัก</p>
          <p class="text-3xl font-extrabold text-blue-600">{{ topType?.type_name ?? '—' }}</p>
          <p class="text-xs text-gray-400 mt-0.5">{{ topType?.count ?? 0 }} ครั้ง</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">เดือนสูงสุด</p>
          <p class="text-3xl font-extrabold text-purple-600">{{ peakMonth?.label ?? '—' }}</p>
          <p class="text-xs text-gray-400 mt-0.5">{{ peakMonth?.count ?? 0 }} รายการ</p>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Monthly bar chart -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
          <h2 class="text-base font-bold text-gray-800 mb-5">สถิติรายเดือน</h2>
          <div class="flex items-end gap-1.5 h-36">
            <div v-for="(m, i) in (summary.monthly || [])" :key="i" class="flex-1 flex flex-col items-center gap-1">
              <span class="text-xs font-bold text-gray-500">{{ m.count || '' }}</span>
              <div
                class="w-full rounded-t-md bg-[#800000] transition-all duration-700"
                :style="{
                  height: m.count ? `${Math.max((m.count / maxMonthly) * 100, 8)}%` : '4%',
                  opacity: m.count ? 1 : 0.12
                }">
              </div>
              <span class="text-[10px] text-gray-400">{{ shortMonths[i] }}</span>
            </div>
          </div>
        </div>

        <!-- By type donut -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
          <h2 class="text-base font-bold text-gray-800 mb-4">สัดส่วนตามประเภทลา</h2>
          <div v-if="!summary.by_type || summary.by_type.length === 0" class="text-center text-gray-400 py-8 text-sm">
            ไม่มีข้อมูล
          </div>
          <div v-else class="space-y-3">
            <div v-for="t in summary.by_type" :key="t.type_id" class="flex items-center gap-3">
              <div class="flex-1">
                <div class="flex justify-between text-xs mb-1">
                  <span class="font-medium text-gray-700">{{ t.type_name }}</span>
                  <span class="text-gray-500">{{ t.count }} ครั้ง / {{ t.total_days }} วัน</span>
                </div>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                  <div
                    class="h-full rounded-full bg-[#800000] transition-all duration-700"
                    :style="{ width: `${summary.total_requests ? (t.count / summary.total_requests) * 100 : 0}%` }">
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Detailed table by type -->
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
          <h2 class="text-base font-bold text-gray-800">สรุปตามประเภทการลา</h2>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
              <tr>
                <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase">ประเภทการลา</th>
                <th class="text-right px-6 py-3 text-xs font-semibold text-gray-500 uppercase">จำนวนครั้ง</th>
                <th class="text-right px-6 py-3 text-xs font-semibold text-gray-500 uppercase">รวมวันลา</th>
                <th class="text-right px-6 py-3 text-xs font-semibold text-gray-500 uppercase">สัดส่วน</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-if="!summary.by_type || summary.by_type.length === 0">
                <td colspan="4" class="text-center text-gray-400 py-8">ไม่มีข้อมูล</td>
              </tr>
              <tr v-for="t in (summary.by_type || [])" :key="t.type_id" class="hover:bg-gray-50 transition-colors">
                <td class="px-6 py-3.5 font-medium text-gray-900">{{ t.type_name }}</td>
                <td class="px-6 py-3.5 text-right text-gray-700">{{ t.count }}</td>
                <td class="px-6 py-3.5 text-right text-gray-700">{{ t.total_days }} วัน</td>
                <td class="px-6 py-3.5 text-right">
                  <span class="text-xs font-semibold text-[#800000]">
                    {{ summary.total_requests ? Math.round((t.count / summary.total_requests) * 100) : 0 }}%
                  </span>
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-gray-50 border-t border-gray-200">
              <tr>
                <td class="px-6 py-3 font-bold text-gray-800">รวม</td>
                <td class="px-6 py-3 text-right font-bold text-gray-800">{{ summary.total_requests }}</td>
                <td class="px-6 py-3 text-right font-bold text-gray-800">{{ summary.total_days }} วัน</td>
                <td class="px-6 py-3 text-right font-bold text-[#800000]">100%</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'
import api from '../axios'

const authStore = useAuthStore()

const loading      = ref(true)
const exporting    = ref(null)
const summary      = ref(null)
const selectedYear  = ref(new Date().getFullYear())
const selectedMonth = ref('')
const selectedUser  = ref('')
const users         = ref([])

const canSelectUser = computed(() => authStore.user?.role?.name !== 'Employee')

const thaiMonths = [
  'มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
  'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'
]
const shortMonths = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.']

const yearOptions = ref([])

const maxMonthly = computed(() => {
  const max = Math.max(...(summary.value?.monthly?.map(m => m.count) || [0]))
  return max === 0 ? 1 : max
})

const topType = computed(() => {
  if (!summary.value?.by_type?.length) return null
  return [...summary.value.by_type].sort((a, b) => b.count - a.count)[0]
})

const peakMonth = computed(() => {
  if (!summary.value?.monthly?.length) return null
  const peak = [...summary.value.monthly].sort((a, b) => b.count - a.count)[0]
  if (!peak.count) return null
  return { label: shortMonths[peak.month - 1], count: peak.count }
})

const loadReport = async () => {
  loading.value = true
  try {
    const params = { year: selectedYear.value }
    if (selectedMonth.value) params.month = selectedMonth.value
    if (selectedUser.value && canSelectUser.value) params.user_id = selectedUser.value
    const res = await api.get('/reports/summary', { params })
    summary.value = res.data.summary
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

const initData = async () => {
  loading.value = true
  try {
    try {
      const allFyRes = await api.get('/admin/fiscal-years')
      if (allFyRes.data && allFyRes.data.length > 0) {
        yearOptions.value = allFyRes.data.map(fy => fy.year - 543).sort()
      } else {
        const cur = new Date().getFullYear()
        yearOptions.value = [cur - 2, cur - 1, cur, cur + 1]
      }
    } catch (e) {
      console.error('Failed to fetch fiscal years', e)
      const cur = new Date().getFullYear()
      yearOptions.value = [cur - 2, cur - 1, cur, cur + 1]
    }

    try {
      const fyRes = await api.get('/fiscal-year/current')
      if (fyRes.data && fyRes.data.year) {
        selectedYear.value = fyRes.data.year - 543
      }
    } catch (e) {
      console.error('Failed to fetch current fiscal year', e)
    }

    if (canSelectUser.value) {
      try {
        const uRes = await api.get('/users/list')
        users.value = uRes.data
      } catch (e) {
        console.error('Failed to fetch users', e)
      }
    }

    await loadReport()
  } finally {
    loading.value = false
  }
}

const exportExcel = async () => {
  exporting.value = 'excel'
  try {
    const params = { year: selectedYear.value }
    if (selectedMonth.value) params.month = selectedMonth.value
    if (selectedUser.value && canSelectUser.value) params.user_id = selectedUser.value
    const res = await api.get('/reports/export-excel', { params, responseType: 'blob' })
    const year  = selectedYear.value + 543
    const month = selectedMonth.value ? `_${thaiMonths[selectedMonth.value - 1]}` : ''
    const url   = window.URL.createObjectURL(new Blob([res.data]))
    const link  = document.createElement('a')
    link.href = url
    link.setAttribute('download', `รายงานการลา_${year}${month}.csv`)
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (e) {
    alert('Export ไม่สำเร็จ')
  } finally {
    exporting.value = null
  }
}

const exportPdf = async () => {
  exporting.value = 'pdf'
  try {
    const params = { year: selectedYear.value }
    if (selectedMonth.value) params.month = selectedMonth.value
    if (selectedUser.value && canSelectUser.value) params.user_id = selectedUser.value
    const res = await api.get('/reports/export-pdf', { params, responseType: 'blob' })
    const year  = selectedYear.value + 543
    const month = selectedMonth.value ? `_${thaiMonths[selectedMonth.value - 1]}` : ''
    const url   = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    const link  = document.createElement('a')
    link.href = url
    link.setAttribute('download', `รายงานการลา_${year}${month}.pdf`)
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (e) {
    alert('Export PDF ไม่สำเร็จ')
  } finally {
    exporting.value = null
  }
}

onMounted(initData)
</script>
