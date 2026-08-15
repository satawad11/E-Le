<template>
  <div>
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
      <div>
        <div class="flex items-center gap-3">
          <h1 class="text-2xl font-bold text-gray-900">จัดการลำดับการอนุมัติ</h1>
          <span v-if="hasUnsavedChanges" class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 animate-pulse">
            ยังไม่ได้บันทึก
          </span>
        </div>
        <p class="text-gray-500 mt-1 text-sm">กำหนดขั้นตอนและเงื่อนไขการค้นหาผู้อนุมัติใบลาตามโครงสร้างองค์กร (Personnel & Org Structure)</p>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <button
          @click="fetchWorkflow"
          :disabled="loading || saving"
          class="flex items-center gap-1.5 px-4 py-2.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 font-semibold rounded-xl text-sm transition-all shadow-sm disabled:opacity-50 min-h-[44px]"
          title="โหลดข้อมูลล่าสุด"
        >
          <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          รีเฟรช
        </button>

        <button
          @click="saveWorkflow"
          :disabled="saving || loading || steps.length === 0"
          class="flex items-center gap-2 px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm transition-all shadow-md hover:shadow-lg disabled:opacity-50 min-h-[44px]"
        >
          <div v-if="saving" class="animate-spin h-4 w-4 rounded-full border-2 border-white border-t-transparent"></div>
          <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2v-3M9 19v-6a2 2 0 00-2-2H5m14 0a2 2 0 012 2v2m-6 4h3m-3 0h-3"/>
          </svg>
          {{ saving ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่า' }}
        </button>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center py-16">
      <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <!-- Content Grid -->
    <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-8">
      <!-- Info sidebar -->
      <div class="lg:col-span-1 space-y-4">
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 text-amber-900 text-sm">
          <h3 class="font-bold flex items-center gap-2 text-amber-800 mb-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            หลักการทำงาน & Approver Resolvers
          </h3>
          <p class="mb-2">ระบบค้นหาผู้อนุมัติอัตโนมัติตาม<strong>สังกัดของผู้ยื่นคำขอ</strong> และโครงสร้างหน่วยงาน (Org Structure)</p>
          <p class="mb-2">คำขอลาเดิมที่สร้างไว้แล้วจะยึด <strong>Snapshot</strong> ขั้นตอน ณ เวลาที่สร้างคำขอ ไม่ถูกเปลี่ยนแปลงย้อนหลัง</p>
          <p>รองรับการทำหน้าที่แทน (Delegation) ของทุกระดับอย่างถูกต้อง</p>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-3">
          <h4 class="font-bold text-gray-800 text-sm flex items-center justify-between">
            <span>รูปแบบผู้อนุมัติที่รองรับ</span>
            <span class="text-xs font-normal text-gray-500">{{ resolverOptions.length }} รูปแบบ</span>
          </h4>
          <div class="space-y-2">
            <div v-for="res in resolverOptions" :key="res.value" class="flex justify-between items-center text-xs py-1.5 border-b border-gray-50 last:border-0">
              <span class="font-semibold text-gray-700">{{ res.label }}</span>
              <span class="text-gray-400 font-mono text-[10px]">{{ res.value }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Workflow Timeline / Steps Builder -->
      <div class="lg:col-span-2 space-y-4">
        <!-- Empty State -->
        <div v-if="steps.length === 0" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-12 text-center">
          <svg class="mx-auto h-12 w-12 text-gray-300 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
          </svg>
          <h3 class="text-gray-700 font-bold text-base mb-1">ยังไม่มีขั้นตอนการอนุมัติ</h3>
          <p class="text-gray-400 text-sm mb-6">กรุณาสร้างขั้นตอนการอนุมัติอย่างน้อย 1 ขั้นตอน เพื่อเปิดใช้งานกระบวนการยื่นคำขอลา</p>
          <button
            @click="addStep"
            class="px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white text-sm font-semibold rounded-xl transition-all inline-flex items-center gap-1.5 min-h-[44px]"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            เพิ่มขั้นตอนแรก
          </button>
        </div>

        <div v-else class="space-y-4">
          <!-- Steps Loop -->
          <div
            v-for="(step, index) in steps"
            :key="index"
            class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:border-gray-200 transition-colors relative flex flex-col sm:flex-row gap-4 items-start sm:items-center"
          >
            <!-- Step index badge -->
            <div class="w-9 h-9 rounded-full bg-[#800000]/10 text-[#800000] font-bold text-sm flex items-center justify-center flex-shrink-0">
              {{ index + 1 }}
            </div>

            <!-- Form fields -->
            <div class="flex-1 grid grid-cols-1 md:grid-cols-2 gap-4 w-full">
              <div class="md:col-span-2">
                <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">
                  ชื่อขั้นตอนการทำงาน <span class="text-red-500">*</span>
                </label>
                <input
                  v-model="step.name"
                  type="text"
                  required
                  placeholder="เช่น ตรวจสอบโดยหัวหน้าแผนก / เสนอผู้อำนวยการ"
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm transition-colors"
                />
              </div>

              <div>
                <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">
                  เงื่อนไขผู้อนุมัติ (Resolver) <span class="text-red-500">*</span>
                </label>
                <select
                  v-model="step.approver_type"
                  required
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm transition-colors text-gray-700 bg-white"
                >
                  <option v-for="res in resolverOptions" :key="res.value" :value="res.value">
                    {{ res.label }}
                  </option>
                </select>
              </div>

              <!-- Conditional: Specific Role -->
              <div v-if="step.approver_type === 'ROLE'">
                <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">
                  บทบาทที่รับผิดชอบ <span class="text-red-500">*</span>
                </label>
                <select
                  v-model="step.role_id"
                  required
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm transition-colors text-gray-700 bg-white"
                >
                  <option value="" disabled>เลือกบทบาท</option>
                  <option v-for="role in approvableRoles" :key="role.id" :value="role.id">
                    {{ roleLabel(role.name) }}
                  </option>
                </select>
              </div>

              <!-- Conditional: Specific User -->
              <div v-else-if="step.approver_type === 'SPECIFIC_USER'">
                <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">
                  ระบุผู้ใช้งานเฉพาะเจาะจง <span class="text-red-500">*</span>
                </label>
                <select
                  v-model="step.specific_user_id"
                  required
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm transition-colors text-gray-700 bg-white"
                >
                  <option value="" disabled>เลือกผู้ใช้งาน</option>
                  <option v-for="u in usersList" :key="u.id" :value="u.id">
                    {{ u.personnel?.full_name || u.name }} ({{ u.email }})
                  </option>
                </select>
              </div>

              <!-- Info hint for automatic resolvers -->
              <div v-else class="flex items-center text-xs text-blue-700 bg-blue-50 p-2.5 rounded-xl">
                <i class="fas fa-info-circle mr-1.5 text-blue-500"></i>
                <span>ระบบจะค้นหาผู้ดำรงตำแหน่ง/หัวหน้าหน่วยงานของผู้ยื่นอัตโนมัติ</span>
              </div>
            </div>

            <!-- Reorder and actions buttons -->
            <div class="flex items-center gap-1.5 flex-shrink-0 self-end sm:self-center">
              <!-- Move Up -->
              <button
                @click="moveUp(index)"
                :disabled="index === 0 || saving"
                class="w-10 h-10 rounded-xl hover:bg-gray-100 text-gray-500 hover:text-gray-800 disabled:opacity-30 transition-colors flex items-center justify-center"
                title="เลื่อนขึ้น"
                aria-label="เลื่อนขั้นตอนขึ้น"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                </svg>
              </button>
              <!-- Move Down -->
              <button
                @click="moveDown(index)"
                :disabled="index === steps.length - 1 || saving"
                class="w-10 h-10 rounded-xl hover:bg-gray-100 text-gray-500 hover:text-gray-800 disabled:opacity-30 transition-colors flex items-center justify-center"
                title="เลื่อนลง"
                aria-label="เลื่อนขั้นตอนลง"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
              </button>
              <!-- Delete -->
              <button
                @click="confirmRemoveStep(index)"
                :disabled="saving"
                class="w-10 h-10 rounded-xl hover:bg-red-50 text-red-500 hover:text-red-700 transition-colors flex items-center justify-center"
                title="ลบขั้นตอนนี้"
                aria-label="ลบขั้นตอนนี้"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
              </button>
            </div>
          </div>

          <!-- Add step button at the bottom -->
          <button
            @click="addStep"
            :disabled="saving"
            class="w-full py-3.5 border-2 border-dashed border-gray-200 hover:border-[#800000]/40 text-gray-500 hover:text-[#800000] font-semibold rounded-2xl text-sm transition-all flex items-center justify-center gap-2 bg-gray-50/50 hover:bg-white hover:shadow-sm min-h-[48px]"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            เพิ่มขั้นตอนอนุมัติ
          </button>
        </div>
      </div>
    </div>

    <!-- Delete Step Confirmation Modal -->
    <div
      v-if="deleteModal.show"
      class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4"
      @click.self="deleteModal.show = false"
    >
      <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="w-12 h-12 rounded-full bg-red-100 text-red-600 flex items-center justify-center mx-auto">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
          </svg>
        </div>
        <div class="text-center">
          <h3 class="text-lg font-bold text-gray-900">ยืนยันการลบขั้นตอน?</h3>
          <p class="text-sm text-gray-500 mt-1">
            คุณต้องการลบขั้นตอนที่ {{ deleteModal.index + 1 }}
            <span v-if="deleteModal.name" class="font-semibold text-gray-800">"{{ deleteModal.name }}"</span>
            หรือไม่?
          </p>
        </div>
        <div class="flex justify-end gap-3 pt-2">
          <button
            type="button"
            @click="deleteModal.show = false"
            class="px-4 py-2.5 rounded-xl border border-gray-200 text-gray-700 font-medium hover:bg-gray-50 text-sm min-h-[44px]"
          >
            ยกเลิก
          </button>
          <button
            type="button"
            @click="executeRemoveStep"
            class="px-4 py-2.5 rounded-xl bg-red-600 text-white font-medium hover:bg-red-700 text-sm shadow-md transition min-h-[44px]"
          >
            ยืนยันลบขั้นตอน
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'

const alertStore = useAlertStore()

const loading = ref(false)
const saving = ref(false)
const roles = ref([])
const usersList = ref([])
const steps = ref([])
const initialStepsSnapshot = ref('')
const currentFingerprint = ref('')

const deleteModal = ref({
  show: false,
  index: -1,
  name: ''
})

const resolverOptions = [
  { value: 'DEPARTMENT_HEAD', label: '🏢 หัวหน้าแผนกวิชา / หัวหน้างาน (ต้นสังกัดผู้ยื่น)' },
  { value: 'REQUESTER_SUPERVISOR', label: '👤 ผู้บังคับบัญชาชั้นต้น' },
  { value: 'DIVISION_DIRECTOR', label: '👔 หัวหน้าฝ่าย / รองผู้อำนวยการ' },
  { value: 'DIRECTOR', label: '🏛️ ผู้อำนวยการสถานศึกษา' },
  { value: 'HR_OFFICER', label: '📋 เจ้าหน้าที่งานบุคลากร' },
  { value: 'ROLE', label: '🛡️ กำหนดตามบทบาทระบบ (System Role)' },
  { value: 'SPECIFIC_USER', label: '🎯 ระบุตัวบุคคลรายคน (Specific User)' },
]

const roleLabel = (name) => {
  const map = {
    'ผู้ดูแลระบบสูงสุด': '👑 ผู้ดูแลระบบสูงสุด',
    'ผู้อำนวยการ': '🏢 ผู้อำนวยการ',
    'รองผู้อำนวยการ': '👔 รองผู้อำนวยการ',
    'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย': '📁 หัวหน้าแผนกวิชา / หัวหน้าฝ่าย',
    'เจ้าหน้าที่บุคลากร (HR)': '📋 เจ้าหน้าที่บุคลากร (HR)',
    'เจ้าหน้าที่สารบรรณกลาง': '📮 เจ้าหน้าที่สารบรรณกลาง',
    'บุคลากร / ครู / เจ้าหน้าที่': '👤 บุคลากร / ครู / เจ้าหน้าที่',
    'Super Admin': '👑 ผู้ดูแลระบบสูงสุด',
    'SuperAdmin':  '👑 ผู้ดูแลระบบสูงสุด',
    'Director':    '🏢 ผู้อำนวยการ',
    'Deputy Director': '👔 รองผู้อำนวยการ',
    'Deputy':      '👔 รองผู้อำนวยการ',
    'HR Head':     '📋 หัวหน้าบุคลากร',
    'HRHead':      '📋 หัวหน้าบุคลากร',
    'Employee':    '👤 พนักงานทั่วไป',
  }
  return map[name] || name || ''
}

const approvableRoles = computed(() => {
  return roles.value.filter(r => r.name !== 'Employee')
})

const serializeSteps = (stepList) => {
  return JSON.stringify(stepList.map(s => ({
    role_id: Number(s.role_id) || '',
    approver_type: s.approver_type || 'ROLE',
    specific_user_id: Number(s.specific_user_id) || '',
    name: (s.name || '').trim(),
    step_order: Number(s.step_order) || 0
  })))
}

const hasUnsavedChanges = computed(() => {
  if (loading.value) return false
  return serializeSteps(steps.value) !== initialStepsSnapshot.value
})

const reindexSteps = () => {
  steps.value.forEach((step, idx) => {
    step.step_order = idx + 1
  })
}

const fetchWorkflow = async () => {
  loading.value = true
  try {
    const [rolesRes, stepsRes, usersRes] = await Promise.all([
      api.get('/admin/roles'),
      api.get('/admin/workflow-steps'),
      api.get('/users/list')
    ])
    roles.value = Array.isArray(rolesRes.data) ? rolesRes.data : (rolesRes.data?.data || [])
    usersList.value = Array.isArray(usersRes.data) ? usersRes.data : (usersRes.data?.data || [])
    
    const rawSteps = Array.isArray(stepsRes.data) 
      ? stepsRes.data 
      : (stepsRes.data?.steps || stepsRes.data?.data || [])

    currentFingerprint.value = stepsRes.headers?.['x-workflow-fingerprint'] || stepsRes.data?.fingerprint || ''

    steps.value = rawSteps.map((s, idx) => ({
      id: s.id,
      role_id: s.role_id || '',
      approver_type: s.approver_type || 'ROLE',
      specific_user_id: s.specific_user_id || '',
      name: s.name,
      step_order: s.step_order || (idx + 1)
    }))
    
    reindexSteps()
    initialStepsSnapshot.value = serializeSteps(steps.value)
  } catch (e) {
    console.error(e)
    alertStore.error('เกิดข้อผิดพลาดในการดึงข้อมูลลำดับการอนุมัติ')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchWorkflow()
  window.addEventListener('beforeunload', handleBeforeUnload)
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', handleBeforeUnload)
})

const handleBeforeUnload = (e) => {
  if (hasUnsavedChanges.value) {
    e.preventDefault()
    e.returnValue = ''
  }
}

onBeforeRouteLeave((to, from, next) => {
  if (hasUnsavedChanges.value) {
    const answer = window.confirm('คุณมีการเปลี่ยนแปลงลำดับการอนุมัติที่ยังไม่ได้บันทึก ต้องการออกจากหน้านี้หรือไม่?')
    if (answer) next()
    else next(false)
  } else {
    next()
  }
})

const addStep = () => {
  steps.value.push({
    role_id: '',
    approver_type: 'DEPARTMENT_HEAD',
    specific_user_id: '',
    name: 'ตรวจสอบโดยหัวหน้างาน',
    step_order: steps.value.length + 1
  })
  reindexSteps()
}

const confirmRemoveStep = (index) => {
  deleteModal.value = {
    show: true,
    index,
    name: steps.value[index]?.name || ''
  }
}

const executeRemoveStep = () => {
  if (deleteModal.value.index >= 0 && deleteModal.value.index < steps.value.length) {
    steps.value.splice(deleteModal.value.index, 1)
    reindexSteps()
  }
  deleteModal.value.show = false
}

const moveUp = (index) => {
  if (index === 0) return
  const temp = steps.value[index]
  steps.value[index] = steps.value[index - 1]
  steps.value[index - 1] = temp
  reindexSteps()
}

const moveDown = (index) => {
  if (index === steps.value.length - 1) return
  const temp = steps.value[index]
  steps.value[index] = steps.value[index + 1]
  steps.value[index + 1] = temp
  reindexSteps()
}

const saveWorkflow = async () => {
  if (steps.value.length === 0) {
    alertStore.error('กรุณาระบุขั้นตอนการอนุมัติอย่างน้อย 1 ขั้นตอน')
    return
  }

  for (let i = 0; i < steps.value.length; i++) {
    const s = steps.value[i]
    if (!s.name || !s.name.trim()) {
      alertStore.error(`กรุณากรอกชื่อขั้นตอนในลำดับที่ ${i + 1}`)
      return
    }
    if (s.approver_type === 'ROLE' && !s.role_id) {
      alertStore.error(`กรุณาเลือกบทบาทผู้มีสิทธิ์อนุมัติในลำดับที่ ${i + 1}`)
      return
    }
    if (s.approver_type === 'SPECIFIC_USER' && !s.specific_user_id) {
      alertStore.error(`กรุณาระบุผู้ใช้งานในลำดับที่ ${i + 1}`)
      return
    }
  }

  reindexSteps()
  saving.value = true

  try {
    const payload = {
      steps: steps.value.map(s => ({
        role_id: s.role_id ? Number(s.role_id) : null,
        approver_type: s.approver_type || 'ROLE',
        specific_user_id: s.specific_user_id ? Number(s.specific_user_id) : null,
        name: s.name.trim(),
        step_order: Number(s.step_order)
      })),
      fingerprint: currentFingerprint.value
    }

    const res = await api.post('/admin/workflow-steps', payload)
    alertStore.success(res.data.message || 'บันทึกลำดับการอนุมัติเรียบร้อยแล้ว')
    
    currentFingerprint.value = res.headers?.['x-workflow-fingerprint'] || res.data?.fingerprint || ''
    
    const returnedSteps = res.data?.steps || []
    if (returnedSteps.length > 0) {
      steps.value = returnedSteps.map((s, idx) => ({
        id: s.id,
        role_id: s.role_id || '',
        approver_type: s.approver_type || 'ROLE',
        specific_user_id: s.specific_user_id || '',
        name: s.name,
        step_order: s.step_order || (idx + 1)
      }))
    }

    reindexSteps()
    initialStepsSnapshot.value = serializeSteps(steps.value)
  } catch (e) {
    console.error(e)
    if (e.response?.status === 409) {
      alertStore.error(e.response?.data?.message || 'ข้อมูลถูกแก้ไขโดยผู้ใช้อื่น กรุณารีเฟรชเพื่อโหลดข้อมูลล่าสุด')
      if (e.response?.data?.current_steps) {
        currentFingerprint.value = e.response.data.current_fingerprint || ''
      }
    } else {
      alertStore.error(e.response?.data?.message || Object.values(e.response?.data?.errors || {})[0]?.[0] || 'เกิดข้อผิดพลาดในการบันทึกข้อมูล')
    }
  } finally {
    saving.value = false
  }
}
</script>
