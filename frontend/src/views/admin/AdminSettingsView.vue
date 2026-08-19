<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">ข้อมูลสถานศึกษาและแบบฟอร์ม</h1>
        <p class="text-sm text-gray-500 mt-1">ข้อมูลหน่วยงาน ผู้ลงนาม และรูปแบบเอกสารที่ระบบใช้ออกหนังสือราชการ</p>
      </div>
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

        <!-- Tab 1: ข้อมูลสถานศึกษา -->
        <div v-show="currentTab === 'basic'" id="settings-panel-basic" role="tabpanel" class="space-y-6">
          <h3 class="text-lg leading-6 font-medium text-red-700 border-b pb-2">ข้อมูลสถานศึกษา</h3>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Left Column: Identity & Logo -->
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
                  <label class="w-1/3 text-sm font-medium text-gray-700">สังกัด :</label>
                  <input type="text" v-model="settings.school_affiliation" class="flex-1 border-gray-300 rounded-md shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
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
                  <img v-if="settings.organization_logo" v-secure-src="settings.organization_logo" alt="Logo" class="w-32 h-32 object-contain" />
                  <div v-else class="w-32 h-32 bg-gray-100 flex items-center justify-center rounded-full text-gray-400">ไม่มีรูปภาพ</div>
                </div>
              </div>
            </div>

            <!-- Right Column: Contact & Names -->
            <div class="space-y-6">
              <div class="border rounded-md p-4 space-y-4">
                <p class="text-sm font-medium text-gray-700">ที่อยู่และช่องทางติดต่อ</p>
                <div>
                  <label class="block text-sm text-gray-700 mb-1">ที่อยู่</label>
                  <input type="text" v-model="settings.school_address" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm text-gray-700 mb-1">โทรศัพท์</label>
                    <input type="text" v-model="settings.school_phone" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                  </div>
                  <div>
                    <label class="block text-sm text-gray-700 mb-1">เลขไปรษณีย์</label>
                    <input type="text" v-model="settings.postal_code" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                  </div>
                </div>
                <div>
                  <label class="block text-sm text-gray-700 mb-1">อีเมล</label>
                  <input type="email" v-model="settings.school_email" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                </div>
              </div>

              <div class="border rounded-md p-4 space-y-4">
                <p class="text-sm font-medium text-gray-700">ชื่อย่อและข้อมูลประกอบ</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm text-gray-700 mb-1">ชื่อย่อ (ภาษาไทย)</label>
                    <input type="text" v-model="settings.short_name_th" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                  </div>
                  <div>
                    <label class="block text-sm text-gray-700 mb-1">ชื่อย่อ (ภาษาอังกฤษ)</label>
                    <input type="text" v-model="settings.short_name_en" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                  </div>
                </div>
                <div>
                  <label class="block text-sm text-gray-700 mb-1">ชื่อสถานศึกษา (ภาษาอังกฤษ)</label>
                  <input type="text" v-model="settings.full_name_en" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div>
                    <label class="block text-sm text-gray-700 mb-1">วันก่อตั้ง</label>
                    <ThaiDatePicker v-model="settings.founding_date" />
                  </div>
                  <div>
                    <label class="block text-sm text-gray-700 mb-1">เวลาเข้าเรียน</label>
                    <input type="time" v-model="settings.start_time" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                  </div>
                  <div>
                    <label class="block text-sm text-gray-700 mb-1">เวลาเลิกเรียน</label>
                    <input type="time" v-model="settings.end_time" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Tab 2: ผู้บริหารและผู้ลงนาม -->
        <div v-show="currentTab === 'executives'" id="settings-panel-executives" role="tabpanel" class="space-y-6">
          <h3 class="text-lg leading-6 font-medium text-red-700 border-b pb-2">ผู้บริหารและผู้ลงนาม</h3>
          <p class="text-sm text-gray-500">ชื่อที่เลือกไว้จะถูกนำไปพิมพ์ในช่องลงนามของเอกสารที่ระบบออกให้</p>

          <div class="border rounded-md p-4">
            <label class="block text-sm text-gray-700 mb-1">ตำแหน่งผู้อำนวยการ (ข้อความใต้ลายเซ็น)</label>
            <input type="text" v-model="settings.director_position" placeholder="เช่น ผู้อำนวยการวิทยาลัย..." class="block w-full md:w-1/2 border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
          </div>

          <div class="border rounded-md p-4 grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3">
            <div v-for="field in executiveFields" :key="field.key" class="flex items-center">
              <label class="w-1/2 text-sm text-gray-700">{{ field.label }} :</label>
              <select v-model="settings[field.key]" class="flex-1 border border-gray-300 rounded shadow-sm py-1 px-2 focus:ring-red-500 focus:border-red-500 sm:text-sm">
                <option value="">-- เลือกจากผู้ใช้งาน --</option>
                <option v-for="user in users" :key="user.id" :value="user.full_name_with_title">{{ user.full_name_with_title }}</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Tab 3: แบบฟอร์มและการส่งออก -->
        <div v-show="currentTab === 'export'" id="settings-panel-export" role="tabpanel" class="space-y-6">
          <h3 class="text-lg leading-6 font-medium text-red-700 border-b pb-2">แบบฟอร์มและการส่งออกเอกสาร</h3>

          <div class="border rounded-md p-4 space-y-4">
            <p class="text-sm font-medium text-gray-700">ระยะขอบกระดาษ (เซนติเมตร)</p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div>
                <label class="block text-sm text-gray-700 mb-1">ขอบบน</label>
                <input type="number" step="0.1" min="0" max="10" v-model="settings.margin_top" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 sm:text-sm">
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">ขอบล่าง</label>
                <input type="number" step="0.1" min="0" max="10" v-model="settings.margin_bottom" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 sm:text-sm">
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">ขอบซ้าย</label>
                <input type="number" step="0.1" min="0" max="10" v-model="settings.margin_left" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 sm:text-sm">
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">ขอบขวา</label>
                <input type="number" step="0.1" min="0" max="10" v-model="settings.margin_right" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 sm:text-sm">
              </div>
            </div>
          </div>

          <div class="border rounded-md p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm text-gray-700 mb-1">แบบอักษรมาตรฐานของเอกสาร</label>
              <select v-model="settings.default_font" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 sm:text-sm">
                <option value="TH Sarabun New">TH Sarabun New</option>
                <option value="TH Sarabun PSK">TH Sarabun PSK</option>
                <option value="Sarabun">Sarabun</option>
              </select>
            </div>
            <div>
              <label class="block text-sm text-gray-700 mb-1">เลขที่หนังสือออก</label>
              <input type="text" v-model="settings.document_number_out" placeholder="เช่น ศธ 0584.1" class="block w-full border border-gray-300 rounded shadow-sm py-1 px-2 sm:text-sm">
            </div>
          </div>

          <p class="text-xs text-gray-500">
            ตราครุฑและรูปแบบเลขทะเบียนหนังสือรับ-ส่ง ตั้งค่าได้ที่เมนู "ตั้งค่าระบบสารบรรณ"
          </p>
        </div>

      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { onBeforeRouteLeave, useRoute } from 'vue-router'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import ThaiDatePicker from '../../components/ThaiDatePicker.vue'

const alertStore = useAlertStore()
const route = useRoute()

const tabs = [
  { id: 'basic', name: 'ข้อมูลสถานศึกษา' },
  { id: 'executives', name: 'ผู้บริหารและผู้ลงนาม' },
  { id: 'export', name: 'แบบฟอร์มและการส่งออก' }
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

// รองรับลิงก์เดิม /admin/export-settings ที่ถูก redirect มาพร้อม ?tab=export
const currentTab = ref(tabs.some(tab => tab.id === route.query.tab) ? String(route.query.tab) : 'basic')
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
  school_affiliation: '',
  vocational_province: '',
  vocational_institute: '',
  organization_logo: '',
  school_address: '',
  school_phone: '',
  school_email: '',
  postal_code: '',
  director_name: '',
  director_position: '',
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
  founding_date: '',
  start_time: '',
  end_time: '',
  document_number_out: '',
  default_font: 'TH Sarabun New',
  margin_top: '2.5',
  margin_bottom: '2.0',
  margin_left: '3.0',
  margin_right: '2.0'
})

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
