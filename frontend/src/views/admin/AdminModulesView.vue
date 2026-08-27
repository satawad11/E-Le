<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-800">โมดูลระบบ</h1>
      <button @click="saveSettings" :disabled="isSaving" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md font-medium text-sm flex items-center gap-2 transition-colors disabled:opacity-50">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
        {{ isSaving ? 'กำลังบันทึก...' : 'บันทึกข้อมูล' }}
      </button>
    </div>

    <div class="bg-white shadow rounded-lg p-6 space-y-4">
      <h3 class="text-lg leading-6 font-medium text-red-700 border-b pb-2">จัดการการเปิด-ปิดโมดูลของระบบ</h3>
      <p class="text-sm text-gray-500">คุณสามารถเปิดหรือปิดการใช้งานโมดูลต่างๆ ของระบบได้จากที่นี่</p>
      
      <div class="space-y-4 mt-6">
        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
          <div>
            <h4 class="text-md font-semibold text-gray-800">ระบบลงเวลาออนไลน์</h4>
            <p class="text-sm text-gray-500 mt-1">เปิดเมนูลงเวลา ประวัติ และรายงานการลงเวลา</p>
          </div>
          <label class="flex items-center cursor-pointer relative">
            <input type="checkbox" aria-label="เปิดหรือปิดระบบลงเวลาออนไลน์" v-model="settings.enable_attendance_menu" true-value="1" false-value="0" class="sr-only">
            <div class="w-14 h-7 bg-gray-300 rounded-full transition-colors duration-300 shadow-inner" :class="{'bg-green-500': settings.enable_attendance_menu === '1'}"></div>
            <div class="absolute left-1 top-1 bg-white w-5 h-5 rounded-full transition-transform duration-300 shadow" :class="{'translate-x-7': settings.enable_attendance_menu === '1'}"></div>
          </label>
        </div>

        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
          <div>
            <h4 class="text-md font-semibold text-gray-800">ระบบบริหารการลา</h4>
            <p class="text-sm text-gray-500 mt-1">เปิดเมนูคำขอลา การอนุมัติ และรายงานการลา</p>
          </div>
          <label class="flex items-center cursor-pointer relative">
            <input type="checkbox" aria-label="เปิดหรือปิดระบบบริหารการลา" v-model="settings.enable_leave_menu" true-value="1" false-value="0" class="sr-only">
            <div class="w-14 h-7 bg-gray-300 rounded-full transition-colors duration-300 shadow-inner" :class="{'bg-green-500': settings.enable_leave_menu === '1'}"></div>
            <div class="absolute left-1 top-1 bg-white w-5 h-5 rounded-full transition-transform duration-300 shadow" :class="{'translate-x-7': settings.enable_leave_menu === '1'}"></div>
          </label>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'

const alertStore = useAlertStore()

const isSaving = ref(false)
const settings = ref({
  enable_attendance_menu: '1',
  enable_leave_menu: '1'
})

const fetchSettings = async () => {
  try {
    const response = await api.get('/admin/settings')
    settings.value.enable_attendance_menu = response.data.enable_attendance_menu ?? '1'
    settings.value.enable_leave_menu = response.data.enable_leave_menu ?? '1'
  } catch {
    alertStore.error('โหลดข้อมูลการตั้งค่าล้มเหลว')
  }
}

const saveSettings = async () => {
  isSaving.value = true
  try {
    await api.post('/admin/settings', settings.value)
    alertStore.success('บันทึกการตั้งค่าโมดูลเรียบร้อยแล้ว หากมีการเปลี่ยนแปลงเมนู กรุณารีเฟรชหน้าเว็บ')
    
    // Update global settings cache in local storage / reload window
    setTimeout(() => {
        window.location.reload();
    }, 1500)
  } catch {
    alertStore.error('เกิดข้อผิดพลาดในการบันทึก')
  } finally {
    isSaving.value = false
  }
}

onMounted(() => {
  fetchSettings()
})
</script>

