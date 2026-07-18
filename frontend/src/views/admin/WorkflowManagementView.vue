<template>
  <div>
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">จัดการลำดับการอนุมัติ</h1>
        <p class="text-gray-500 mt-1">กำหนดขั้นตอนและบทบาทผู้มีสิทธิ์อนุมัติใบลาตามลำดับความสำคัญ</p>
      </div>
      <button
        @click="saveWorkflow"
        :disabled="saving"
        class="flex items-center gap-2 px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm transition-all shadow-md hover:shadow-lg disabled:opacity-50"
      >
        <div v-if="saving" class="animate-spin h-4 w-4 rounded-full border-2 border-white border-t-transparent"></div>
        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2v-3M9 19v-6a2 2 0 00-2-2H5m14 0a2 2 0 012 2v2m-6 4h3m-3 0h-3"/>
        </svg>
        บันทึกการตั้งค่า
      </button>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-8">
      <!-- Info sidebar -->
      <div class="lg:col-span-1 space-y-4">
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 text-amber-900 text-sm">
          <h3 class="font-bold flex items-center gap-2 text-amber-800 mb-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            ข้อควรระวัง
          </h3>
          <p class="mb-2">ลำดับการอนุมัติจะมีผลทันทีต่อใบลาที่ถูกส่งเข้ามาใหม่ โดยจะเรียงตาม "ขั้นที่ 1, ขั้นที่ 2, ขั้นที่ 3..."</p>
          <p>หากไม่มีการตั้งค่าขั้นตอนอนุมัติ ใบลาจะอยู่ในสถานะที่ไม่มีผู้ใดสามารถอนุมัติได้ ยกเว้นผู้ดูแลระบบสูงสุด (Super Admin)</p>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-3">
          <h4 class="font-bold text-gray-800 text-sm">รายชื่อบทบาทในระบบ</h4>
          <div class="space-y-2">
            <div v-for="role in roles" :key="role.id" class="flex justify-between items-center text-xs py-1.5 border-b border-gray-50 last:border-0">
              <span class="font-semibold text-gray-700">{{ roleLabel(role.name) }}</span>
              <span class="text-gray-400 font-mono text-[10px]">{{ role.name }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Workflow Timeline / Steps Builder -->
      <div class="lg:col-span-2 space-y-4">
        <div v-if="steps.length === 0" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-12 text-center">
          <svg class="mx-auto h-12 w-12 text-gray-300 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
          </svg>
          <h3 class="text-gray-700 font-bold text-base mb-1">ยังไม่มีขั้นตอนการอนุมัติ</h3>
          <p class="text-gray-400 text-sm mb-6">เริ่มสร้างลำดับการอนุมัติแรกของคุณเพื่อขับเคลื่อนกระบวนการยื่นใบลา</p>
          <button
            @click="addStep"
            class="px-4 py-2 bg-[#800000] hover:bg-[#600000] text-white text-sm font-semibold rounded-xl transition-all inline-flex items-center gap-1.5"
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
            class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:border-gray-200 transition-colors relative flex gap-4 items-start"
          >
            <!-- Step index badge -->
            <div class="w-8 h-8 rounded-full bg-[#800000]/10 text-[#800000] font-bold text-sm flex items-center justify-center flex-shrink-0">
              {{ index + 1 }}
            </div>

            <!-- Form fields -->
            <div class="flex-1 grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">ชื่อขั้นตอนการทำงาน</label>
                <input
                  v-model="step.name"
                  type="text"
                  required
                  placeholder="เช่น ตรวจสอบโดยหัวหน้าบุคลากร"
                  class="w-full px-4 py-2 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm transition-colors"
                />
              </div>
              <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">บทบาทที่รับผิดชอบอนุมัติ</label>
                <select
                  v-model="step.role_id"
                  required
                  class="w-full px-4 py-2 rounded-xl border border-gray-200 focus:outline-none focus:border-[#800000] text-sm transition-colors text-gray-700 bg-white"
                >
                  <option value="" disabled>เลือกบทบาท</option>
                  <option v-for="role in approvableRoles" :key="role.id" :value="role.id">
                    {{ roleLabel(role.name) }}
                  </option>
                </select>
              </div>
            </div>

            <!-- Reorder and actions buttons -->
            <div class="flex items-center gap-1 flex-shrink-0 pt-3 md:pt-4">
              <!-- Move Up -->
              <button
                @click="moveUp(index)"
                :disabled="index === 0"
                class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 disabled:opacity-30 transition-colors"
                title="เลื่อนขึ้น"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                </svg>
              </button>
              <!-- Move Down -->
              <button
                @click="moveDown(index)"
                :disabled="index === steps.length - 1"
                class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 disabled:opacity-30 transition-colors"
                title="เลื่อนลง"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
              </button>
              <!-- Delete -->
              <button
                @click="removeStep(index)"
                class="p-1.5 rounded-lg hover:bg-red-50 text-red-400 hover:text-red-600 transition-colors"
                title="ลบขั้นตอนนี้"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
              </button>
            </div>
          </div>

          <!-- Add step button at the bottom -->
          <button
            @click="addStep"
            class="w-full py-3 border-2 border-dashed border-gray-200 hover:border-[#800000]/40 text-gray-400 hover:text-[#800000] font-semibold rounded-2xl text-sm transition-all flex items-center justify-center gap-1.5 bg-gray-50/50 hover:bg-white hover:shadow-sm"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            เพิ่มขั้นตอนอนุมัติ
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAlertStore } from '../../stores/alert'
import api from '../../axios'

const alertStore = useAlertStore()

const loading = ref(true)
const saving = ref(false)
const roles = ref([])
const steps = ref([])

const roleLabel = (name) => {
  const map = {
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

// Exclude standard employees from the workflow approver roles list
const approvableRoles = computed(() => {
  return roles.value.filter(r => r.name !== 'Employee')
})

onMounted(async () => {
  try {
    const [rolesRes, stepsRes] = await Promise.all([
      api.get('/admin/roles'),
      api.get('/admin/workflow-steps')
    ])
    roles.value = rolesRes.data
    steps.value = stepsRes.data.map(s => ({
      id: s.id,
      role_id: s.role_id,
      name: s.name,
      step_order: s.step_order
    }))
  } catch (e) {
    console.error(e)
    alertStore.error('เกิดข้อผิดพลาดในการดึงข้อมูล')
  } finally {
    loading.value = false
  }
})

const addStep = () => {
  steps.value.push({
    role_id: '',
    name: '',
    step_order: steps.value.length + 1
  })
}

const removeStep = (index) => {
  steps.value.splice(index, 1)
}

const moveUp = (index) => {
  if (index === 0) return
  const temp = steps.value[index]
  steps.value[index] = steps.value[index - 1]
  steps.value[index - 1] = temp
}

const moveDown = (index) => {
  if (index === steps.value.length - 1) return
  const temp = steps.value[index]
  steps.value[index] = steps.value[index + 1]
  steps.value[index + 1] = temp
}

const saveWorkflow = async () => {
  // Validate that no step has an empty role or empty name
  for (let i = 0; i < steps.value.length; i++) {
    const s = steps.value[i]
    if (!s.name.trim()) {
      alertStore.error(`กรุณากรอกชื่อขั้นตอนในลำดับที่ ${i + 1}`)
      return
    }
    if (!s.role_id) {
      alertStore.error(`กรุณาเลือกบทบาทผู้มีสิทธิ์อนุมัติในลำดับที่ ${i + 1}`)
      return
    }
  }

  saving.value = true
  try {
    const res = await api.post('/admin/workflow-steps', { steps: steps.value })
    alertStore.success(res.data.message || 'บันทึกลำดับการอนุมัติเรียบร้อยแล้ว')
    steps.value = res.data.steps.map(s => ({
      id: s.id,
      role_id: s.role_id,
      name: s.name,
      step_order: s.step_order
    }))
  } catch (e) {
    console.error(e)
    alertStore.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึกข้อมูล')
  } finally {
    saving.value = false
  }
}
</script>
