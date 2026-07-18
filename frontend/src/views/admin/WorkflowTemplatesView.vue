<template>
  <div class="p-6">
    <div class="flex justify-between items-center mb-6">
      <h2 class="text-2xl font-bold">จัดการสายการอนุมัติ (Workflow Templates)</h2>
      <button @click="openModal()" class="px-4 py-2 bg-[#800000] text-white rounded-lg text-sm hover:bg-red-800 flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
        สร้างเทมเพลตใหม่
      </button>
    </div>
    
    <div class="bg-white rounded-xl shadow p-6">
      <div v-if="loading" class="text-center py-12">
        <svg class="animate-spin h-8 w-8 mx-auto mb-2 text-[#800000]" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <p class="text-gray-500">กำลังโหลดข้อมูล...</p>
      </div>

      <div v-else-if="templates.length === 0" class="text-center py-12 text-gray-500">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
        <p>ยังไม่มีเทมเพลตสายการอนุมัติ</p>
        <p class="text-sm mt-1">คลิกปุ่ม "สร้างเทมเพลตใหม่" เพื่อเริ่มต้นกำหนดสายการอนุมัติเอกสาร</p>
      </div>
      
      <div v-else class="space-y-4">
        <div v-for="t in templates" :key="t.id" class="border rounded-lg p-4 flex flex-col md:flex-row justify-between md:items-center gap-4">
          <div>
            <h3 class="font-bold text-lg text-gray-800 flex items-center gap-2">
              {{ t.name }}
              <span v-if="!t.is_active" class="text-xs px-2 py-0.5 bg-gray-200 text-gray-600 rounded-full">ปิดใช้งาน</span>
            </h3>
            <p class="text-sm text-gray-500">{{ t.description }}</p>
            <div class="flex flex-wrap gap-2 mt-3">
              <span v-for="(step, i) in t.steps_config" :key="i" class="px-2 py-1 bg-gray-100 border text-xs rounded-full flex items-center gap-1">
                <span class="w-4 h-4 rounded-full bg-purple-500 text-white flex items-center justify-center">{{ i + 1 }}</span>
                {{ step.name }} ({{ step.action === 'approve' ? 'อนุมัติ' : 'ลงนาม' }})
              </span>
            </div>
          </div>
          <div class="flex gap-2 shrink-0">
            <button @click="openModal(t)" class="p-2 text-blue-600 hover:bg-blue-50 rounded" title="แก้ไข">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
            </button>
            <button @click="deleteTemplate(t.id)" class="p-2 text-red-600 hover:bg-red-50 rounded" title="ลบ">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
        <div class="px-6 py-4 border-b flex justify-between items-center">
          <h3 class="font-bold text-lg">{{ editingId ? 'แก้ไขเทมเพลต' : 'สร้างเทมเพลตใหม่' }}</h3>
          <button @click="showModal = false" class="text-gray-400 hover:text-gray-600">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
        
        <div class="p-6 overflow-y-auto flex-1">
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อเทมเพลต <span class="text-red-500">*</span></label>
              <input type="text" v-model="form.name" class="w-full border-gray-300 rounded-lg shadow-sm focus:border-[#800000] focus:ring-[#800000]">
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">คำอธิบาย</label>
              <textarea v-model="form.description" rows="2" class="w-full border-gray-300 rounded-lg shadow-sm focus:border-[#800000] focus:ring-[#800000]"></textarea>
            </div>

            <div class="flex items-center gap-2">
              <input type="checkbox" id="isActive" v-model="form.is_active" class="text-[#800000] rounded focus:ring-[#800000]">
              <label for="isActive" class="text-sm text-gray-700">เปิดใช้งานเทมเพลตนี้</label>
            </div>
            
            <div class="pt-4 border-t">
              <div class="flex justify-between items-center mb-3">
                <label class="block text-sm font-medium text-gray-700">ลำดับขั้นตอน (Steps)</label>
                <button type="button" @click="addStep" class="text-xs px-3 py-1 bg-gray-100 hover:bg-gray-200 rounded-lg flex items-center gap-1">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                  เพิ่มขั้นตอน
                </button>
              </div>
              
              <div class="space-y-3">
                <div v-for="(step, index) in form.steps_config" :key="index" class="flex gap-2 items-start border p-3 rounded-lg bg-gray-50 relative group">
                  <div class="flex-1 space-y-3">
                    <div class="flex items-center gap-2">
                      <span class="w-6 h-6 rounded-full bg-[#800000] text-white flex items-center justify-center text-xs font-bold shrink-0">{{ index + 1 }}</span>
                      <input type="text" v-model="step.name" placeholder="ชื่อขั้นตอน (เช่น หัวหน้าแผนก)" class="w-full border-gray-300 rounded text-sm p-1.5 focus:border-[#800000] focus:ring-[#800000]">
                    </div>
                    <div class="flex gap-3 pl-8">
                      <label class="flex items-center gap-1 text-sm">
                        <input type="radio" :name="'action_'+index" value="approve" v-model="step.action" class="text-[#800000] focus:ring-[#800000]">
                        แค่ตรวจสอบ/อนุมัติ
                      </label>
                      <label class="flex items-center gap-1 text-sm">
                        <input type="radio" :name="'action_'+index" value="sign" v-model="step.action" class="text-[#800000] focus:ring-[#800000]">
                        ต้องประทับลายเซ็น
                      </label>
                    </div>
                  </div>
                  <button @click="removeStep(index)" class="text-gray-400 hover:text-red-500 shrink-0 p-1">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                  </button>
                </div>
                
                <div v-if="form.steps_config.length === 0" class="text-center text-sm text-gray-500 py-4 bg-gray-50 rounded-lg border border-dashed">
                  ยังไม่ได้กำหนดขั้นตอน
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-3 rounded-b-xl">
          <button @click="showModal = false" class="px-4 py-2 text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">ยกเลิก</button>
          <button @click="saveTemplate" class="px-4 py-2 text-white bg-[#800000] rounded-lg hover:bg-red-800 transition-colors" :disabled="saving">
            {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../axios'

const templates = ref([])
const loading = ref(true)
const showModal = ref(false)
const saving = ref(false)
const editingId = ref(null)

const form = ref({
  name: '',
  description: '',
  is_active: true,
  steps_config: []
})

const fetchTemplates = async () => {
  loading.value = true
  try {
    const res = await api.get('/saraban/workflow-templates')
    templates.value = res.data
  } catch (error) {
    console.error('Failed to load templates:', error)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchTemplates()
})

const openModal = (template = null) => {
  if (template) {
    editingId.value = template.id
    form.value = {
      name: template.name,
      description: template.description || '',
      is_active: template.is_active,
      steps_config: JSON.parse(JSON.stringify(template.steps_config || []))
    }
  } else {
    editingId.value = null
    form.value = {
      name: '',
      description: '',
      is_active: true,
      steps_config: [
        { name: 'หัวหน้าแผนก', action: 'approve' }
      ]
    }
  }
  showModal.value = true
}

const addStep = () => {
  form.value.steps_config.push({ name: '', action: 'approve' })
}

const removeStep = (index) => {
  form.value.steps_config.splice(index, 1)
}

const saveTemplate = async () => {
  if (!form.value.name) {
    alert('กรุณากรอกชื่อเทมเพลต')
    return
  }
  if (form.value.steps_config.length === 0) {
    alert('กรุณาเพิ่มอย่างน้อย 1 ขั้นตอน')
    return
  }

  saving.value = true
  try {
    if (editingId.value) {
      await api.put(`/saraban/workflow-templates/${editingId.value}`, form.value)
    } else {
      await api.post('/saraban/workflow-templates', form.value)
    }
    showModal.value = false
    fetchTemplates()
  } catch (error) {
    console.error('Failed to save template:', error)
    alert('เกิดข้อผิดพลาดในการบันทึกข้อมูล')
  } finally {
    saving.value = false
  }
}

const deleteTemplate = async (id) => {
  if (!confirm('คุณแน่ใจหรือไม่ว่าต้องการลบเทมเพลตนี้?')) return

  try {
    await api.delete(`/saraban/workflow-templates/${id}`)
    fetchTemplates()
  } catch (error) {
    console.error('Failed to delete template:', error)
    alert('เกิดข้อผิดพลาดในการลบข้อมูล')
  }
}
</script>
