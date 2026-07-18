<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-800">การแจ้งเตือน</h1>
      <button @click="saveSettings" :disabled="isSaving" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md font-medium text-sm flex items-center gap-2 transition-colors disabled:opacity-50">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
        {{ isSaving ? 'กำลังบันทึก...' : 'บันทึกข้อมูล' }}
      </button>
    </div>

    <div class="bg-white shadow rounded-lg p-6 space-y-4">
      <h3 class="text-lg leading-6 font-medium text-red-700 border-b pb-2">การแจ้งเตือน (Telegram Bot)</h3>
      <p class="text-sm text-gray-500">ตั้งค่า Token ของ Telegram Bot สำหรับแจ้งเตือนสถานะใบลาไปยังผู้ใช้แต่ละคน</p>
      <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label class="block text-sm font-medium text-gray-700">Bot Token</label>
          <input type="password" v-model="settings.telegram_bot_token" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-red-500 focus:border-red-500 sm:text-sm" placeholder="123456789:ABCDefghIJKLmnopQRSTuvwxYZ">
        </div>
      </div>
    </div>

    <!-- Email Notification Settings -->
    <div class="bg-white shadow rounded-lg p-6 space-y-4">
      <h3 class="text-lg leading-6 font-medium text-red-700 border-b pb-2">การแจ้งเตือน (อีเมล)</h3>
      <p class="text-sm text-gray-500">ตั้งค่าเซิร์ฟเวอร์ SMTP สำหรับส่งอีเมลแจ้งเตือนระบบลางาน</p>
      <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700">Mail Host (SMTP)</label>
          <input type="text" v-model="settings.mail_host" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-red-500 focus:border-red-500 sm:text-sm" placeholder="smtp.gmail.com">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Mail Port</label>
          <input type="text" v-model="settings.mail_port" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-red-500 focus:border-red-500 sm:text-sm" placeholder="587">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Mail Username</label>
          <input type="text" v-model="settings.mail_username" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-red-500 focus:border-red-500 sm:text-sm" placeholder="example@gmail.com">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Mail Password</label>
          <input type="password" v-model="settings.mail_password" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-red-500 focus:border-red-500 sm:text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Mail Encryption</label>
          <select v-model="settings.mail_encryption" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-red-500 focus:border-red-500 sm:text-sm">
            <option value="tls">TLS</option>
            <option value="ssl">SSL</option>
            <option value="">None</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Mail From Address</label>
          <input type="email" v-model="settings.mail_from_address" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-red-500 focus:border-red-500 sm:text-sm" placeholder="no-reply@school.ac.th">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Mail From Name</label>
          <input type="text" v-model="settings.mail_from_name" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-red-500 focus:border-red-500 sm:text-sm" placeholder="E-Leave System">
        </div>
      </div>
    </div>

    <!-- Attendance & Line Notify -->
    <div class="bg-white shadow rounded-lg p-6 space-y-4">
      <h3 class="text-lg leading-6 font-medium text-red-700 border-b pb-2">การตั้งค่าลงเวลา & LINE แจ้งเตือน</h3>
      
      <div class="space-y-4">
        <h4 class="text-md font-semibold text-gray-800">1. การจำกัดพื้นที่ลงเวลาและยืนยันตัวตน</h4>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="bg-red-50 p-3 rounded border border-red-100">
            <label class="block text-sm font-semibold text-red-700 mb-1">รัศมีการลงเวลาจากวิทยาลัย (เมตร)</label>
            <input type="number" v-model="settings.max_attendance_radius" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
            <span class="text-xs text-red-600 mt-1 block">หากปล่อยว่าง หรือตั้งค่า 0 จะไม่มีการจำกัดรัศมี</span>
          </div>
          <div class="bg-red-50 p-3 rounded border border-red-100">
            <label class="block text-sm font-semibold text-red-700 mb-1">การยืนยันตัวตนด้วยภาพถ่าย (Selfie)</label>
            <select v-model="settings.require_attendance_selfie" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
              <option value="0">ปิดการใช้งาน</option>
              <option value="1">เปิดการใช้งาน (บังคับถ่ายรูปก่อนลงเวลา)</option>
            </select>
            <span class="text-xs text-red-600 mt-1 block">หากเปิด พนักงานจะต้องเปิดกล้องถ่ายรูปตัวเองก่อนลงเวลา</span>
          </div>
        </div>

        <hr class="my-4">
        <h4 class="text-md font-semibold text-gray-800">2. ตั้งค่า LINE Messaging API แจ้งเตือนมาสาย/ออกก่อน</h4>
        <div class="grid grid-cols-1 gap-4">
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">LINE Channel Access Token</label>
            <input type="text" v-model="settings.line_channel_access_token" placeholder="ใส่ Channel Access Token (Long-lived) จาก LINE Developers" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
          </div>
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">LINE Admin User ID (สำหรับรับแจ้งเตือน)</label>
            <input type="text" v-model="settings.line_admin_user_id" placeholder="ใส่ User ID ของผู้ที่ต้องการรับแจ้งเตือน" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
            <span class="text-xs text-gray-500 mt-1 block">* เมื่อมีพนักงานมาสาย หรือ ออกก่อนเวลา ระบบจะ Push ข้อความไปหา User ID นี้ทันที</span>
          </div>
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
  telegram_bot_token: '',
  mail_host: '',
  mail_port: '',
  mail_username: '',
  mail_password: '',
  mail_encryption: 'tls',
  mail_from_address: '',
  mail_from_name: '',
  max_attendance_radius: '',
  require_attendance_selfie: '0',
  line_channel_access_token: '',
  line_admin_user_id: ''
})

const fetchSettings = async () => {
  try {
    const response = await api.get('/admin/settings')
    settings.value.telegram_bot_token = response.data.telegram_bot_token || ''
    settings.value.mail_host = response.data.mail_host || ''
    settings.value.mail_port = response.data.mail_port || ''
    settings.value.mail_username = response.data.mail_username || ''
    settings.value.mail_password = response.data.mail_password || ''
    settings.value.mail_encryption = response.data.mail_encryption || 'tls'
    settings.value.mail_from_address = response.data.mail_from_address || ''
    settings.value.mail_from_name = response.data.mail_from_name || ''
    settings.value.max_attendance_radius = response.data.max_attendance_radius || ''
    settings.value.require_attendance_selfie = response.data.require_attendance_selfie || '0'
    settings.value.line_channel_access_token = response.data.line_channel_access_token || ''
    settings.value.line_admin_user_id = response.data.line_admin_user_id || ''
  } catch (error) {
    alertStore.error('โหลดข้อมูลการตั้งค่าล้มเหลว')
  }
}

const saveSettings = async () => {
  isSaving.value = true
  try {
    await api.post('/admin/settings', settings.value)
    alertStore.success('บันทึกการตั้งค่าการแจ้งเตือนเรียบร้อยแล้ว')
  } catch (error) {
    alertStore.error('เกิดข้อผิดพลาดในการบันทึก')
  } finally {
    isSaving.value = false
  }
}

onMounted(() => {
  fetchSettings()
})
</script>
