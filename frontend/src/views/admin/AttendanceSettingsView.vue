<template>
  <div class="space-y-6">
    <div class="flex flex-wrap justify-between items-center gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">ตั้งค่าระบบลงเวลา</h1>
        <p class="text-sm text-gray-500 mt-1">พิกัดสถานศึกษา รัศมีที่อนุญาตให้ลงเวลา และเวลาทำการมาตรฐานที่ใช้คำนวณสาย/ออกก่อน</p>
      </div>
      <button @click="saveSettings" :disabled="isSaving" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md font-medium text-sm flex items-center gap-2 transition-colors disabled:opacity-50">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
        {{ isSaving ? 'กำลังบันทึก...' : 'บันทึกข้อมูล' }}
      </button>
    </div>

    <form @submit.prevent="saveSettings" class="bg-white shadow rounded-lg p-6 space-y-6">
      <div class="space-y-4">
        <h3 class="text-lg leading-6 font-medium text-red-700 border-b pb-2">เวลาทำการมาตรฐาน</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="bg-red-50 p-3 rounded border border-red-100">
            <label class="block text-sm font-semibold text-red-700 mb-1">เวลาเข้างานมาตรฐานของระบบ</label>
            <input type="time" v-model="settings.default_work_start_time" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
            <span class="text-xs text-red-600 mt-1 block">ใช้สำหรับบุคลากรที่ไม่ได้กำหนดเวลาเข้างานไว้</span>
          </div>
          <div class="bg-red-50 p-3 rounded border border-red-100">
            <label class="block text-sm font-semibold text-red-700 mb-1">เวลาออกงานมาตรฐานของระบบ</label>
            <input type="time" v-model="settings.default_work_end_time" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
            <span class="text-xs text-red-600 mt-1 block">ใช้สำหรับคำนวณการออกก่อนเวลา</span>
          </div>
        </div>
      </div>

      <div class="space-y-4">
        <h3 class="text-lg leading-6 font-medium text-red-700 border-b pb-2">พิกัดและขอบเขตการลงเวลา</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm text-gray-700 mb-1">พิกัด GPS ละติจูด (Latitude)</label>
            <input type="number" step="any" min="-90" max="90" v-model="settings.college_latitude" placeholder="เช่น 13.7563" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
          </div>
          <div>
            <label class="block text-sm text-gray-700 mb-1">พิกัด GPS ลองจิจูด (Longitude)</label>
            <input type="number" step="any" min="-180" max="180" v-model="settings.college_longitude" placeholder="เช่น 100.5018" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">รัศมีลงเวลาสูงสุด (เมตร)</label>
            <input type="number" min="0" max="100000" v-model.number="settings.max_attendance_radius" class="block w-full border border-gray-300 rounded py-1 px-2 sm:text-sm">
            <span class="text-xs text-gray-600 mt-1 block">ผู้ที่อยู่นอกรัศมีนี้จะลงเวลาไม่ได้</span>
          </div>
          <label class="flex items-center gap-3 text-sm text-gray-700">
            <input type="checkbox" v-model="settings.require_attendance_selfie" true-value="1" false-value="0" class="h-5 w-5 rounded border-gray-300 text-red-600">
            บังคับถ่ายภาพขณะลงเวลา
          </label>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'

const alertStore = useAlertStore()

const isSaving = ref(false)
const initialSnapshot = ref('')

const settings = ref({
  default_work_start_time: '',
  default_work_end_time: '',
  college_latitude: '',
  college_longitude: '',
  max_attendance_radius: '',
  require_attendance_selfie: '0'
})

const isDirty = computed(() => Boolean(initialSnapshot.value) && JSON.stringify(settings.value) !== initialSnapshot.value)

const validationMessage = (error, fallback) => Object.values(error.response?.data?.errors || {}).flat()[0] || fallback

const fetchSettings = async () => {
  try {
    const response = await api.get('/admin/settings')
    for (const key of Object.keys(settings.value)) {
      if (Object.prototype.hasOwnProperty.call(response.data, key)) settings.value[key] = response.data[key]
    }
    initialSnapshot.value = JSON.stringify(settings.value)
  } catch (error) {
    alertStore.error(validationMessage(error, 'โหลดข้อมูลการตั้งค่าล้มเหลว'))
  }
}

const saveSettings = async () => {
  isSaving.value = true
  try {
    await api.post('/admin/settings', settings.value)
    initialSnapshot.value = JSON.stringify(settings.value)
    alertStore.success('บันทึกการตั้งค่าระบบลงเวลาเรียบร้อยแล้ว')
  } catch (error) {
    alertStore.error(validationMessage(error, 'เกิดข้อผิดพลาดในการบันทึก'))
  } finally {
    isSaving.value = false
  }
}

const handleBeforeUnload = (event) => {
  if (!isDirty.value) return
  event.preventDefault()
  event.returnValue = ''
}

onBeforeRouteLeave(() => !isDirty.value || window.confirm('มีการตั้งค่าที่ยังไม่ได้บันทึก ต้องการออกจากหน้านี้หรือไม่?'))

onMounted(() => {
  fetchSettings()
  window.addEventListener('beforeunload', handleBeforeUnload)
})

onBeforeUnmount(() => window.removeEventListener('beforeunload', handleBeforeUnload))
</script>
