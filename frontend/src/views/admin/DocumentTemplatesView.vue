<template>
  <div class="max-w-7xl mx-auto space-y-8 pb-12">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 tracking-tight">ระบบจัดการและออกแบบแม่แบบเอกสาร</h1>
        <p class="text-sm text-gray-500 mt-1">ออกแบบ ปรับแต่งโครงสร้าง และสร้างแม่แบบเอกสารใหม่สำหรับทุกโมดูลในระบบ</p>
      </div>
      <div class="flex items-center gap-3">
        <button
          @click="seedDefaults"
          :disabled="seeding"
          class="px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 font-medium hover:bg-gray-50 text-sm transition-all"
        >
          {{ seeding ? 'กำลังซิงค์...' : '🔄 ซิงค์แม่แบบตั้งต้น' }}
        </button>
        <button
          @click="openModal(null)"
          class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-[#800000] text-white font-medium hover:bg-[#660000] active:scale-[0.98] transition-all shadow-sm cursor-pointer"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          <span>สร้างแม่แบบเอกสารใหม่</span>
        </button>
      </div>
    </div>

    <!-- Alert message -->
    <div v-if="message" class="p-4 rounded-xl bg-green-50 border border-green-200 text-green-800 text-sm flex items-center justify-between">
      <span>{{ message }}</span>
      <button @click="message = ''" class="text-green-600 font-semibold text-xs">ปิด</button>
    </div>

    <!-- Module Filters -->
    <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-3">
      <button
        v-for="m in modules"
        :key="m.value"
        @click="activeModule = m.value"
        :class="['px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer', activeModule === m.value ? 'bg-[#800000] text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']"
      >
        {{ m.label }} ({{ countByModule(m.value) }})
      </button>
    </div>

    <!-- Template Cards Grid -->
    <div v-if="loading" class="text-center py-12 text-gray-500 text-sm">กำลังโหลดแม่แบบเอกสาร...</div>
    <div v-else-if="filteredTemplates.length === 0" class="text-center py-16 bg-white rounded-2xl border border-gray-100 shadow-sm text-gray-400">
      ไม่พบแม่แบบเอกสารในโมดูลนี้ กดปุ่ม "ซิงค์แม่แบบตั้งต้น" หรือ "สร้างแม่แบบเอกสารใหม่"
    </div>
    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div
        v-for="tpl in filteredTemplates"
        :key="tpl.id"
        class="bg-white rounded-2xl p-6 border border-gray-100 shadow-sm hover:shadow-md transition-shadow flex flex-col justify-between"
      >
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-[#800000]/10 text-[#800000] uppercase">
              {{ tpl.module }}
            </span>
            <span class="text-xs font-mono text-gray-400">Code: {{ tpl.code }}</span>
          </div>
          <h3 class="font-bold text-gray-900 text-base leading-snug">{{ tpl.name }}</h3>
          <p class="text-xs text-gray-500 line-clamp-2">ขนาดกระดาษ: {{ tpl.paper_size }} {{ tpl.orientation }} (ระยะขอบ: {{ tpl.margin_top }}cm)</p>
        </div>

        <div class="mt-6 pt-4 border-t border-gray-100 flex items-center justify-between gap-2">
          <button @click="previewTemplate(tpl)" class="px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-100 rounded-lg">
            👁️ พรีวิว A4
          </button>
          <div class="flex gap-2">
            <button @click="openModal(tpl)" class="px-3 py-1.5 text-xs font-semibold text-[#800000] hover:bg-[#800000]/5 rounded-lg border border-[#800000]/20">
              แก้ไข
            </button>

            <button @click="deleteTemplate(tpl)" class="px-3 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 rounded-lg">
              ลบ
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Template Editor Modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl my-8 overflow-hidden flex flex-col max-h-[90vh]">
        <!-- Modal Header -->
        <div class="px-6 py-4 bg-[#800000] text-white flex items-center justify-between">
          <h3 class="font-bold text-base">{{ editingTpl ? 'แก้ไขแม่แบบเอกสาร' : 'สร้างแม่แบบเอกสารใหม่' }}</h3>
          <button @click="showModal = false" class="text-white/80 hover:text-white text-xl">✕</button>
        </div>

        <!-- Modal Body -->
        <div class="p-6 overflow-y-auto space-y-6 flex-1">
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">รหัสแม่แบบ (Code Key)</label>
              <input v-model="form.code" :disabled="!!editingTpl" type="text" placeholder="เช่น hr_certificate" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">ชื่อแม่แบบเอกสาร</label>
              <input v-model="form.name" type="text" placeholder="เช่น หนังสือรับรองเงินเดือน" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm">
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-700 mb-1">สังกัดโมดูล</label>
              <select v-model="form.module" class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 text-sm">
                <option value="leave">การลา (E-Leave)</option>
                <option value="hr">งานบุคลากร (HR)</option>
                <option value="saraban">งานสารบรรณ (Saraban)</option>
                <option value="attendance">เวลาปฏิบัติงาน (Attendance)</option>
                <option value="custom">แบบฟอร์มอื่นๆ (Custom)</option>
              </select>
            </div>
          </div>

          <!-- Variable Tags Palette -->
          <div class="bg-gray-50 rounded-xl p-4 border border-gray-200 space-y-2">
            <p class="text-xs font-bold text-gray-700">แท็กตัวแปรสำหรับแทรกในเนื้อหา (คลิกเพื่อคัดลอก):</p>
            <div class="flex flex-wrap gap-1.5 text-xs">
              <span v-for="tag in availableTags" :key="tag" @click="insertTag(tag)" class="px-2 py-1 bg-white border border-gray-200 rounded-md font-mono text-[11px] hover:bg-[#800000] hover:text-white cursor-pointer transition-colors">
                {{ tag }}
              </span>
            </div>
          </div>

          <!-- Code Editor & Preview Split -->
          <div class="space-y-2">
            <label class="block text-xs font-semibold text-gray-700">โค้ดโครงร่าง HTML / Blade Template</label>
            <textarea v-model="form.content_html" rows="12" class="w-full p-4 rounded-xl border border-gray-300 font-mono text-xs bg-gray-900 text-gray-100 focus:outline-none focus:ring-2 focus:ring-[#800000] resize-y" placeholder="<div class='text-center'>...</div>"></textarea>
          </div>
        </div>

        <!-- Modal Footer -->
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-3">
          <button @click="showModal = false" class="px-5 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 font-medium text-sm">ยกเลิก</button>
          <button @click="saveTemplate" :disabled="saving" class="px-6 py-2.5 rounded-xl bg-[#800000] text-white font-medium text-sm hover:bg-[#660000]">
            {{ saving ? 'กำลังบันทึก...' : 'บันทึกแม่แบบ' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Live A4 Preview Modal -->
    <div v-if="previewTpl" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 overflow-y-auto">
      <div class="bg-gray-200 rounded-2xl shadow-2xl w-full max-w-4xl p-6 relative max-h-[90vh] flex flex-col">
        <div class="flex justify-between items-center mb-4 text-gray-800">
          <h3 class="font-bold text-base">พรีวิวแม่แบบกระดาษ A4: {{ previewTpl.name }}</h3>
          <button @click="previewTpl = null" class="px-3 py-1 bg-white rounded-lg text-sm font-bold shadow-sm">✕ ปิด</button>
        </div>
        <!-- Simulated A4 Paper -->
        <iframe
          :srcdoc="previewTpl.content_html"
          sandbox=""
          title="ตัวอย่างแม่แบบเอกสาร"
          class="bg-white mx-auto shadow-xl flex-1 w-full max-w-[210mm] min-h-[297mm] rounded-sm"
        ></iframe>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { useAlertStore } from '../../stores/alert'

const alertStore = useAlertStore()
const templates = ref([])
const loading = ref(true)
const saving = ref(false)
const seeding = ref(false)
const message = ref('')
const activeModule = ref('all')
const showModal = ref(false)
const editingTpl = ref(null)
const previewTpl = ref(null)

const modules = [
  { label: 'ทั้งหมด', value: 'all' },
  { label: 'การลา (E-Leave)', value: 'leave' },
  { label: 'งานบุคลากร (HR)', value: 'hr' },
  { label: 'งานสารบรรณ (Saraban)', value: 'saraban' },
  { label: 'เวลาปฏิบัติงาน (Attendance)', value: 'attendance' },
]

const availableTags = [
  '{{ $school_name }}',
  '{{ $user_name }}',
  '{{ $position }}',
  '{{ $department }}',
  '{{ $leave_type }}',
  '{{ $start_date }}',
  '{{ $end_date }}',
  '{{ $total_days }}',
  '{{ $reason }}',
  '{{ $director_name }}',
  '{{ $director_position }}',
]

const form = ref({
  code: '',
  name: '',
  module: 'leave',
  paper_size: 'A4',
  orientation: 'portrait',
  margin_top: 8.0,
  margin_bottom: 8.0,
  margin_left: 12.0,
  margin_right: 12.0,
  content_html: '',
  is_active: true,
})

const fetchTemplates = async () => {
  loading.value = true
  try {
    const res = await axios.get('/api/admin/document-templates')
    templates.value = res.data
  } catch (err) {
    console.error('Failed to load templates', err)
  } finally {
    loading.value = false
  }
}

const countByModule = (mod) => {
  if (mod === 'all') return templates.value.length
  return templates.value.filter(t => t.module === mod).length
}

const filteredTemplates = computed(() => {
  if (activeModule.value === 'all') return templates.value
  return templates.value.filter(t => t.module === activeModule.value)
})

const openModal = (tpl) => {
  editingTpl.value = tpl
  if (tpl) {
    form.value = { ...tpl }
  } else {
    form.value = {
      code: '',
      name: '',
      module: 'leave',
      paper_size: 'A4',
      orientation: 'portrait',
      margin_top: 8.0,
      margin_bottom: 8.0,
      margin_left: 12.0,
      margin_right: 12.0,
      content_html: '<div class="text-center font-bold" style="font-size:18pt;">แบบฟอร์มเอกสารใหม่</div>',
      is_active: true,
    }
  }
  showModal.value = true
}

const insertTag = (tag) => {
  form.value.content_html += ' ' + tag
}

const saveTemplate = async () => {
  saving.value = true
  message.value = ''
  try {
    if (editingTpl.value) {
      await axios.put(`/api/admin/document-templates/${editingTpl.value.id}`, form.value)
      message.value = 'ปรับปรุงแม่แบบเอกสารเรียบร้อยแล้ว 🎉'
    } else {
      await axios.post('/api/admin/document-templates', form.value)
      message.value = 'สร้างแม่แบบเอกสารใหม่เรียบร้อยแล้ว 🎉'
    }
    showModal.value = false
    await fetchTemplates()
  } catch (err) {
    const errors = err.response?.data?.errors
    if (errors) {
      alertStore.error(Object.values(errors).flat().join(' | '))
    } else {
      alertStore.error(err.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึกแม่แบบเอกสาร')
    }
  } finally {
    saving.value = false
  }
}

const deleteTemplate = async (tpl) => {
  if (!confirm(`ต้องการลบแม่แบบ "${tpl.name}" ใช่หรือไม่?`)) return
  try {
    await axios.delete(`/api/admin/document-templates/${tpl.id}`)
    message.value = 'ลบแม่แบบเอกสารเรียบร้อยแล้ว'
    alertStore.success('ลบแม่แบบเอกสารเรียบร้อยแล้ว')
    await fetchTemplates()
  } catch (err) {
    alertStore.error(err.response?.data?.message || 'เกิดข้อผิดพลาดในการลบแม่แบบ')
  }
}

const seedDefaults = async () => {
  seeding.value = true
  try {
    await axios.post('/api/admin/document-templates/seed-defaults')
    message.value = 'ซิงค์แม่แบบตั้งต้นเรียบร้อยแล้ว 🎉'
    alertStore.success('ซิงค์แม่แบบตั้งต้นเรียบร้อยแล้ว')
    await fetchTemplates()
  } catch (err) {
    alertStore.error('ซิงค์ล้มเหลว')
  } finally {
    seeding.value = false
  }
}

const previewTemplate = (tpl) => {
  previewTpl.value = tpl
}

onMounted(() => {
  fetchTemplates()
})
</script>
