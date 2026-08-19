<template>
  <div class="max-w-6xl mx-auto space-y-8 pb-12">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 tracking-tight">ตั้งค่าแบบฟอร์มและการส่งออกเอกสาร</h1>
        <p class="text-sm text-gray-500 mt-1">จัดการข้อมูลสถานศึกษา ตราสัญลักษณ์ ตราครุฑ ผู้มีอำนาจลงนาม และรูปแบบไฟล์ PDF / Word / Excel</p>
      </div>
      <div class="flex items-center gap-3">
        <button
          @click="saveSettings"
          :disabled="saving"
          class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-[#800000] text-white font-medium hover:bg-[#660000] active:scale-[0.98] transition-all shadow-sm disabled:opacity-50 cursor-pointer"
        >
          <span v-if="saving" class="animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent"></span>
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
          <span>{{ saving ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่า' }}</span>
        </button>
      </div>
    </div>

    <!-- Alert message -->
    <div v-if="message" class="p-4 rounded-xl bg-green-50 border border-green-200 text-green-800 text-sm flex items-center justify-between">
      <div class="flex items-center gap-2">
        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <span>{{ message }}</span>
      </div>
      <button @click="message = ''" class="text-green-600 hover:text-green-800 text-xs font-semibold">ปิด</button>
    </div>

    <!-- Settings Sections -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
      
      <!-- Left Column: Form Settings -->
      <div class="lg:col-span-2 space-y-6">
        
        <!-- Section 1: Organization Metadata -->
        <div class="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm space-y-5">
          <div class="flex items-center gap-3 pb-3 border-b border-gray-100">
            <div class="w-9 h-9 rounded-xl bg-[#800000]/10 text-[#800000] flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.building"/>
              </svg>
            </div>
            <div>
              <h2 class="font-bold text-gray-900">ข้อมูลสถานศึกษาและหน่วยงาน</h2>
              <p class="text-xs text-gray-500">ข้อมูลนี้จะถูกนำไปใช้บนหัวกระดาษของใบลาและรายงานทุกประเภท</p>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ชื่อสถานศึกษา (ภาษาไทย)</label>
              <input v-model="form.institution_name" type="text" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm focus:border-[#800000] focus:ring-1 focus:ring-[#800000]">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">สังกัดหน่วยงาน</label>
              <input v-model="form.school_affiliation" type="text" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm focus:border-[#800000] focus:ring-1 focus:ring-[#800000]">
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">ที่อยู่สถานศึกษา</label>
            <input v-model="form.school_address" type="text" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm focus:border-[#800000] focus:ring-1 focus:ring-[#800000]">
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">หมายเลขโทรศัพท์</label>
              <input v-model="form.school_phone" type="text" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm focus:border-[#800000] focus:ring-1 focus:ring-[#800000]">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">อีเมลกลางสถานศึกษา</label>
              <input v-model="form.school_email" type="email" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm focus:border-[#800000] focus:ring-1 focus:ring-[#800000]">
            </div>
          </div>
        </div>

        <!-- Section 2: Signatories & Authorizers -->
        <div class="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm space-y-5">
          <div class="flex items-center gap-3 pb-3 border-b border-gray-100">
            <div class="w-9 h-9 rounded-xl bg-[#800000]/10 text-[#800000] flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.pencil"/>
              </svg>
            </div>
            <div>
              <h2 class="font-bold text-gray-900">ผู้มีอำนาจลงนามประจำตำแหน่ง</h2>
              <p class="text-xs text-gray-500">กำหนดชื่อตั้งต้นสำหรับบล็อกลงนามในไฟล์ PDF และ Word</p>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ชื่อ-นามสกุล ผู้อำนวยการ</label>
              <input v-model="form.director_name" type="text" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm focus:border-[#800000]">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ชื่อตำแหน่งผู้อำนวยการ</label>
              <input v-model="form.director_position" type="text" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm focus:border-[#800000]">
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ชื่อ-นามสกุล รองผู้อำนวยการ (ฝ่ายบริหาร)</label>
              <input v-model="form.deputy_admin_name" type="text" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm focus:border-[#800000]">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ชื่อ-นามสกุล หัวหน้างานบุคลากร (HR)</label>
              <input v-model="form.head_hr_name" type="text" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm focus:border-[#800000]">
            </div>
          </div>
        </div>

        <!-- Section 3: Page Margins & Font Settings -->
        <div class="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm space-y-5">
          <div class="flex items-center gap-3 pb-3 border-b border-gray-100">
            <div class="w-9 h-9 rounded-xl bg-[#800000]/10 text-[#800000] flex items-center justify-center">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.document"/>
              </svg>
            </div>
            <div>
              <h2 class="font-bold text-gray-900">ระยะขอบกระดาษและรูปแบบเลขที่หนังสือ</h2>
              <p class="text-xs text-gray-500">กำหนดระยะขอบกระดาษ PDF (ตามมาตรฐานสารบรรณ: บน 2.5 ซม., ซ้าย 3.0 ซม.)</p>
            </div>
          </div>

          <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ขอบบน (ซม.)</label>
              <input v-model="form.margin_top" type="number" step="0.1" min="0" max="10" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ขอบล่าง (ซม.)</label>
              <input v-model="form.margin_bottom" type="number" step="0.1" min="0" max="10" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ขอบซ้าย (ซม.)</label>
              <input v-model="form.margin_left" type="number" step="0.1" min="0" max="10" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ขอบขวา (ซม.)</label>
              <input v-model="form.margin_right" type="number" step="0.1" min="0" max="10" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm">
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ฟอนต์มาตรฐานเอกสาร</label>
              <select v-model="form.default_font" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm">
                <option value="TH Sarabun New">TH Sarabun New (มาตรฐานราชการ)</option>
                <option value="TH Sarabun PSK">TH Sarabun PSK</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">คำนำหน้าเลขที่หนังสือ</label>
              <input v-model="form.document_number_out" type="text" placeholder="เช่น ศธ 0584.1" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm">
            </div>
          </div>
        </div>

      </div>

      <!-- Right Column: Logo Uploaders & Format Status -->
      <div class="space-y-6">
        
        <!-- Garuda Logo Uploader -->
        <div class="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm space-y-4">
          <h3 class="font-bold text-gray-900 text-sm flex items-center gap-2">
            <svg class="w-4 h-4 flex-shrink-0 text-[#800000]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.photo"/>
            </svg>
            ตราครุฑมาตรฐาน (Garuda Logo)
          </h3>
          <div class="flex flex-col items-center justify-center p-4 border-2 border-dashed border-gray-200 rounded-2xl bg-gray-50/50">
            <img v-if="form.saraban_garuda_logo" v-secure-src="form.saraban_garuda_logo" alt="Garuda Logo" class="h-24 object-contain mb-3">
            <div v-else class="w-20 h-20 rounded-full bg-gray-200 flex items-center justify-center text-gray-400 mb-3">ตราครุฑ</div>
            <label class="px-4 py-2 rounded-xl bg-white border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50 shadow-sm cursor-pointer">
              <span>อัปโหลดตราครุฑใหม่</span>
              <input type="file" @change="uploadLogo($event, 'garuda')" accept="image/*" class="hidden">
            </label>
          </div>
          <p class="text-[11px] text-gray-400 text-center">* แนะนำไฟล์ PNG พื้นหลังโปร่งใส (Transparent)</p>
        </div>

        <!-- School Logo Uploader -->
        <div class="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm space-y-4">
          <h3 class="font-bold text-gray-900 text-sm flex items-center gap-2">
            <svg class="w-4 h-4 flex-shrink-0 text-[#800000]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.academic"/>
            </svg>
            ตราสัญลักษณ์สถานศึกษา
          </h3>
          <div class="flex flex-col items-center justify-center p-4 border-2 border-dashed border-gray-200 rounded-2xl bg-gray-50/50">
            <img v-if="form.organization_logo" v-secure-src="form.organization_logo" alt="School Logo" class="h-24 object-contain mb-3">
            <div v-else class="w-20 h-20 rounded-full bg-gray-200 flex items-center justify-center text-gray-400 mb-3">ตราโรงเรียน</div>
            <label class="px-4 py-2 rounded-xl bg-white border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50 shadow-sm cursor-pointer">
              <span>อัปโหลดตราสถานศึกษา</span>
              <input type="file" @change="uploadLogo($event, 'school')" accept="image/*" class="hidden">
            </label>
          </div>
        </div>

        <!-- Supported Formats Badge -->
        <div class="bg-gradient-to-br from-[#800000]/5 to-amber-50 rounded-2xl p-6 border border-[#800000]/15 space-y-3">
          <h4 class="font-bold text-[#800000] text-sm">รูปแบบไฟล์ที่รองรับการส่งออก</h4>
          <div class="space-y-2 text-xs">
            <div class="flex items-center justify-between p-2 rounded-lg bg-white border border-gray-100">
              <span class="inline-flex items-center gap-1.5 font-medium text-gray-700">
                <svg class="w-4 h-4 flex-shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.document"/>
                </svg>
                PDF (DomPDF + TH Sarabun)
              </span>
              <span class="text-green-600 font-bold">พร้อมใช้งาน</span>
            </div>
            <div class="flex items-center justify-between p-2 rounded-lg bg-white border border-gray-100">
              <span class="inline-flex items-center gap-1.5 font-medium text-gray-700">
                <svg class="w-4 h-4 flex-shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.documentDuplicate"/>
                </svg>
                Word (.docx Native)
              </span>
              <span class="text-green-600 font-bold">พร้อมใช้งาน</span>
            </div>
            <div class="flex items-center justify-between p-2 rounded-lg bg-white border border-gray-100">
              <span class="inline-flex items-center gap-1.5 font-medium text-gray-700">
                <svg class="w-4 h-4 flex-shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.table"/>
                </svg>
                Excel (.xlsx Native)
              </span>
              <span class="text-green-600 font-bold">พร้อมใช้งาน</span>
            </div>
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
import { ICONS } from '../../utils/icons'

const alertStore = useAlertStore()
const saving = ref(false)
const message = ref('')

const form = ref({
  institution_name: '',
  school_affiliation: '',
  school_address: '',
  school_phone: '',
  school_email: '',
  director_name: '',
  director_position: '',
  deputy_admin_name: '',
  head_hr_name: '',
  saraban_garuda_logo: '',
  organization_logo: '',
  default_font: 'TH Sarabun New',
  margin_top: '2.5',
  margin_bottom: '2.0',
  margin_left: '3.0',
  margin_right: '2.0',
  document_number_out: 'ศธ 0584.1',
})

const fetchSettings = async () => {
  try {
    const res = await api.get('/admin/export-settings')
    form.value = { ...form.value, ...res.data }
  } catch (err) {
    console.error('Error loading export settings', err)
  }
}

const saveSettings = async () => {
  saving.value = true
  message.value = ''
  try {
    await api.post('/admin/export-settings', form.value)
    message.value = 'บันทึกการตั้งค่าแบบฟอร์มและการส่งออกไฟล์เรียบร้อยแล้ว'
  } catch (err) {
    alertStore.error(Object.values(err.response?.data?.errors || {}).flat()[0] || 'เกิดข้อผิดพลาดในการบันทึกข้อมูล')
  } finally {
    saving.value = false
  }
}

const uploadLogo = async (e, type) => {
  const file = e.target.files[0]
  if (!file) return

  const formData = new FormData()
  formData.append('logo', file)
  formData.append('type', type)

  try {
    const res = await api.post('/admin/export-settings/upload-logo', formData)
    if (type === 'garuda') {
      form.value.saraban_garuda_logo = res.data.url
    } else {
      form.value.organization_logo = res.data.url
    }
    message.value = 'อัปโหลดตราสัญลักษณ์เรียบร้อยแล้ว'
  } catch (err) {
    alertStore.error(Object.values(err.response?.data?.errors || {}).flat()[0] || 'อัปโหลดไฟล์ล้มเหลว')
  }
}

onMounted(() => {
  fetchSettings()
})
</script>


