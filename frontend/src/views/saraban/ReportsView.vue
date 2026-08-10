<template>
  <div class="p-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-6 gap-4">
      <h2 class="text-2xl font-bold text-gray-800">รายงานสถิติงานสารบรรณ</h2>
      
      <div class="flex items-center gap-3">
        <select v-model="selectedYear" @change="fetchData" class="border-gray-300 rounded-lg shadow-sm focus:border-[#800000] focus:ring focus:ring-[#800000]/20">
          <option v-for="y in availableYears" :key="y" :value="y">ปี พ.ศ. {{ y + 543 }}</option>
        </select>
        
        <button @click="exportData" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 flex items-center gap-2 transition-colors">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          ส่งออกรายงาน (Excel/CSV)
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center py-16">
      <svg class="animate-spin h-8 w-8 mx-auto mb-2 text-[#800000]" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
      </svg>
      <p class="text-gray-500">กำลังโหลดข้อมูล...</p>
    </div>

    <div v-else class="space-y-6">
      <!-- Top Cards -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 flex items-center gap-4">
          <div class="p-3 bg-blue-100 text-blue-600 rounded-lg">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          </div>
          <div>
            <p class="text-sm text-gray-500 font-medium">เอกสารทั้งหมดในปีนี้</p>
            <p class="text-2xl font-bold text-gray-800">{{ totalDocuments }}</p>
          </div>
        </div>
        
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 flex items-center gap-4">
          <div class="p-3 bg-orange-100 text-orange-600 rounded-lg">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
          </div>
          <div>
            <p class="text-sm text-gray-500 font-medium">เอกสารระดับด่วน</p>
            <p class="text-2xl font-bold text-gray-800">{{ urgentDocuments }}</p>
          </div>
        </div>
      </div>

      <!-- Charts Row -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Bar Chart: Monthly Documents -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 lg:col-span-2">
          <h3 class="font-bold text-gray-800 mb-4">ปริมาณเอกสารรับ-ส่งรายเดือน</h3>
          <div class="h-[300px] flex justify-center items-center">
            <Bar v-if="chartData.monthly" :data="chartData.monthly" :options="chartOptions.bar" />
          </div>
        </div>

        <!-- Pie Chart: Priorities -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
          <h3 class="font-bold text-gray-800 mb-4">สัดส่วนความเร่งด่วน</h3>
          <div class="h-[300px] flex justify-center items-center">
            <Pie v-if="chartData.priorities" :data="chartData.priorities" :options="chartOptions.pie" />
          </div>
        </div>
      </div>

      <!-- Table: Departments -->
      <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
        <h3 class="font-bold text-gray-800 mb-4">สถิติแยกตามฝ่าย/แผนก</h3>
        <div class="overflow-x-auto">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="bg-gray-50 border-b">
                <th class="p-3 font-medium text-sm text-gray-600">ลำดับ</th>
                <th class="p-3 font-medium text-sm text-gray-600">ฝ่าย/แผนก</th>
                <th class="p-3 font-medium text-sm text-gray-600 text-right">จำนวนเอกสาร (ฉบับ)</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(dept, index) in departmentsData" :key="index" class="border-b hover:bg-gray-50">
                <td class="p-3 text-sm text-gray-500">{{ index + 1 }}</td>
                <td class="p-3 text-sm font-medium text-gray-800">{{ dept.name }}</td>
                <td class="p-3 text-sm text-right font-bold text-[#800000]">{{ dept.count }}</td>
              </tr>
              <tr v-if="departmentsData.length === 0">
                <td colspan="3" class="p-6 text-center text-gray-500">ไม่มีข้อมูล</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '../../axios'
import {
  Chart as ChartJS,
  Title,
  Tooltip,
  Legend,
  BarElement,
  CategoryScale,
  LinearScale,
  ArcElement
} from 'chart.js'
import { Bar, Pie } from 'vue-chartjs'

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend, ArcElement)

const loading = ref(false)
const selectedYear = ref(new Date().getFullYear())
const availableYears = ref([])

// Generate last 5 years
for (let i = 0; i < 5; i++) {
  availableYears.value.push(new Date().getFullYear() - i)
}

const reportData = ref(null)

const chartData = ref({
  monthly: null,
  priorities: null
})

const chartOptions = {
  bar: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true } }
  },
  pie: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom' }
    }
  }
}

const departmentsData = computed(() => reportData.value?.departments || [])
const totalDocuments = computed(() => {
  if (!reportData.value) return 0
  return (reportData.value.monthly ?? []).reduce((a, b) => a + b, 0)
})

const urgentDocuments = computed(() => {
  if (!reportData.value?.priorities) return 0
  const p = reportData.value.priorities
  return (p.urgent || 0) + (p.very_urgent || 0) + (p.most_urgent || 0)
})

const fetchData = async () => {
  loading.value = true
  try {
    const res = await api.get(`/saraban/reports/summary?year=${selectedYear.value}`)
    reportData.value = res.data
    
    // Prepare monthly data
    chartData.value.monthly = {
      labels: ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'],
      datasets: [
        {
          label: 'จำนวนเอกสาร',
          backgroundColor: '#800000',
          data: res.data.monthly
        }
      ]
    }
    
    // Prepare priority data
    chartData.value.priorities = {
      labels: ['ปกติ', 'ด่วน', 'ด่วนมาก', 'ด่วนที่สุด'],
      datasets: [
        {
          backgroundColor: ['#e5e7eb', '#fbbf24', '#f97316', '#ef4444'],
          data: [
            res.data.priorities.normal || 0,
            res.data.priorities.urgent || 0,
            res.data.priorities.very_urgent || 0,
            res.data.priorities.most_urgent || 0
          ]
        }
      ]
    }
    
  } catch (error) {
    console.error('Failed to load report data', error)
  } finally {
    loading.value = false
  }
}

const exportData = async () => {
  try {
    const response = await api.get(`/saraban/reports/export?year=${selectedYear.value}`, {
      responseType: 'blob'
    })
    
    const url = window.URL.createObjectURL(new Blob([response.data]))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', `saraban_report_${selectedYear.value}.csv`)
    document.body.appendChild(link)
    link.click()
    link.remove()
  } catch (error) {
    console.error('Export failed', error)
    alert('เกิดข้อผิดพลาดในการดาวน์โหลดไฟล์')
  }
}

onMounted(() => {
  fetchData()
})
</script>
