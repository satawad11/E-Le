<template>
  <div>
    <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">หน้าหลัก</h1>
        <p class="text-gray-500 mt-1">ยินดีต้อนรับ, {{ authStore.user?.name }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2 sm:gap-4">
        <router-link to="/request" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-[#800000] hover:bg-[#600000] text-white text-sm font-semibold rounded-xl transition-colors shadow-sm min-h-[44px]">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          ยื่นใบลาใหม่
        </router-link>
        <label v-if="isManager" class="flex items-center gap-2 text-sm text-gray-700 font-medium cursor-pointer">
          <input type="checkbox" v-model="viewSelf" @change="fetchDashboard" class="rounded text-[#800000] focus:ring-[#800000]">
          ดูเฉพาะข้อมูลของฉัน
        </label>
        <select v-model="selectedYear" @change="fetchDashboard" class="bg-white border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-[#800000] focus:border-[#800000] block p-2 sm:p-2.5 shadow-sm">
          <option v-for="fy in fiscalYearsList" :key="fy.year" :value="fy.year">ปีงบประมาณ {{ fy.year }} {{ fy.is_current ? '(ปัจจุบัน)' : '' }}</option>
        </select>
      </div>
    </div>

    <!-- Error State -->
    <div v-if="fetchError" class="py-6">
      <ErrorState :message="fetchError" @retry="loadDashboardData" />
    </div>

    <!-- Skeleton Loader -->
    <LoadingState v-else-if="loading" type="skeleton-card" :rows="3" message="" />


    <div v-else>
      <!-- Today's Attendance Card -->
      <div class="mb-8 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden flex flex-col md:flex-row items-center justify-between p-4 sm:p-6 bg-gradient-to-r from-gray-50 to-white gap-4">
        <div class="flex items-center gap-4">
          <div class="p-3 sm:p-4 bg-blue-100 text-blue-600 rounded-full shadow-inner flex-shrink-0">
            <svg class="w-6 h-6 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div>
            <h2 class="text-lg sm:text-xl font-bold text-gray-800">สถานะการลงเวลาวันนี้</h2>
            <div class="flex flex-wrap gap-2 sm:gap-4 mt-2 text-xs sm:text-sm">
              <div class="flex items-center gap-1 sm:gap-2">
                <span class="text-gray-500">เวลาเข้างาน:</span>
                <span class="font-bold text-sm sm:text-base" :class="todayAttendance?.check_in_time ? 'text-green-600' : 'text-gray-400'">
                  {{ formatTime(todayAttendance?.check_in_time) }} {{ todayAttendance?.check_in_time ? 'น.' : '' }}
                </span>
              </div>
              <div class="flex items-center gap-1 sm:gap-2 sm:border-l sm:pl-4">
                <span class="text-gray-500">เวลาออกงาน:</span>
                <span class="font-bold text-sm sm:text-base" :class="todayAttendance?.check_out_time ? 'text-green-600' : 'text-gray-400'">
                  {{ formatTime(todayAttendance?.check_out_time) }} {{ todayAttendance?.check_out_time ? 'น.' : '' }}
                </span>
              </div>
            </div>
          </div>
        </div>
        <div>
          <router-link to="/attendance/check-in" class="inline-flex items-center gap-2 px-6 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl transition-colors shadow-md">
            ไปหน้าลงเวลา
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
          </router-link>
        </div>
      </div>

      <!-- Stats cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">รออนุมัติ</p>
          <p class="text-3xl font-extrabold text-amber-600">{{ stats.pending }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">อนุมัติแล้ว</p>
          <p class="text-3xl font-extrabold text-green-600">{{ stats.approved }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">ไม่อนุมัติ</p>
          <p class="text-3xl font-extrabold text-red-600">{{ stats.rejected }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">ทั้งหมด</p>
          <p class="text-3xl font-extrabold text-[#800000]">{{ stats.total }}</p>
        </div>
      </div>

      <!-- Leave Balances -->
      <div class="mb-8">
        <h2 class="text-lg font-bold text-gray-800 mb-4">วันลาคงเหลือ ปี {{ selectedYear || '' }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <div v-for="balance in leaveStore.balances" :key="balance.id" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:-translate-y-0.5 transition-transform duration-200">
            <div class="flex justify-between items-start mb-3">
              <h3 class="font-semibold text-gray-800 text-sm">{{ balance.type.name }}</h3>
              <span v-if="balance.unlimited" class="text-xs font-bold px-2 py-0.5 rounded-full bg-blue-100 text-blue-700">ไม่จำกัดโควตา</span>
              <span v-else :class="[
                'text-xs font-bold px-2 py-0.5 rounded-full',
                (balance.quota + (balance.carried_forward || 0) - balance.used - (balance.pending || 0)) <= 2 ? 'bg-red-100 text-red-700' :
                (balance.quota + (balance.carried_forward || 0) - balance.used - (balance.pending || 0)) <= 5 ? 'bg-amber-100 text-amber-700' :
                'bg-green-100 text-green-700'
              ]">เหลือ {{ balance.quota + (balance.carried_forward || 0) - balance.used - (balance.pending || 0) }} วัน</span>
            </div>
            <div class="flex items-baseline gap-1 mb-2">
              <span class="text-4xl font-extrabold text-[#800000]">{{ balance.unlimited ? balance.used : balance.quota + (balance.carried_forward || 0) - balance.used - (balance.pending || 0) }}</span>
              <span class="text-gray-400 text-sm flex flex-col gap-0.5">
                <span>{{ balance.unlimited ? 'วันที่ใช้ไป' : `/ ${balance.quota + (balance.carried_forward || 0)} วัน` }}</span>
                <span v-if="balance.carried_forward" class="text-xs text-blue-600 font-medium">(มีวันทบยอด {{ balance.carried_forward }} วัน)</span>
              </span>
            </div>
            <div class="h-2 bg-gray-100 rounded-full overflow-hidden flex">
              <div
                :class="[
                  'h-full transition-all duration-500',
                  (balance.used / (balance.quota + (balance.carried_forward || 0))) >= 0.8 ? 'bg-red-500' :
                  (balance.used / (balance.quota + (balance.carried_forward || 0))) >= 0.5 ? 'bg-amber-400' : 'bg-[#800000]'
                ]"
                :style="{ width: `${Math.min((balance.used / (balance.quota + (balance.carried_forward || 0))) * 100, 100)}%` }"
              ></div>
              <div
                v-if="balance.pending"
                class="h-full bg-amber-300 transition-all duration-500 opacity-80"
                :style="{ width: `${Math.min(((balance.pending || 0) / (balance.quota + (balance.carried_forward || 0))) * 100, 100)}%` }"
              ></div>
            </div>
            <div class="flex justify-between text-xs text-gray-400 mt-1.5">
              <span>ใช้ไปแล้ว {{ balance.used }} วัน</span>
              <span v-if="balance.pending">รออนุมัติ {{ balance.pending }} วัน</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Leave Frequency By Type -->
      <div v-if="stats.by_type && stats.by_type.length > 0" class="mb-8">
        <h2 class="text-lg font-bold text-gray-800 mb-4">สถิติการลาแยกตามประเภท (ความถี่/จำนวนครั้ง)</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div v-for="(item, idx) in stats.by_type" :key="idx" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 hover:shadow-md transition-shadow">
            <h3 class="font-semibold text-gray-800 text-sm mb-2">{{ item.type_name }}</h3>
            <div class="flex flex-col gap-1">
              <div class="flex justify-between items-end">
                <span class="text-xs text-gray-500">จำนวนครั้ง</span>
                <span class="text-xl font-bold text-[#800000]">{{ item.count }} <span class="text-xs text-gray-400 font-normal">ครั้ง</span></span>
              </div>
              <div class="flex justify-between items-end">
                <span class="text-xs text-gray-500">รวมจำนวนวัน</span>
                <span class="text-xl font-bold text-[#800000]">{{ item.days }} <span class="text-xs text-gray-400 font-normal">วัน</span></span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Monthly bar chart -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
          <div class="flex justify-between items-center mb-5">
            <h2 class="text-lg font-bold text-gray-800">สถิติการลารายเดือน (ปีนี้)</h2>
          </div>
          <div class="h-64 w-full">
            <Bar v-if="stats.monthly && stats.monthly.length > 0" :data="chartData" :options="chartOptions" />
            <div v-else class="h-full flex items-center justify-center text-gray-400 text-sm bg-gray-50 rounded-xl border border-dashed border-gray-200">ไม่พบข้อมูลสถิติในปีนี้</div>
          </div>
        </div>

        <!-- Recent requests -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
          <h2 class="text-base font-bold text-gray-800 mb-4">คำขอล่าสุด</h2>
          <div v-if="myLeaves.length === 0" class="text-center text-gray-400 py-6 text-sm">
            ยังไม่มีประวัติการลา
          </div>
          <div v-else class="space-y-3">
            <div v-for="leave in myLeaves.slice(0, 4)" :key="leave.id" class="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
              <div>
                <p class="text-sm font-medium text-gray-800">{{ leave.type.name }}</p>
                <p class="text-xs text-gray-400">{{ leave.total_days }} วัน</p>
              </div>
              <span :class="[
                'text-xs font-semibold px-2 py-0.5 rounded-full',
                statusBadgeClass(leave.status)
              ]">{{ statusLabel(leave.status) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Full leave history table -->
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <h2 class="text-lg font-bold text-gray-800">ประวัติการลาทั้งหมด</h2>
          <!-- Status filter tabs -->
          <div class="flex gap-1 bg-gray-100 p-1 rounded-xl text-xs font-semibold">
            <button v-for="f in filters" :key="f.value"
              @click="activeFilter = f.value; currentPage = 1"
              :class="['px-3 py-1.5 rounded-lg transition-all', activeFilter === f.value ? 'bg-[#800000] text-white shadow-sm' : 'text-gray-500 hover:text-gray-700']"
            >{{ f.label }}</button>
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
              <tr>
                <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase">ชื่อ-นามสกุล</th>
                <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase">ประเภทลา</th>
                <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase">ช่วงวันที่</th>
                <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase">สถานะ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-if="filteredLeaves.length === 0">
                <td colspan="5" class="text-center py-10">
                  <div class="text-center text-gray-400 text-sm font-medium">ยังไม่มีรายการใบลาในเงื่อนไขนี้</div>
                </td>
              </tr>
              <tr v-for="leave in paginatedLeaves" :key="leave.id" class="hover:bg-gray-50 transition-colors">
                <td class="px-6 py-4 font-medium text-gray-900">{{ leave.user?.name || authStore.user?.name }}</td>
                <td class="px-6 py-4 text-gray-600">{{ leave.type.name }}</td>
                <td class="px-6 py-4 text-gray-600">{{ ThaiDateFormatter.toThaiSlash(leave.start_date) }} — {{ ThaiDateFormatter.toThaiSlash(leave.end_date) }}</td>
                <td class="px-6 py-4">
                  <span :class="[
                    'px-2.5 py-1 text-xs font-bold rounded-full',
                    statusBadgeClass(leave.status)
                  ]">{{ statusLabel(leave.status) }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <!-- Pagination -->
        <div v-if="totalPages > 1" class="px-6 py-4 border-t border-gray-100 flex items-center justify-between">
          <span class="text-sm text-gray-500">แสดง {{ (currentPage - 1) * itemsPerPage + 1 }} ถึง {{ Math.min(currentPage * itemsPerPage, filteredLeaves.length) }} จาก {{ filteredLeaves.length }} รายการ</span>
          <div class="flex items-center gap-1">
            <button @click="changePage(currentPage - 1)" :disabled="currentPage === 1" class="px-3 py-1 text-sm border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
              ก่อนหน้า
            </button>
            <span class="text-sm text-gray-700 px-3">หน้า {{ currentPage }} / {{ totalPages }}</span>
            <button @click="changePage(currentPage + 1)" :disabled="currentPage === totalPages" class="px-3 py-1 text-sm border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
              ถัดไป
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useLeaveStore } from '../stores/leave'
import { useAuthStore } from '../stores/auth'
import { useAlertStore } from '../stores/alert'
import api from '../axios'
import { ThaiDateFormatter } from '../utils/ThaiDateFormatter'
import LoadingState from '../components/ui/LoadingState.vue'
import { statusLabel, statusBadgeClass } from '../utils/statusHelper'
import { getErrorMessage } from '../utils/errorHandler'
import ErrorState from '../components/ui/ErrorState.vue'
import EmptyState from '../components/ui/EmptyState.vue'

import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale } from 'chart.js'
import { Bar } from 'vue-chartjs'

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend)

const leaveStore = useLeaveStore()
const authStore = useAuthStore()
const alertStore = useAlertStore()
const loading = ref(true)
const fetchError = ref('')
const pdfLoading = ref(null)
const wordLoading = ref(null)
const stats = ref({ pending: 0, approved: 0, rejected: 0, total: 0, monthly: [] })
const activeFilter = ref('all')
const currentYear = new Date().getFullYear() + 543

const currentPage = ref(1)
const itemsPerPage = 10

const generateFilename = (leave, extension) => {
  const leaveTypeName = leave?.type?.name || 'การลา'
  const userName = (leave?.user?.name || authStore.user?.name || '').trim().replace(/ /g, '_')
  const docNumber = leave?.document_number || leave?.id
  
  let baseFilename = `${leaveTypeName}_${userName}_${docNumber}`
  baseFilename = baseFilename.replace(/[\/\\]/g, '_')
  
  return `${baseFilename}.${extension}`
}

const downloadPdf = async (leave) => {
  if (pdfLoading.value === leave.id) return
  pdfLoading.value = leave.id
  try {
    const response = await api.get(`/leaves/${leave.id}/pdf`, { responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', generateFilename(leave, 'pdf'))
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (e) {
    console.error('PDF download error:', e)
    alertStore.error('ไม่สามารถดาวน์โหลด PDF ได้ กรุณาลองใหม่')
  } finally {
    pdfLoading.value = null
  }
}

const downloadWord = async (leave) => {
  if (wordLoading.value === leave.id) return
  wordLoading.value = leave.id
  try {
    const response = await api.get(`/leaves/${leave.id}/word`, { responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([response.data], { type: 'application/msword' }))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', generateFilename(leave, 'doc'))
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (e) {
    console.error('Word error:', e)
    alertStore.error('ไม่สามารถดาวน์โหลด Word ได้ กรุณาลองใหม่')
  } finally {
    wordLoading.value = null
  }
}

const filters = [
  { label: 'ทั้งหมด',    value: 'all'       },
  { label: 'รออนุมัติ',  value: 'Pending'   },
  { label: 'อนุมัติแล้ว', value: 'Approved'  },
  { label: 'ไม่อนุมัติ', value: 'Rejected'  },
  { label: 'ยกเลิกแล้ว', value: 'Cancelled' },
]

const maxMonthly = computed(() => {
  const max = Math.max(...(stats.value.monthly?.map(m => m.count) || [0]))
  return max === 0 ? 1 : max
})

const isManager = computed(() => {
  return authStore.isManager || authStore.isHRHead || authStore.isSuperAdmin
})

const fiscalYearsList = ref([])
const selectedYear = ref(null)
const viewSelf = ref(false)

// กรองเฉพาะใบลาของตัวเอง (ไม่รวมใบลาของคนอื่นที่รอ manager อนุมัติ) ยกเว้น manager เลือกดูของทุกคน
const myLeaves = computed(() => {
  let list = leaveStore.leaves
  if (!isManager.value || viewSelf.value) {
    list = list.filter(l => l.user_id === authStore.user?.id)
  }
  return list
})

const filteredLeaves = computed(() => {
  if (activeFilter.value === 'all') return myLeaves.value
  return myLeaves.value.filter(l => l.status === activeFilter.value)
})

const totalPages = computed(() => Math.ceil(filteredLeaves.value.length / itemsPerPage))

const paginatedLeaves = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage
  return filteredLeaves.value.slice(start, start + itemsPerPage)
})

const changePage = (p) => {
  if (p >= 1 && p <= totalPages.value) {
    currentPage.value = p
  }
}

const loadDashboardData = async () => {
  fetchError.value = ''
  leaveStore.fetchLeaves()
  await fetchDashboard()
  fetchTodayAttendance()
}

const fetchDashboard = async () => {
  loading.value = true
  fetchError.value = ''
  try {
    const params = { view_self: viewSelf.value }
    if (selectedYear.value) {
      params.fiscal_year = selectedYear.value
    }
    const dashRes = await api.get('/dashboard', { params })
    stats.value = dashRes.data
    
    // Populate fiscal years list from backend
    if (dashRes.data.fiscal_years && dashRes.data.fiscal_years.length > 0) {
      fiscalYearsList.value = dashRes.data.fiscal_years
    }
    
    // Set default selected year from backend
    if (!selectedYear.value && dashRes.data.fiscal_year) {
      selectedYear.value = dashRes.data.fiscal_year
    }

    // Re-fetch balances for the selected fiscal year
    leaveStore._fetchingBalances = false // reset guard
    leaveStore.fetchBalances(selectedYear.value)
  } catch (e) {
    console.error(e)
    fetchError.value = getErrorMessage(e, 'ไม่สามารถโหลดข้อมูลสถิติหน้าหลักได้')
  } finally {
    loading.value = false
  }
}

const todayAttendance = ref(null)

const formatTime = (datetimeStr) => {
  if (!datetimeStr) return '-'
  const d = new Date(datetimeStr)
  return d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })
}

const fetchTodayAttendance = async () => {
  try {
    const res = await api.get('/attendances/today')
    todayAttendance.value = res.data
  } catch (error) {
    console.error('Error fetching today attendance:', error)
  }
}

const chartData = computed(() => {
  return {
    labels: stats.value.monthly?.map(m => m.month) || [],
    datasets: [
      {
        label: 'จำนวนใบลา',
        backgroundColor: 'rgba(128, 0, 0, 0.85)',
        hoverBackgroundColor: 'rgba(128, 0, 0, 1)',
        borderRadius: 6,
        data: stats.value.monthly?.map(m => m.count) || []
      }
    ]
  }
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      display: false
    },
    tooltip: {
      backgroundColor: 'rgba(0, 0, 0, 0.8)',
      padding: 12,
      titleFont: { size: 13, family: "'IBM Plex Sans Thai', sans-serif" },
      bodyFont: { size: 12, family: "'IBM Plex Sans Thai', sans-serif" },
      cornerRadius: 8,
      displayColors: false,
    }
  },
  scales: {
    y: {
      beginAtZero: true,
      grid: {
        color: '#f3f4f6',
        drawBorder: false,
      },
      ticks: {
        stepSize: 1,
        font: { family: "'IBM Plex Sans Thai', sans-serif" }
      }
    },
    x: {
      grid: {
        display: false,
        drawBorder: false,
      },
      ticks: {
        font: { family: "'IBM Plex Sans Thai', sans-serif" }
      }
    }
  },
  animation: {
    duration: 1000,
    easing: 'easeOutQuart'
  }
}

onMounted(async () => {
  leaveStore.fetchLeaves()
  fetchDashboard()
  fetchTodayAttendance()
})
</script>
