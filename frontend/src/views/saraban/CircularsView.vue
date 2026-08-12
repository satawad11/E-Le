<template>
  <div class="p-6">
    <div class="flex justify-between items-center mb-6">
      <h2 class="text-2xl font-bold">หนังสือเวียน (Circulars)</h2>
    </div>

    <!-- Tabs -->
    <div class="flex space-x-4 mb-6 border-b">
      <button 
        @click="activeTab = 'incoming'" 
        class="py-2 px-4 border-b-2 font-medium text-sm transition-colors"
        :class="activeTab === 'incoming' ? 'border-[#800000] text-[#800000]' : 'border-transparent text-gray-500 hover:text-gray-700'"
      >
        หนังสือเวียนถึงฉัน
      </button>
      <button 
        @click="activeTab = 'outgoing'" 
        class="py-2 px-4 border-b-2 font-medium text-sm transition-colors"
        :class="activeTab === 'outgoing' ? 'border-[#800000] text-[#800000]' : 'border-transparent text-gray-500 hover:text-gray-700'"
      >
        หนังสือเวียนที่ฉันส่ง
      </button>
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden">
      <div v-if="loading" class="p-12 text-center text-gray-500">
        กำลังโหลดข้อมูล...
      </div>
      
      <div v-else-if="activeTab === 'incoming'">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-gray-50 border-b">
              <th class="p-4 font-medium text-sm text-gray-600">วันที่ส่ง</th>
              <th class="p-4 font-medium text-sm text-gray-600">เรื่อง</th>
              <th class="p-4 font-medium text-sm text-gray-600">ผู้ส่ง</th>
              <th class="p-4 font-medium text-sm text-gray-600">ข้อความ</th>
              <th class="p-4 font-medium text-sm text-gray-600">สถานะ</th>
              <th class="p-4 font-medium text-sm text-gray-600 text-center">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in incomingList" :key="item.id" class="border-b hover:bg-gray-50">
              <td class="p-4 text-sm">{{ formatDate(item.circular?.created_at) }}</td>
              <td class="p-4 font-medium text-[#800000]">
                <router-link :to="`/saraban/${item.circular?.document_id}`" class="hover:underline">
                  {{ item.circular?.document?.title || 'ไม่มีชื่อเรื่อง' }}
                </router-link>
              </td>
              <td class="p-4 text-sm">{{ userDisplayName(item.circular?.sender) }}</td>
              <td class="p-4 text-sm text-gray-600 truncate max-w-xs">{{ item.circular?.message }}</td>
              <td class="p-4 text-sm">
                <span v-if="item.is_acknowledged" class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs">รับทราบแล้ว</span>
                <span v-else class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs animate-pulse">รอการรับทราบ</span>
              </td>
              <td class="p-4 text-center">
                <button 
                  v-if="!item.is_acknowledged" 
                  @click="promptAcknowledge(item.circular_id)" 
                  class="px-3 py-1 bg-[#800000] text-white text-xs rounded hover:bg-red-800 transition"
                >
                  กดรับทราบ
                </button>
                <span v-else class="text-xs text-gray-400">{{ formatDate(item.acknowledged_at) }}</span>
              </td>
            </tr>
            <tr v-if="incomingList.length === 0">
              <td colspan="6" class="p-8 text-center text-gray-500">ไม่มีรายการหนังสือเวียนถึงคุณ</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else-if="activeTab === 'outgoing'">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-gray-50 border-b">
              <th class="p-4 font-medium text-sm text-gray-600">วันที่ส่ง</th>
              <th class="p-4 font-medium text-sm text-gray-600">เรื่อง</th>
              <th class="p-4 font-medium text-sm text-gray-600">ข้อความที่ส่ง</th>
              <th class="p-4 font-medium text-sm text-gray-600">ผู้รับทราบแล้ว</th>
              <th class="p-4 font-medium text-sm text-gray-600 text-center">รายละเอียด</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in outgoingList" :key="item.id" class="border-b hover:bg-gray-50">
              <td class="p-4 text-sm">{{ formatDate(item.created_at) }}</td>
              <td class="p-4 font-medium text-[#800000]">
                <router-link :to="`/saraban/${item.document_id}`" class="hover:underline">
                  {{ item.document?.title || 'ไม่มีชื่อเรื่อง' }}
                </router-link>
              </td>
              <td class="p-4 text-sm text-gray-600 truncate max-w-xs">{{ item.message }}</td>
              <td class="p-4 text-sm">
                <div class="flex items-center gap-2">
                  <div class="w-24 bg-gray-200 rounded-full h-2">
                    <div class="bg-green-500 h-2 rounded-full" :style="{ width: getAckPercentage(item) + '%' }"></div>
                  </div>
                  <span class="text-xs">{{ getAckCount(item) }}/{{ item.recipients?.length || 0 }}</span>
                </div>
              </td>
              <td class="p-4 text-center">
                <button @click="viewTracking(item)" class="text-blue-600 hover:text-blue-800 text-sm">ดูสถานะ</button>
              </td>
            </tr>
            <tr v-if="outgoingList.length === 0">
              <td colspan="5" class="p-8 text-center text-gray-500">ไม่มีรายการหนังสือเวียนที่คุณส่ง</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Tracking Modal -->
    <div v-if="showTrackingModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
        <div class="px-6 py-4 border-b flex justify-between items-center">
          <h3 class="font-bold text-lg">สถานะการรับทราบหนังสือเวียน</h3>
          <button @click="showTrackingModal = false" class="text-gray-400 hover:text-gray-600">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
        <div class="p-6 overflow-y-auto flex-1">
          <div class="mb-4">
            <h4 class="font-bold text-gray-800">{{ selectedCircular?.document?.title }}</h4>
            <p class="text-sm text-gray-500 mt-1">ข้อความแนบ: {{ selectedCircular?.message }}</p>
          </div>
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="bg-gray-50 border-b">
                <th class="p-3 font-medium text-sm">ผู้รับ</th>
                <th class="p-3 font-medium text-sm">สถานะ</th>
                <th class="p-3 font-medium text-sm">เวลารับทราบ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="rec in selectedCircular?.recipients" :key="rec.id" class="border-b">
                <td class="p-3 text-sm">{{ userDisplayName(rec.user) }}</td>
                <td class="p-3 text-sm">
                  <span v-if="rec.is_acknowledged" class="text-green-600 flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> รับทราบแล้ว
                  </span>
                  <span v-else class="text-gray-400">รอการรับทราบ</span>
                </td>
                <td class="p-3 text-sm text-gray-500">{{ rec.acknowledged_at ? formatDate(rec.acknowledged_at) : '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import api from '../../axios'
import Swal from 'sweetalert2'
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'
import { userDisplayName } from '../../utils/userName'

const activeTab = ref('incoming')
const loading = ref(false)
const incomingList = ref([])
const outgoingList = ref([])
const globalSettings = ref({})

const showTrackingModal = ref(false)
const selectedCircular = ref(null)

const formatDate = (dateStr) => {
  if (!dateStr) return '-'
  return ThaiDateFormatter.toThaiDateTime(dateStr)
}

const getAckCount = (circular) => {
  if (!circular.recipients) return 0
  return circular.recipients.filter(r => r.is_acknowledged).length
}

const getAckPercentage = (circular) => {
  if (!circular.recipients || circular.recipients.length === 0) return 0
  return (getAckCount(circular) / circular.recipients.length) * 100
}

const fetchSettings = async () => {
  try {
    const res = await api.get('/settings')
    globalSettings.value = res.data || {}
  } catch (err) {
    console.error('Failed to load settings', err)
  }
}

const fetchData = async () => {
  loading.value = true
  try {
    if (activeTab.value === 'incoming') {
      const res = await api.get('/saraban/circulars/incoming')
      incomingList.value = res.data
    } else {
      const res = await api.get('/saraban/circulars/outgoing')
      outgoingList.value = res.data
    }
  } catch (error) {
    console.error('Failed to fetch circulars', error)
  } finally {
    loading.value = false
  }
}

watch(activeTab, () => {
  fetchData()
})

onMounted(() => {
  fetchSettings()
  fetchData()
})

const promptAcknowledge = async (circularId) => {
  const requirePin = globalSettings.value.require_pin_for_circular_ack === '1'
  
  if (requirePin) {
    const { value: password } = await Swal.fire({
      title: 'ยืนยันการรับทราบ',
      text: 'กรุณากรอกรหัสผ่านของคุณเพื่อยืนยันการรับทราบหนังสือเวียน',
      input: 'password',
      inputPlaceholder: 'รหัสผ่านของคุณ',
      showCancelButton: true,
      confirmButtonColor: '#800000',
      cancelButtonColor: '#9ca3af',
      confirmButtonText: 'ยืนยัน',
      cancelButtonText: 'ยกเลิก',
      inputValidator: (value) => {
        if (!value) return 'กรุณากรอกรหัสผ่าน!'
      }
    })

    if (password) {
      submitAcknowledge(circularId, password)
    }
  } else {
    Swal.fire({
      title: 'ยืนยันการรับทราบ',
      text: "คุณแน่ใจหรือไม่ที่จะกดยืนยันรับทราบหนังสือเวียนฉบับนี้?",
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#800000',
      cancelButtonColor: '#9ca3af',
      confirmButtonText: 'ยืนยันรับทราบ',
      cancelButtonText: 'ยกเลิก'
    }).then((result) => {
      if (result.isConfirmed) {
        submitAcknowledge(circularId)
      }
    })
  }
}

const submitAcknowledge = async (circularId, password = null) => {
  try {
    const payload = password ? { password } : {}
    await api.post(`/saraban/circulars/${circularId}/acknowledge`, payload)
    Swal.fire('สำเร็จ', 'บันทึกการรับทราบเรียบร้อยแล้ว', 'success')
    fetchData()
  } catch (err) {
    console.error(err)
    Swal.fire('ข้อผิดพลาด', err.response?.data?.message || 'ไม่สามารถบันทึกการรับทราบได้', 'error')
  }
}

const viewTracking = (circular) => {
  selectedCircular.value = circular
  showTrackingModal.value = true
}
</script>
