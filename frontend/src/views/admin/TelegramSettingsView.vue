<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">ศูนย์จัดการการแจ้งเตือน (Notifications Center)</h1>
        <p class="text-sm text-gray-500 mt-1">ตั้งค่าระบบการแจ้งเตือนส่วนกลางผ่าน LINE Official Account, Telegram Bot และ Email</p>
      </div>
      <button @click="saveSettings" :disabled="isSaving" class="bg-red-700 hover:bg-red-800 text-white px-5 py-2.5 rounded-xl font-semibold text-sm flex items-center gap-2 transition-all shadow-md disabled:opacity-50">
        <span v-if="isSaving" class="animate-spin h-4 w-4 border-2 border-white border-t-transparent rounded-full"></span>
        <svg v-else class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.save"/>
        </svg>
        {{ isSaving ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่า' }}
      </button>
    </div>

    <OperationsTools />
    <!-- 1. LINE Official Account & Group Notifications -->
    <div class="bg-white shadow-sm border border-gray-100 rounded-2xl p-6 space-y-4">
      <div class="flex items-center gap-3 border-b pb-3">
        <div class="w-10 h-10 rounded-full bg-green-100 text-green-600 flex items-center justify-center">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.chat"/>
          </svg>
        </div>
        <div>
          <h3 class="text-lg font-bold text-gray-900">1. การตั้งค่า LINE Official Account & กลุ่ม LINE</h3>
          <p class="text-xs text-gray-500">สำหรับส่งแจ้งเตือนการยื่นใบลา ผลอนุมัติ และการลงเวลามาสายผ่าน LINE OA</p>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <div class="sm:col-span-2">
          <label class="block text-xs font-semibold text-gray-700 mb-1">LINE Channel Access Token (Messaging API)</label>
          <input type="password" v-model="settings.line_channel_access_token" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:border-red-500" placeholder=" Channel Access Token (Long-lived) จาก LINE Developers Console">
          <p class="mt-1 text-xs"><span v-if="configured.line_channel_access_token" class="text-green-700">ตั้งค่าแล้ว — เว้นว่างเพื่อคงค่าเดิม</span><button v-if="configured.line_channel_access_token" type="button" @click="clearSecret('line_channel_access_token')" class="ml-3 text-red-600 underline">ล้าง Token</button></p>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">LINE Admin User ID (รายบุคคลผู้ดูแลระบบ)</label>
          <input type="text" v-model="settings.line_admin_user_id" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:border-red-500" placeholder="เช่น U1234567890abcdef...">
          <span class="text-[11px] text-gray-500 mt-1 block">LINE User ID ของแอดมินหรือหัวหน้าฝ่ายบุคลากร (ขึ้นต้นด้วย U)</span>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">LINE Group ID (สำหรับแจ้งเตือนเข้ากลุ่มไลน์)</label>
          <input type="text" v-model="settings.line_group_id" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:border-red-500" placeholder="เช่น C1234567890abcdef...">
          <span class="text-[11px] text-gray-500 mt-1 block">Group ID ของกลุ่มไลน์ฝ่ายบริหาร/งานบุคลากร (ขึ้นต้นด้วย C)</span>
        </div>
        <div v-if="configured.line_notify_token" class="sm:col-span-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
          พบ LINE Notify token เดิมซึ่งไม่ได้ใช้งานแล้ว เนื่องจากบริการยุติลง โปรดใช้ Messaging API ด้านบน
          <button type="button" @click="clearSecret('line_notify_token')" class="ml-3 font-semibold text-red-700 underline">ล้าง Token เดิม</button>
        </div>
        <div class="sm:col-span-2">
          <button type="button" @click="testNotification('line')" :disabled="testingChannel === 'line'" class="px-4 py-2 rounded-lg border border-green-600 text-green-700 text-sm font-semibold hover:bg-green-50 disabled:opacity-50">{{ testingChannel === 'line' ? 'กำลังทดสอบ...' : 'ทดสอบส่ง LINE' }}</button>
        </div>
      </div>
    </div>

    <!-- 2. Telegram Bot Notifications -->
    <div class="bg-white shadow-sm border border-gray-100 rounded-2xl p-6 space-y-4">
      <div class="flex items-center gap-3 border-b pb-3">
        <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.send"/>
          </svg>
        </div>
        <div>
          <h3 class="text-lg font-bold text-gray-900">2. การตั้งค่า Telegram Bot</h3>
          <p class="text-xs text-gray-500">สำหรับส่งแจ้งเตือนผ่าน Telegram Bot ไปยังบุคลากร</p>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-5">
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">โทเคนบอท Telegram (Bot Token)</label>
          <input type="password" v-model="settings.telegram_bot_token" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:border-red-500" placeholder="เช่น 123456789:ABCDefghIJKLmnopQRSTuvwxYZ">
          <p class="mt-1 text-xs"><span v-if="configured.telegram_bot_token" class="text-green-700">ตั้งค่าแล้ว — เว้นว่างเพื่อคงค่าเดิม</span><button v-if="configured.telegram_bot_token" type="button" @click="clearSecret('telegram_bot_token')" class="ml-3 text-red-600 underline">ล้าง Token</button></p>
          <span class="text-[11px] text-gray-500 mt-1 block">ขอ Bot Token ได้จากบอท @BotFather บน Telegram</span>
        </div>
        <div>
          <button type="button" @click="testNotification('telegram')" :disabled="testingChannel === 'telegram'" class="px-4 py-2 rounded-lg border border-blue-600 text-blue-700 text-sm font-semibold hover:bg-blue-50 disabled:opacity-50">{{ testingChannel === 'telegram' ? 'กำลังทดสอบ...' : 'ทดสอบส่ง Telegram ไปยังบัญชีผู้ดูแล' }}</button>
        </div>
      </div>
    </div>

    <!-- 3. SMTP Email Settings -->
    <div class="bg-white shadow-sm border border-gray-100 rounded-2xl p-6 space-y-4">
      <div class="flex items-center gap-3 border-b pb-3">
        <div class="w-10 h-10 rounded-full bg-red-100 text-red-700 flex items-center justify-center">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.mail"/>
          </svg>
        </div>
        <div>
          <h3 class="text-lg font-bold text-gray-900">3. การตั้งค่าเซิร์ฟเวอร์ส่งอีเมล (SMTP Email)</h3>
          <p class="text-xs text-gray-500">สำหรับส่งอีเมลแจ้งเตือนใบลาและการลงเวลา</p>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">เซิร์ฟเวอร์อีเมลขาออก (SMTP Host)</label>
          <input type="text" v-model="settings.mail_host" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:border-red-500" placeholder="smtp.gmail.com">
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">พอร์ตของเซิร์ฟเวอร์อีเมล</label>
          <input type="number" min="1" max="65535" v-model.number="settings.mail_port" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:border-red-500" placeholder="587">
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">ชื่อผู้ใช้สำหรับส่งอีเมล</label>
          <input type="text" v-model="settings.mail_username" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:border-red-500" placeholder="example@dmtc.ac.th">
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">รหัสผ่านสำหรับส่งอีเมล</label>
          <input type="password" v-model="settings.mail_password" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:border-red-500">
          <p class="mt-1 text-xs"><span v-if="configured.mail_password" class="text-green-700">ตั้งค่าแล้ว — เว้นว่างเพื่อคงค่าเดิม</span><button v-if="configured.mail_password" type="button" @click="clearSecret('mail_password')" class="ml-3 text-red-600 underline">ล้างรหัสผ่าน</button></p>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">วิธีเข้ารหัสการเชื่อมต่อ</label>
          <select v-model="settings.mail_encryption" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:border-red-500">
            <option value="tls">TLS</option>
            <option value="ssl">SSL</option>
            <option value="none">ไม่เข้ารหัส</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">อีเมลผู้ส่ง</label>
          <input type="email" v-model="settings.mail_from_address" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:border-red-500" placeholder="no-reply@dmtc.ac.th">
        </div>        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1">ชื่อผู้ส่งที่แสดงในอีเมล</label>
          <input type="text" v-model="settings.mail_from_name" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:border-red-500" placeholder="ระบบ E-Leave">
        </div>
        <div class="sm:col-span-2">
          <button type="button" @click="testNotification('email')" :disabled="testingChannel === 'email'" class="px-4 py-2 rounded-lg border border-red-700 text-red-700 text-sm font-semibold hover:bg-red-50 disabled:opacity-50">{{ testingChannel === 'email' ? 'กำลังทดสอบ...' : 'ทดสอบส่งอีเมลไปยังบัญชีผู้ดูแล' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import api from '../../axios'
import OperationsTools from '../../components/saraban/OperationsTools.vue'
import { useAlertStore } from '../../stores/alert'
import { ICONS } from '../../utils/icons'

const alertStore = useAlertStore()

const isSaving = ref(false)
const testingChannel = ref('')
const initialSnapshot = ref('')
const configured = ref({
  telegram_bot_token: false,
  line_channel_access_token: false,
  line_notify_token: false,
  mail_password: false,
})
const settings = ref({
  telegram_bot_token: '',
  line_channel_access_token: '',
  line_admin_user_id: '',
  line_group_id: '',
  mail_host: '',
  mail_port: '',
  mail_username: '',
  mail_password: '',
  mail_encryption: 'tls',
  mail_from_address: '',
  mail_from_name: ''
})
const isDirty = computed(() => Boolean(initialSnapshot.value) && JSON.stringify(settings.value) !== initialSnapshot.value)

const fetchSettings = async () => {
  try {
    const response = await api.get('/admin/settings')
    settings.value.telegram_bot_token = response.data.telegram_bot_token || ''
    settings.value.line_channel_access_token = response.data.line_channel_access_token || ''
    settings.value.line_admin_user_id = response.data.line_admin_user_id || ''
    settings.value.line_group_id = response.data.line_group_id || ''
    settings.value.line_notify_token = response.data.line_notify_token || ''
    settings.value.mail_host = response.data.mail_host || ''
    settings.value.mail_port = response.data.mail_port || ''
    settings.value.mail_username = response.data.mail_username || ''
    settings.value.mail_password = response.data.mail_password || ''
    settings.value.mail_encryption = response.data.mail_encryption || 'tls'
    settings.value.mail_from_address = response.data.mail_from_address || ''
    settings.value.mail_from_name = response.data.mail_from_name || ''
    for (const key of Object.keys(configured.value)) configured.value[key] = Boolean(response.data[key + '_configured'])
    initialSnapshot.value = JSON.stringify(settings.value)
  } catch {
    alertStore.error('โหลดข้อมูลการตั้งค่าล้มเหลว')
  }
}

const saveSettings = async () => {
  isSaving.value = true
  try {
    await api.post('/admin/settings', settings.value)
    for (const key of Object.keys(configured.value)) {
      if (settings.value[key]) configured.value[key] = true
      settings.value[key] = ''
    }
    initialSnapshot.value = JSON.stringify(settings.value)
    alertStore.success('บันทึกการตั้งค่าการแจ้งเตือนเรียบร้อยแล้ว')
  } catch {
    alertStore.error('เกิดข้อผิดพลาดในการบันทึก')
  } finally {
    isSaving.value = false
  }
}

const clearSecret = async (key) => {
  if (!window.confirm('ยืนยันการล้างข้อมูลลับนี้?')) return
  try {
    await api.delete('/admin/settings/secrets/' + key)
    configured.value[key] = false
    settings.value[key] = ''
    alertStore.success('ล้างข้อมูลลับเรียบร้อยแล้ว')
  } catch {
    alertStore.error('ล้างข้อมูลลับไม่สำเร็จ')
  }
}

const testNotification = async (channel) => {
  testingChannel.value = channel
  try {
    await api.post('/admin/settings/test-notification/' + channel)
    alertStore.success('ส่งการแจ้งเตือนทดสอบเรียบร้อยแล้ว')
  } catch (error) {
    alertStore.error(Object.values(error.response?.data?.errors || {}).flat()[0] || 'ทดสอบการแจ้งเตือนไม่สำเร็จ')
  } finally {
    testingChannel.value = ''
  }
}

const handleBeforeUnload = (event) => {
  if (!isDirty.value) return
  event.preventDefault()
  event.returnValue = ''
}

onBeforeRouteLeave(() => !isDirty.value || window.confirm('มีการตั้งค่าการแจ้งเตือนที่ยังไม่ได้บันทึก ต้องการออกจากหน้านี้หรือไม่?'))

onMounted(() => {
  fetchSettings()
  window.addEventListener('beforeunload', handleBeforeUnload)
})

onBeforeUnmount(() => window.removeEventListener('beforeunload', handleBeforeUnload))
</script>

