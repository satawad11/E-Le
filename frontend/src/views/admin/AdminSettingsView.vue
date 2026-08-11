<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-800">ตั้งค่าระบบ</h1>
      <button @click="saveSettings" :disabled="isSaving" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md font-medium text-sm flex items-center gap-2 transition-colors disabled:opacity-50">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
        {{ isSaving ? 'กำลังบันทึก...' : 'บันทึกข้อมูล' }}
      </button>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200">
      <nav class="-mb-px flex gap-6 overflow-x-auto" role="tablist" aria-label="หมวดการตั้งค่าระบบ">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="currentTab = tab.id"
          @keydown.left.prevent="moveTab(-1)"
          @keydown.right.prevent="moveTab(1)"
          role="tab"
          :aria-selected="currentTab === tab.id"
          :aria-controls="'settings-panel-' + tab.id"
          :class="[
            currentTab === tab.id
              ? 'border-red-500 text-red-600'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
            'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
          ]"
        >
          {{ tab.name }}
        </button>
      </nav>
    </div>

    <div class="bg-white shadow rounded-lg p-6">
      <form @submit.prevent="saveSettings">
        
        <!-- Tab 1: ข้อมูลพื้นฐานของสถานศึกษา -->
        <div v-show="currentTab === 'basic'" id="settings-panel-basic" role="tabpanel" class="space-y-6">
          <h3 class="text-lg leading-6 font-medium text-red-700 border-b pb-2">ข้อมูลพื้นฐานของสถานศึกษา</h3>
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Left Column: Info & Logo -->
            <div class="space-y-6">
              <div class="border rounded-md p-4 space-y-4">
                <div class="flex items-center">
                  <label class="w-1/3 text-sm font-medium text-gray-700">รหัสสถานศึกษา :</label>
                  <input type="text" v-model="settings.institution_code" class="flex-1 border-gray-300 rounded-md shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                </div>
                <div class="flex items-center">
                  <label class="w-1/3 text-sm font-medium text-gray-700">ประเภทสถานศึกษา :</label>
                  <input type="text" v-model="settings.institution_type" class="flex-1 border-gray-300 rounded-md shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                </div>
                <div class="flex items-center">
                  <label class="w-1/3 text-sm font-medium text-gray-700">ชื่อสถานศึกษา :</label>
                  <input type="text" v-model="settings.institution_name" class="flex-1 border-gray-300 rounded-md shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                </div>
                <div class="flex items-center">
                  <label class="w-1/3 text-sm font-medium text-gray-700">อาชีวศึกษาจังหวัด :</label>
                  <input type="text" v-model="settings.vocational_province" class="flex-1 border-gray-300 rounded-md shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                </div>
                <div class="flex items-center">
                  <label class="w-1/3 text-sm font-medium text-gray-700">สถาบันการอาชีวศึกษา :</label>
                  <input type="text" v-model="settings.vocational_institute" class="flex-1 border-gray-300 rounded-md shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                </div>
              </div>

              <div class="border rounded-md p-4 space-y-4 text-center">
                <p class="text-sm font-medium text-gray-700 text-left">ตราสัญลักษณ์สถานศึกษา</p>
                <div class="flex justify-start gap-2">
                  <input type="file" ref="logoInput" @change="handleLogoChange" class="hidden" accept="image/jpeg,image/png,image/jpg" />
                  <button type="button" @click="$refs.logoInput.click()" class="bg-white border border-gray-300 text-gray-700 px-3 py-1 rounded text-sm hover:bg-gray-50">เลือกรูป (.jpg)</button>
                  <button type="button" @click="deleteLogo" :disabled="isDeletingLogo || !settings.organization_logo" class="bg-red-500 text-white px-3 py-1 rounded text-sm hover:bg-red-600 disabled:opacity-50">{{ isDeletingLogo ? 'กำลังลบ...' : 'ลบรูปภาพ' }}</button>
                </div>
                <div class="mt-4 flex justify-center">
                  <img v-if="settings.organization_logo" :src="settings.organization_logo" alt="Logo" class="w-32 h-32 object-contain" />
                  <div v-else class="w-32 h-32 bg-gray-100 flex items-center justify-center rounded-full text-gray-400">ไม่มีรูปภาพ</div>
                </div>
              </div>
            </div>

            <!-- Right Column: Executives -->
            <div class="border rounded-md p-4 space-y-3">
              <p class="text-sm font-medium text-gray-700 mb-2">สมาชิกของผู้บริหาร</p>
              
              <div v-for="field in executiveFields" :key="field.key" class="flex items-center">
                <label class="w-1/2 text-sm text-gray-700">{{ field.label }} :</label>
                <select v-model="settings[field.key]" class="flex-1 border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                  <option value="">-- เลือกจากผู้ใช้งาน --</option>
                  <option v-for="user in users" :key="user.id" :value="user.full_name_with_title">{{ user.full_name_with_title }}</option>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- Tab 2: ข้อมูลเพิ่มเติม -->
        <div v-show="currentTab === 'additional'" id="settings-panel-additional" role="tabpanel" class="space-y-6">
          <h3 class="text-lg leading-6 font-medium text-red-700 border-b pb-2">ข้อมูลเพิ่มเติม</h3>
          
          <div class="border rounded-md p-4 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm text-gray-700 mb-1">ชื่อย่อสถานศึกษา (ภาษาไทย)</label>
                <input type="text" v-model="settings.short_name_th" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">ชื่อย่อสถานศึกษา (ภาษาอังกฤษ)</label>
                <input type="text" v-model="settings.short_name_en" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div class="md:col-span-2">
                <label class="block text-sm text-gray-700 mb-1">ชื่อสถานศึกษา (ภาษาอังกฤษ)</label>
                <input type="text" v-model="settings.full_name_en" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">ประเภทโรงเรียน</label>
                <select v-model="settings.school_type" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                  <option value="ในระบบ">ในระบบ</option>
                  <option value="นอกระบบ">นอกระบบ</option>
                </select>
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">ภาษาที่สอน</label>
                <input type="text" v-model="settings.teaching_language" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div>
                <label class="block text-sm text-gray-700 mb-1 text-red-500">วันก่อตั้ง *</label>
                <ThaiDatePicker v-model="settings.founding_date" />
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1 text-red-500">เวลาเข้าเรียน *</label>
                <input type="time" v-model="settings.start_time" required class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1 text-red-500">เวลาเลิกเรียน *</label>
                <input type="time" v-model="settings.end_time" required class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">เลขที่หนังสือออก</label>
                <input type="text" v-model="settings.document_number_out" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
              </div>
            </div>

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
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">รัศมีลงเวลาสูงสุด (เมตร)</label>
                <input type="number" min="0" max="100000" v-model.number="settings.max_attendance_radius" class="block w-full border border-gray-300 rounded py-1 px-2 sm:text-sm">
              </div>
              <label class="flex items-center gap-3 text-sm text-gray-700">
                <input type="checkbox" v-model="settings.require_attendance_selfie" true-value="1" false-value="0" class="h-5 w-5 rounded border-gray-300 text-red-600">
                บังคับถ่ายภาพขณะลงเวลา
              </label>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ยกเลิกใบลาได้ภายใน (วัน)</label>
                <input type="number" min="0" max="365" v-model.number="settings.cancel_allowed_days" class="block w-full border border-gray-300 rounded py-1 px-2 sm:text-sm">
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">เก็บหลักฐานใบลา (วัน)</label>
                <input type="number" min="1" max="36500" v-model.number="settings.leave_evidence_retention_days" class="block w-full border border-gray-300 rounded py-1 px-2 sm:text-sm">
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm text-gray-700 mb-1">ที่ทำการไปรษณีย์</label>
                <input type="text" v-model="settings.post_office_branch" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">เลขไปรษณีย์</label>
                <input type="text" v-model="settings.postal_code" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">แหล่งน้ำที่ใช้</label>
                <input type="text" v-model="settings.water_source" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm text-gray-700 mb-1">จำนวนชั่วโมงในการใช้อินเตอร์เน็ต</label>
                <input type="number" v-model.number="settings.internet_hours" min="0" max="168" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">จำนวนคอมพิวเตอร์สำหรับการเรียนการสอน</label>
                <input type="number" v-model.number="settings.teaching_computers" min="0" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">จำนวนคอมพิวเตอร์สำหรับการบริหารจัดการ</label>
                <input type="number" v-model.number="settings.admin_computers" min="0" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm text-gray-700 mb-1 text-red-500">เลือกระบบไฟฟ้า *</label>
                <select v-model="settings.electricity_system" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                  <option value="มีไฟฟ้าจากหน่วยงานรัฐ-รัฐวิสาหกิจ">มีไฟฟ้าจากหน่วยงานรัฐ-รัฐวิสาหกิจ</option>
                  <option value="ไม่มีไฟฟ้า">ไม่มีไฟฟ้า</option>
                </select>
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1 text-red-500">เลือกระบบโทรศัพท์ *</label>
                <select v-model="settings.telephone_system" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                  <option value="มีโทรศัพท์พื้นฐาน">มีโทรศัพท์พื้นฐาน</option>
                  <option value="ไม่มีโทรศัพท์พื้นฐาน">ไม่มีโทรศัพท์พื้นฐาน</option>
                </select>
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1 text-red-500">เลือกระบบอินเตอร์เน็ต *</label>
                <select v-model="settings.internet_system" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                  <option value="มีการเชื่อมต่ออินเตอร์เน็ตด้วย ADSL (Broadband)">มีการเชื่อมต่ออินเตอร์เน็ตด้วย ADSL (Broadband)</option>
                  <option value="ไม่มีการเชื่อมต่อ">ไม่มีการเชื่อมต่อ</option>
                </select>
              </div>
            </div>

          </div>
        </div>


        
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import ThaiDatePicker from '../../components/ThaiDatePicker.vue'

const alertStore = useAlertStore()

const tabs = [
  { id: 'basic', name: 'ข้อมูลพื้นฐานของสถานศึกษา' },
  { id: 'additional', name: 'ข้อมูลเพิ่มเติม' }
]

const executiveFields = [
  { label: 'ชื่อผู้อำนวยการ', key: 'director_name' },
  { label: 'ชื่อรองรักษาการ', key: 'deputy_acting_name' },
  { label: 'ชื่อรองฝ่ายวิชาการ', key: 'deputy_academic_name' },
  { label: 'ชื่อรองฝ่ายพัฒนาฯ', key: 'deputy_development_name' },
  { label: 'ชื่อรองฝ่ายบริหารฯ', key: 'deputy_admin_name' },
  { label: 'ชื่อรองฝ่ายแผนฯ', key: 'deputy_planning_name' },
  { label: 'ชื่อหัวหน้างานบุคลากร', key: 'head_hr_name' },
  { label: 'ชื่อหัวหน้างานทะเบียน', key: 'head_registrar_name' },
  { label: 'ชื่อหัวหน้าการเงิน', key: 'head_finance_name' },
  { label: 'ชื่อหัวหน้างานหลักสูตร', key: 'head_curriculum_name' },
  { label: 'ชื่อหัวหน้างานวัดผล', key: 'head_evaluation_name' },
  { label: 'ชื่อหัวหน้างานแนะแนว', key: 'head_guidance_name' },
  { label: 'ชื่อหัวหน้างานปกครอง', key: 'head_discipline_name' },
  { label: 'ชื่อหัวหน้างานกิจกรรม', key: 'head_activity_name' },
  { label: 'หัวหน้างานศูนย์ข้อมูล', key: 'head_data_center_name' },
]

const currentTab = ref('basic')
const isSaving = ref(false)
const isDeletingLogo = ref(false)
const users = ref([])
const initialSnapshot = ref('')
const isDirty = computed(() => Boolean(initialSnapshot.value) && JSON.stringify(settings.value) !== initialSnapshot.value)

const validationMessage = (error, fallback) => Object.values(error.response?.data?.errors || {}).flat()[0] || fallback

const syncSnapshotField = (key, value) => {
  if (!initialSnapshot.value) return
  const snapshot = JSON.parse(initialSnapshot.value)
  snapshot[key] = value
  initialSnapshot.value = JSON.stringify(snapshot)
}

const moveTab = async (direction) => {
  const index = tabs.findIndex(tab => tab.id === currentTab.value)
  currentTab.value = tabs[(index + direction + tabs.length) % tabs.length].id
  await nextTick()
  document.querySelector('[role="tab"][aria-selected="true"]')?.focus()
}

const settings = ref({
  institution_code: '',
  institution_type: '',
  institution_name: '',
  vocational_province: '',
  vocational_institute: '',
  organization_logo: '',
  director_name: '',
  deputy_acting_name: '',
  deputy_academic_name: '',
  deputy_development_name: '',
  deputy_admin_name: '',
  deputy_planning_name: '',
  head_hr_name: '',
  head_registrar_name: '',
  head_finance_name: '',
  head_curriculum_name: '',
  head_evaluation_name: '',
  head_guidance_name: '',
  head_discipline_name: '',
  head_activity_name: '',
  head_data_center_name: '',
  short_name_th: '',
  short_name_en: '',
  full_name_en: '',
  school_type: 'ในระบบ',
  teaching_language: 'ภาษาไทย',
  document_number_out: '',
  founding_date: '',
  start_time: '',
  end_time: '',
  post_office_branch: '',
  postal_code: '',
  water_source: '',
  internet_hours: '',
  teaching_computers: '',
  admin_computers: '',
  electricity_system: 'มีไฟฟ้าจากหน่วยงานรัฐ-รัฐวิสาหกิจ',
  telephone_system: 'มีโทรศัพท์พื้นฐาน',
  internet_system: 'มีการเชื่อมต่ออินเตอร์เน็ตด้วย ADSL (Broadband)',
  college_latitude: '',
  college_longitude: '',
  default_work_start_time: '',
  default_work_end_time: '',
  max_attendance_radius: '',
  require_attendance_selfie: '0',
  cancel_allowed_days: 5,
  leave_evidence_retention_days: 2555
})

const formatThaiDate = (dateString) => {
  if (!dateString) return ''
  const date = new Date(dateString)
  if (isNaN(date.getTime())) return dateString
  
  const months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.']
  const d = date.getDate()
  const m = months[date.getMonth()]
  const y = date.getFullYear() + 543
  
  return `${d} ${m} ${y}`
}

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

const fetchUsers = async () => {
  try {
    const res = await api.get('/admin/users')
    users.value = res.data.data || res.data
  } catch (error) {
    console.error('Failed to fetch users')
  }
}

const saveSettings = async () => {
  isSaving.value = true
  try {
    await api.post('/admin/settings', settings.value)
    initialSnapshot.value = JSON.stringify(settings.value)
    alertStore.success('บันทึกการตั้งค่าระบบเรียบร้อยแล้ว')
  } catch (error) {
    alertStore.error(validationMessage(error, 'เกิดข้อผิดพลาดในการบันทึก'))
  } finally {
    isSaving.value = false
  }
}

const handleLogoChange = async (event) => {
  const file = event.target.files[0]
  if (!file) return

  const formData = new FormData()
  formData.append('logo', file)

  try {
    const response = await api.post('/admin/settings/upload-logo', formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    })
    settings.value.organization_logo = response.data.url
    syncSnapshotField('organization_logo', response.data.url)
    alertStore.success('อัพโหลดรูปภาพสำเร็จ')
  } catch (error) {
    alertStore.error('อัพโหลดรูปภาพล้มเหลว')
  }
}

const deleteLogo = async () => {
  if (!window.confirm('ยืนยันการลบโลโก้สถานศึกษา?')) return
  isDeletingLogo.value = true
  try {
    await api.delete('/admin/settings/logo')
    settings.value.organization_logo = ''
    syncSnapshotField('organization_logo', '')
    alertStore.success('ลบโลโก้เรียบร้อยแล้ว')
  } catch (error) {
    alertStore.error(validationMessage(error, 'ลบโลโก้ไม่สำเร็จ'))
  } finally {
    isDeletingLogo.value = false
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
  fetchUsers()
  window.addEventListener('beforeunload', handleBeforeUnload)
})

onBeforeUnmount(() => window.removeEventListener('beforeunload', handleBeforeUnload))
</script>


