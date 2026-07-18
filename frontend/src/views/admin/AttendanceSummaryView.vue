<template>
  <div>
    <!-- Header -->
    <div class="mb-6 flex justify-between items-end">
      <div>
        <h2 class="text-2xl font-bold text-gray-800">สรุปผลการปฏิบัติงาน</h2>
        <p class="text-gray-500 text-sm mt-1">สรุปเวลาปฏิบัติงานรายเดือนของบุคลากร</p>
      </div>
      <div class="flex gap-2">
        <button @click="previewReport"
          class="inline-flex items-center gap-2 px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
          </svg>
          ตรวจสอบข้อมูล
        </button>
        <button @click="exportPdf"
          class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
          ดาวน์โหลด PDF
        </button>
        <button @click="exportWord"
          class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
          ดาวน์โหลด Word
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">ปี (พ.ศ.)</label>
          <select v-model="filters.year" @change="fetchRecords" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y + 543 }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">เดือน</label>
          <select v-model="filters.month" @change="fetchRecords" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
            <option value="all">ตลอดทั้งปี (รายบุคคล)</option>
            <option v-for="(name, index) in thaiMonths" :key="index+1" :value="index+1">{{ name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">แผนก</label>
          <select v-model="filters.department_id" @change="fetchRecords" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
            <option value="">ทั้งหมด</option>
            <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">ค้นหาบุคคล</label>
          <select v-model="filters.user_id" @change="fetchRecords" class="w-full border-gray-300 rounded-lg text-sm focus:ring-[#800000] focus:border-[#800000]">
            <option value="">ทุกคน</option>
            <option v-for="user in filteredUsers" :key="user.id" :value="user.id">{{ user.name }} {{ user.last_name || '' }}</option>
          </select>
        </div>
      </div>
    </div>

    <!-- User Info for Yearly Mode -->
    <div v-if="isYearlyMode && yearlyUser" class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6 flex justify-between items-center">
      <div>
        <div class="text-lg font-bold text-gray-800">บุคลากร: {{ yearlyUser.name }}</div>
        <div class="text-sm text-gray-500">ตำแหน่ง: {{ yearlyUser.position }} | แผนกวิชา: {{ yearlyUser.department }}</div>
      </div>
    </div>

    <!-- Data Table -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead v-if="isYearlyMode">
            <tr class="bg-gray-50 border-b border-gray-100 text-sm text-gray-600">
              <th class="py-4 px-4 font-semibold whitespace-nowrap border-r border-gray-200">เดือน</th>
              <th v-for="day in 31" :key="day" class="py-4 px-2 font-semibold text-center border-r border-gray-200 min-w-[30px]">
                {{ day }}
              </th>
              <th class="py-4 px-4 font-semibold text-center text-[#800000] bg-red-50">รวม</th>
              <th class="py-4 px-4 font-semibold text-center text-blue-600 bg-blue-50">ลากิจ</th>
              <th class="py-4 px-4 font-semibold text-center text-blue-600 bg-blue-50">ป่วย</th>
              <th class="py-4 px-4 font-semibold text-center text-blue-600 bg-blue-50">ลาคลอด</th>
              <th class="py-4 px-4 font-semibold text-center text-blue-600 bg-blue-50">ราชการ</th>
            </tr>
          </thead>
          <thead v-else>
            <tr class="bg-gray-50 border-b border-gray-100 text-sm text-gray-600">
              <th class="py-4 px-4 font-semibold whitespace-nowrap border-r border-gray-200">ชื่อ-สกุล</th>
              <th class="py-4 px-4 font-semibold whitespace-nowrap border-r border-gray-200">ตำแหน่ง</th>
              <th v-for="day in daysInMonth" :key="day" class="py-4 px-2 font-semibold text-center border-r border-gray-200 min-w-[30px]">
                {{ day }}
              </th>
              <th class="py-4 px-4 font-semibold text-center text-[#800000] bg-red-50">รวม</th>
              <th class="py-4 px-4 font-semibold text-center text-blue-600 bg-blue-50">ลากิจ</th>
              <th class="py-4 px-4 font-semibold text-center text-blue-600 bg-blue-50">ป่วย</th>
              <th class="py-4 px-4 font-semibold text-center text-blue-600 bg-blue-50">ลาคลอด</th>
              <th class="py-4 px-4 font-semibold text-center text-blue-600 bg-blue-50">ราชการ</th>
            </tr>
          </thead>
          <tbody v-if="isYearlyMode" class="text-sm divide-y divide-gray-100">
            <tr v-if="loading">
              <td colspan="37" class="py-8 text-center text-gray-500">กำลังโหลดข้อมูล...</td>
            </tr>
            <tr v-else-if="errorMessage">
              <td colspan="37" class="py-8 text-center text-red-500 font-medium">{{ errorMessage }}</td>
            </tr>
            <tr v-else-if="records.length === 0">
              <td colspan="37" class="py-8 text-center text-gray-500">ไม่พบข้อมูล</td>
            </tr>
            <tr v-else v-for="record in records" :key="record.month_no" class="hover:bg-gray-50 transition-colors">
              <td class="py-3 px-4 whitespace-nowrap border-r border-gray-100">{{ record.month_name }}</td>
              <td v-for="day in 31" :key="day" class="py-3 px-2 text-center border-r border-gray-100 font-medium"
                :class="getStatusColor(day <= record.days_in_month ? record.attendances[day] : null)">
                <span v-if="day <= record.days_in_month">{{ record.attendances[day] !== null ? record.attendances[day] : '-' }}</span>
              </td>
              <td class="py-3 px-4 text-center font-bold text-[#800000] bg-red-50">{{ record.total_score }}</td>
              <td class="py-3 px-4 text-center font-bold text-blue-600 bg-blue-50">{{ record.total_personal || '-' }}</td>
              <td class="py-3 px-4 text-center font-bold text-blue-600 bg-blue-50">{{ record.total_sick || '-' }}</td>
              <td class="py-3 px-4 text-center font-bold text-blue-600 bg-blue-50">{{ record.total_maternity || '-' }}</td>
              <td class="py-3 px-4 text-center font-bold text-blue-600 bg-blue-50">{{ record.total_official || '-' }}</td>
            </tr>
          </tbody>
          <tbody v-else class="text-sm divide-y divide-gray-100">
            <tr v-if="loading">
              <td :colspan="daysInMonth + 7" class="py-8 text-center text-gray-500">กำลังโหลดข้อมูล...</td>
            </tr>
            <tr v-else-if="records.length === 0">
              <td :colspan="daysInMonth + 7" class="py-8 text-center text-gray-500">ไม่พบข้อมูล</td>
            </tr>
            <tr v-else v-for="record in records" :key="record.user_id" class="hover:bg-gray-50 transition-colors">
              <td class="py-3 px-4 whitespace-nowrap border-r border-gray-100">{{ record.name }}</td>
              <td class="py-3 px-4 whitespace-nowrap border-r border-gray-100">{{ record.position || '-' }}</td>
              
              <td v-for="day in daysInMonth" :key="day" class="py-3 px-2 text-center border-r border-gray-100 font-medium"
                :class="getStatusColor(record.attendances[day])">
                {{ record.attendances[day] !== null ? record.attendances[day] : '-' }}
              </td>
              
              <td class="py-3 px-4 text-center font-bold text-[#800000] bg-red-50">{{ record.total_score }}</td>
              <td class="py-3 px-4 text-center font-bold text-blue-600 bg-blue-50">{{ record.total_personal || '-' }}</td>
              <td class="py-3 px-4 text-center font-bold text-blue-600 bg-blue-50">{{ record.total_sick || '-' }}</td>
              <td class="py-3 px-4 text-center font-bold text-blue-600 bg-blue-50">{{ record.total_maternity || '-' }}</td>
              <td class="py-3 px-4 text-center font-bold text-blue-600 bg-blue-50">{{ record.total_official || '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useAuthStore } from '../../stores/auth'
import api from '../../axios'

const authStore = useAuthStore()

const loading = ref(false)
const errorMessage = ref('')
const records = ref([])
const departments = ref([])
const yearOptions = ref([])
const users = ref([])
const yearlyUser = ref(null)

const currentDate = new Date()
const filters = ref({
  month: currentDate.getMonth() + 1,
  year: currentDate.getFullYear(),
  department_id: '',
  user_id: ''
})

const isYearlyMode = computed(() => filters.value.month === 'all')

const thaiMonths = [
  'มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
  'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'
]

const daysInMonth = computed(() => {
  return new Date(filters.value.year, filters.value.month, 0).getDate()
})

const filteredUsers = computed(() => {
  if (!filters.value.department_id) return users.value
  return users.value.filter(u => u.department_id === filters.value.department_id)
})

const getStatusColor = (status) => {
  if (status === 1) return 'text-green-600'
  if (status === 0) return 'text-red-500'
  if (['ก', 'ป', 'ร', 'ด'].includes(status)) return 'text-blue-600'
  return 'text-gray-400'
}

const fetchDepartments = async () => {
  try {
    const res = await api.get('/admin/departments')
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

const fetchFiscalYears = async () => {
  try {
    const res = await api.get('/admin/fiscal-years')
    const cur = new Date().getFullYear()
    yearOptions.value = res.data.map(fy => fy.year > 2500 ? fy.year - 543 : fy.year).sort((a,b) => b - a)
    
    if (!yearOptions.value.includes(cur)) {
      yearOptions.value.push(cur)
      yearOptions.value.sort((a,b) => b - a)
    }
  } catch (error) {
    console.error(error)
    const cur = new Date().getFullYear()
    yearOptions.value = [cur + 1, cur, cur - 1]
  }
}

const fetchRecords = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get('/admin/attendances/summary', {
      params: filters.value
    })
    if (isYearlyMode.value) {
      records.value = res.data.months
      yearlyUser.value = res.data.user
    } else {
      records.value = res.data
      yearlyUser.value = null
    }
  } catch (error) {
    if (error.response?.data?.error) {
      errorMessage.value = error.response.data.error
    }
    records.value = []
    yearlyUser.value = null
  } finally {
    loading.value = false
  }
}

const previewReport = () => {
  const params = new URLSearchParams(filters.value).toString()
  const token = localStorage.getItem('token')
  const url = `${api.defaults.baseURL}/admin/attendances/summary/preview?${params}&token=${token}`
  window.open(url, '_blank')
}

const exportPdf = () => {
  const params = new URLSearchParams(filters.value).toString()
  const token = localStorage.getItem('token')
  const url = `${api.defaults.baseURL}/admin/attendances/summary/export/pdf?${params}&token=${token}`
  window.open(url, '_blank')
}

const exportWord = () => {
  const params = new URLSearchParams(filters.value).toString()
  const token = localStorage.getItem('token')
  const url = `${api.defaults.baseURL}/admin/attendances/summary/export/word?${params}&token=${token}`
  window.open(url, '_blank')
}

onMounted(async () => {
  await Promise.all([
    fetchFiscalYears(),
    fetchDepartments(),
    fetchUsers(),
    fetchRecords()
  ])
})
</script>
