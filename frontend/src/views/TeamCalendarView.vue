<template>
  <div>
    <!-- Header -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">ปฏิทินวันลาทีม</h1>
        <p class="text-gray-500 mt-1 text-sm">ดูตารางการลางานของบุคลากรในทีมหรือหน่วยงานของคุณ</p>
      </div>
      <div class="flex items-center gap-3">
        <div class="flex items-center gap-2 text-xs font-semibold text-gray-600 bg-white px-3 py-1.5 rounded-lg border border-gray-200">
          <span class="w-3 h-3 rounded-full bg-blue-500"></span> รออนุมัติ
          <span class="w-3 h-3 rounded-full bg-green-500 ml-2"></span> อนุมัติแล้ว
        </div>
      </div>
    </div>

    <!-- Calendar Wrapper -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
      <!-- Calendar Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 bg-gray-50">
        <button @click="prevMonth" class="p-2 hover:bg-gray-200 rounded-lg transition-colors">
          <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </button>
        <h2 class="text-lg font-bold text-gray-800">{{ monthNames[currentMonth] }} {{ currentYear + 543 }}</h2>
        <button @click="nextMonth" class="p-2 hover:bg-gray-200 rounded-lg transition-colors">
          <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </button>
      </div>

      <!-- Calendar Grid -->
      <div class="grid grid-cols-7 border-b border-gray-100">
        <div v-for="day in ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.']" :key="day" class="py-2 text-center text-xs font-semibold text-gray-500 border-r border-gray-100 last:border-r-0">
          {{ day }}
        </div>
      </div>

      <div v-if="loading" class="flex justify-center py-20">
        <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
      </div>
      <div v-else class="grid grid-cols-7 auto-rows-fr">
        <div v-for="(day, idx) in calendarDays" :key="idx" 
             class="min-h-[100px] p-2 border-r border-b border-gray-100 relative group transition-colors"
             :class="{ 'bg-gray-50': !day.isCurrentMonth, 'hover:bg-gray-50': day.isCurrentMonth }">
          
          <div class="text-right mb-1">
            <span :class="[
              'inline-flex items-center justify-center w-6 h-6 text-sm font-semibold rounded-full',
              isToday(day.date) ? 'bg-[#800000] text-white' : (day.isCurrentMonth ? 'text-gray-700' : 'text-gray-300')
            ]">
              {{ day.date.getDate() }}
            </span>
          </div>

          <div class="space-y-1">
            <div v-for="leave in getLeavesForDate(day.date)" :key="leave.id"
                 class="px-2 py-1 text-xs rounded truncate cursor-pointer transition-transform hover:scale-[1.02]"
                 :class="[
                   leave.status === 'Approved' ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-blue-100 text-blue-800 border border-blue-200'
                 ]"
                 :title="`${leave.user?.name} - ${leave.type?.name}\nสถานะ: ${leave.status}`"
                 @click="showLeaveDetails(leave)">
              <span class="font-semibold">{{ leave.user?.name?.split(' ')[0] }}</span> - {{ leave.type?.name }}
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- Leave Details Modal -->
    <transition name="fade">
      <div v-if="selectedLeave" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 overflow-y-auto" @click.self="selectedLeave = null">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden my-auto max-h-[90vh] flex flex-col">
          <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50 flex-shrink-0">
            <h3 class="font-bold text-gray-900">รายละเอียดการลา</h3>
            <button @click="selectedLeave = null" class="p-1.5 text-gray-400 hover:text-gray-600 rounded-lg transition-colors">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
          <div class="p-6 space-y-4 overflow-y-auto flex-1">
            <div>
              <p class="text-xs text-gray-500 font-semibold mb-1">บุคลากร</p>
              <p class="text-sm font-medium text-gray-900">{{ selectedLeave.user?.name }}</p>
            </div>
            <div>
              <p class="text-xs text-gray-500 font-semibold mb-1">ประเภทการลา</p>
              <p class="text-sm font-medium text-gray-900">{{ selectedLeave.type?.name }}</p>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <p class="text-xs text-gray-500 font-semibold mb-1">เริ่ม</p>
                <p class="text-sm text-gray-900">{{ formatDate(selectedLeave.start_date) }}</p>
              </div>
              <div>
                <p class="text-xs text-gray-500 font-semibold mb-1">ถึง</p>
                <p class="text-sm text-gray-900">{{ formatDate(selectedLeave.end_date) }}</p>
              </div>
            </div>
            <div>
              <p class="text-xs text-gray-500 font-semibold mb-1">สถานะ</p>
              <span :class="[
                'inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold',
                selectedLeave.status === 'Approved' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700'
              ]">{{ selectedLeave.status === 'Approved' ? 'อนุมัติแล้ว' : 'รออนุมัติ' }}</span>
            </div>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../axios'
import { ThaiDateFormatter } from '../utils/ThaiDateFormatter'


const currentDate = new Date()
const currentMonth = ref(currentDate.getMonth())
const currentYear = ref(currentDate.getFullYear())
const leaves = ref([])
const loading = ref(true)
const selectedLeave = ref(null)

const monthNames = [
  'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
  'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
]

const fetchTeamLeaves = async () => {
  loading.value = true
  try {
    const res = await api.get('/leaves/team-calendar')
    leaves.value = res.data
  } catch {
    console.error('Failed to fetch team leaves')
  } finally {
    loading.value = false
  }
}

const calendarDays = computed(() => {
  const firstDayOfMonth = new Date(currentYear.value, currentMonth.value, 1)
  const lastDayOfMonth = new Date(currentYear.value, currentMonth.value + 1, 0)
  
  const days = []
  
  // Previous month overflow
  const startDayOfWeek = firstDayOfMonth.getDay()
  for (let i = startDayOfWeek - 1; i >= 0; i--) {
    const d = new Date(currentYear.value, currentMonth.value, -i)
    days.push({ date: d, isCurrentMonth: false })
  }
  
  // Current month
  for (let i = 1; i <= lastDayOfMonth.getDate(); i++) {
    const d = new Date(currentYear.value, currentMonth.value, i)
    days.push({ date: d, isCurrentMonth: true })
  }
  
  // Next month overflow to fill grid (6 rows = 42 cells)
  const remainingCells = 42 - days.length
  for (let i = 1; i <= remainingCells; i++) {
    const d = new Date(currentYear.value, currentMonth.value + 1, i)
    days.push({ date: d, isCurrentMonth: false })
  }
  
  return days
})

const getLeavesForDate = (date) => {
  // Reset time for accurate date comparison
  const compareDate = new Date(date)
  compareDate.setHours(0, 0, 0, 0)

  return leaves.value.filter(leave => {
    const start = new Date(leave.start_date)
    start.setHours(0, 0, 0, 0)
    const end = new Date(leave.end_date)
    end.setHours(0, 0, 0, 0)
    return compareDate >= start && compareDate <= end
  })
}

const isToday = (date) => {
  const today = new Date()
  return date.getDate() === today.getDate() &&
         date.getMonth() === today.getMonth() &&
         date.getFullYear() === today.getFullYear()
}

const prevMonth = () => {
  if (currentMonth.value === 0) {
    currentMonth.value = 11
    currentYear.value--
  } else {
    currentMonth.value--
  }
}

const nextMonth = () => {
  if (currentMonth.value === 11) {
    currentMonth.value = 0
    currentYear.value++
  } else {
    currentMonth.value++
  }
}

const showLeaveDetails = (leave) => {
  selectedLeave.value = leave
}

const formatDate = (dateStr) => {
  if (!dateStr) return '-'
  return ThaiDateFormatter.toThaiShortText(dateStr)
}


onMounted(() => {
  fetchTeamLeaves()
})
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
