<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
      <h2 class="text-2xl font-bold text-gray-800">ประวัติการลงเวลา</h2>
      
      <div class="flex flex-wrap gap-2">
        <select v-model="selectedMonth" class="border-gray-300 rounded-lg text-sm focus:border-[#800000] focus:ring-[#800000]">
          <option v-for="(name, index) in months" :key="index" :value="index + 1">{{ name }}</option>
        </select>
        <select v-model="selectedYear" class="border-gray-300 rounded-lg text-sm focus:border-[#800000] focus:ring-[#800000]">
          <option v-for="y in years" :key="y" :value="y">{{ y + 543 }}</option>
        </select>
        <button @click="fetchHistory" class="bg-[#800000] text-white px-4 py-2 rounded-lg text-sm hover:bg-[#600000]">
          ค้นหา
        </button>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-gray-50 border-b border-gray-100 text-gray-600 text-sm">
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
              <td colspan="7" class="py-8 text-center text-gray-500">กำลังโหลดข้อมูล...</td>
            </tr>
            <tr v-else-if="records.length === 0">
              <td colspan="7" class="py-8 text-center text-gray-500">ไม่มีข้อมูลในเดือนนี้</td>
            </tr>
            <tr v-else v-for="record in records" :key="record.id" class="border-b border-gray-50 hover:bg-gray-50/50">
              <td class="py-4 px-4 font-medium text-gray-900">
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
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../axios'
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'

const months = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม']
const currentYear = new Date().getFullYear()

const years = Array.from({length: 5}, (_, i) => currentYear - i)

const selectedMonth = ref(new Date().getMonth() + 1)
const selectedYear = ref(currentYear)
const records = ref([])
const loading = ref(false)

const fetchHistory = async () => {
  loading.value = true
  try {
    const res = await api.get('/attendances/me', {
      params: {
        month: selectedMonth.value,
        year: selectedYear.value
      }
    })
    records.value = res.data
  } catch (error) {
    console.error(error)
  } finally {
    loading.value = false
  }
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

onMounted(() => {
  fetchHistory()
})
</script>
