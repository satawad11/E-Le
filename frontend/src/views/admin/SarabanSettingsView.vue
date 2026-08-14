<template>
  <div class="p-6">
    <h2 class="text-2xl font-bold mb-6">ตั้งค่าระบบงานสารบรรณ (e-Saraban)</h2>
    
    <div class="bg-white rounded-xl shadow p-6 mb-6">
      <h3 class="text-lg font-bold mb-4 border-b pb-2">ความปลอดภัย (Security)</h3>
      
      <div class="flex items-center justify-between">
        <div>
          <h4 class="font-semibold text-gray-800">บังคับใช้รหัสผ่านก่อนลงนาม (PIN/Password)</h4>
          <p class="text-sm text-gray-500">ผู้ใช้จะต้องกรอกรหัสผ่านทุกครั้งก่อนยืนยันการประทับลายเซ็นอิเล็กทรอนิกส์ลงบนเอกสาร</p>
        </div>
        
        <label class="relative inline-flex items-center cursor-pointer">
          <input type="checkbox" v-model="requirePin" aria-label="บังคับใช้รหัสผ่านก่อนลงนาม" class="sr-only peer" @change="saveSettings">
          <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[#800000]/20 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#800000]"></div>
        </label>
      </div>

      <div class="flex items-center justify-between mt-4">
        <div>
          <h4 class="font-semibold text-gray-800">บังคับใช้รหัสผ่านก่อนกดรับทราบหนังสือเวียน</h4>
          <p class="text-sm text-gray-500">ผู้ใช้จะต้องกรอกรหัสผ่านทุกครั้งก่อนกดยืนยันรับทราบหนังสือเวียนเพื่อเป็นหลักฐานการอ่าน</p>
        </div>
        
        <label class="relative inline-flex items-center cursor-pointer">
          <input type="checkbox" v-model="requirePinForCircular" aria-label="บังคับใช้รหัสผ่านก่อนรับทราบหนังสือเวียน" class="sr-only peer" @change="saveSettings">
          <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-[#800000]/20 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#800000]"></div>
        </label>
      </div>
    </div>
    
    <div class="bg-white rounded-xl shadow p-6 mb-6">
      <h3 class="text-lg font-bold text-gray-800 border-b pb-2 mb-4">ตราครุฑ / โลโก้หน่วยงาน</h3>
      
      <div class="flex items-start gap-6">
        <div class="w-32 h-32 border-2 border-dashed border-gray-300 rounded-lg flex items-center justify-center bg-gray-50 overflow-hidden relative group">
          <img v-if="garudaLogoUrl" v-secure-src="garudaLogoUrl" class="w-full h-full object-contain p-2" />
          <svg v-else class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
          
          <div class="absolute inset-0 bg-black/50 hidden group-hover:flex items-center justify-center">
            <label class="cursor-pointer text-white text-sm font-medium hover:underline">
              เปลี่ยนโลโก้
              <input type="file" class="hidden" accept="image/*" @change="uploadGarudaLogo">
            </label>
          </div>
        </div>
        <div class="flex-1">
          <h4 class="font-semibold text-gray-800">อัปโหลดตราครุฑสำหรับเอกสาร E-Document</h4>
          <p class="text-sm text-gray-500 mt-1">ไฟล์ภาพตราครุฑ (PNG/JPG) ที่จะถูกนำไปประทับบนหัวกระดาษตอนระบบสร้างไฟล์ PDF อัตโนมัติ พื้นหลังควรเป็นแบบโปร่งใส (Transparent PNG)</p>
          <div class="mt-4">
            <label class="px-4 py-2 bg-gray-100 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-200 cursor-pointer inline-block transition-colors">
              เลือกไฟล์
              <input type="file" class="hidden" accept="image/*" @change="uploadGarudaLogo">
            </label>
            <span class="text-xs text-gray-400 ml-3" v-if="isUploadingLogo">กำลังอัปโหลด...</span>
          </div>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow p-6">
      <h3 class="text-lg font-bold mb-4 border-b pb-2">รูปแบบเลขหนังสือ (Document Number)</h3>
      
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">รูปแบบเอกสารรับเข้า (Incoming)</label>
          <input type="text" v-model="formatIncoming" class="w-full border-gray-300 rounded-lg shadow-sm focus:border-[#800000] focus:ring-[#800000]" @blur="saveSettings">
          <p class="text-xs text-gray-500 mt-1">ตัวแปร: {dept_code}, {year}, {running_number}</p>
        </div>
        
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">รูปแบบเอกสารส่งออก (Outgoing)</label>
          <input type="text" v-model="formatOutgoing" class="w-full border-gray-300 rounded-lg shadow-sm focus:border-[#800000] focus:ring-[#800000]" @blur="saveSettings">
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
const requirePin = ref(false)
const requirePinForCircular = ref(false)
const garudaLogoUrl = ref('')
const isUploadingLogo = ref(false)
const formatIncoming = ref('{dept_code}/{year}/{running_number}')
const formatOutgoing = ref('{dept_code}อ/{year}/{running_number}')
const assetUrl = (path) => path ? new URL(path, window.location.origin).toString() : ''

onMounted(async () => {
  try {
    // LEGACY-04 (ปิดงาน): ใช้ endpoint เฉพาะของงานสารบรรณ
    // `/admin/settings` เป็นของผู้ดูแลระบบและมีค่าลับของทั้งระบบอยู่ด้วย
    const res = await api.get('/saraban/settings')
    const settings = res.data || {}
    
    if (settings.require_pin_for_signature) {
      requirePin.value = settings.require_pin_for_signature === '1'
    }
    if (settings.require_pin_for_circular_ack) {
      requirePinForCircular.value = settings.require_pin_for_circular_ack === '1'
    }
    formatIncoming.value = settings.saraban_incoming_number_format || formatIncoming.value
    formatOutgoing.value = settings.saraban_outgoing_number_format || formatOutgoing.value
    if (settings.saraban_garuda_logo) {
      garudaLogoUrl.value = assetUrl(settings.saraban_garuda_logo)
    }
  } catch (err) {
    console.error('Failed to load settings', err)
  }
})

const uploadGarudaLogo = async (event) => {
  const file = event.target.files[0]
  if (!file) return
  
  const formData = new FormData()
  formData.append('logo', file)
  
  isUploadingLogo.value = true
  try {
    const res = await api.post('/saraban/settings/garuda-logo', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    garudaLogoUrl.value = assetUrl(res.data.url)
    alertStore.success('อัปโหลดตราครุฑเรียบร้อยแล้ว')
  } catch (err) {
    console.error(err)
    alertStore.error('เกิดข้อผิดพลาดในการอัปโหลด')
  } finally {
    isUploadingLogo.value = false
    event.target.value = null
  }
}

const saveSettings = async () => {
  try {
    const payload = {
      require_pin_for_signature: requirePin.value ? '1' : '0',
      require_pin_for_circular_ack: requirePinForCircular.value ? '1' : '0',
      saraban_incoming_number_format: formatIncoming.value,
      saraban_outgoing_number_format: formatOutgoing.value
    }
    await api.post('/saraban/settings', payload)

    alertStore.success('บันทึกการตั้งค่าสารบรรณเรียบร้อยแล้ว')
  } catch (err) {
    console.error('Failed to save settings', err)
    alertStore.error(Object.values(err.response?.data?.errors || {}).flat()[0] || 'เกิดข้อผิดพลาดในการบันทึกการตั้งค่า')
  }
}
</script>

