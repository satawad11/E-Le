<template>
  <div>
    <div class="mb-8">
      <h1 class="text-2xl font-bold text-gray-900">จัดการข้อมูลการลา (งานบุคลากร)</h1>
      <p class="text-gray-500 mt-1">บันทึกวันลาแทนพนักงาน หรือ ดึงข้อมูลการลาไปอัปเดตในระบบลงเวลา</p>
    </div>

    <!-- Tabs -->
    <div class="flex border-b border-gray-200 mb-6">
      <button 
        @click="activeTab = 'sync'"
        class="py-3 px-6 font-semibold text-sm border-b-2 transition-colors duration-200"
        :class="activeTab === 'sync' ? 'border-[#800000] text-[#800000]' : 'border-transparent text-gray-500 hover:text-gray-700'"
      >
        <span class="inline-flex items-center gap-2">
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.refresh"/>
          </svg>
          ดึงข้อมูลเข้าสู่ระบบลงเวลา
        </span>
      </button>
      <button 
        @click="activeTab = 'manual'"
        class="py-3 px-6 font-semibold text-sm border-b-2 transition-colors duration-200"
        :class="activeTab === 'manual' ? 'border-[#800000] text-[#800000]' : 'border-transparent text-gray-500 hover:text-gray-700'"
      >
        <span class="inline-flex items-center gap-2">
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.pencil"/>
          </svg>
          บันทึกวันลาแทนพนักงาน
        </span>
      </button>
    </div>

    <!-- Tab Content: Sync -->
    <div v-if="activeTab === 'sync'" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 max-w-2xl">
      <h2 class="text-lg font-bold text-gray-800 mb-4">ซิงค์ข้อมูลวันลา (ที่อนุมัติแล้ว) กับระบบลงเวลา</h2>
      <p class="text-sm text-gray-600 mb-6">
        ระบบจะค้นหาใบลาของเดือนที่คุณเลือก หากใบลาผ่านการอนุมัติแล้ว ระบบจะนำไปบันทึกเป็น "ลางาน" ในหน้าสรุปผลการลงเวลาปฏิบัติงานโดยอัตโนมัติ
      </p>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">เดือน</label>
          <select v-model="syncForm.month" class="w-full bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-[#800000] focus:border-[#800000] p-2.5">
            <option v-for="(m, i) in months" :key="i" :value="i+1">{{ m }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">ปี (ค.ศ.)</label>
          <input type="number" v-model="syncForm.year" class="w-full bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-[#800000] focus:border-[#800000] p-2.5" />
        </div>
      </div>

      <button @click="handleSync" :disabled="syncLoading" class="w-full md:w-auto px-6 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-medium rounded-lg transition-colors flex items-center justify-center gap-2">
        <svg class="w-4 h-4 flex-shrink-0" :class="{ 'animate-spin': syncLoading }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.refresh"/>
        </svg>
        {{ syncLoading ? 'กำลังดึงข้อมูล...' : 'เริ่มดึงข้อมูล (Sync)' }}
      </button>

      <div v-if="syncResult" class="mt-4 p-4 rounded-lg text-sm" :class="syncResult.includes('สำเร็จ') ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'">
        {{ syncResult }}
      </div>
    </div>

    <!-- Tab Content: Manual -->
    <div v-if="activeTab === 'manual'" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 max-w-3xl">
      <h2 class="text-lg font-bold text-gray-800 mb-4">บันทึกวันลาให้พนักงาน</h2>
      <p class="text-sm text-gray-600 mb-6">
        เมื่อบันทึกผ่านหน้านี้ สถานะของใบลาจะถูกตั้งเป็น <strong>"อนุมัติแล้ว"</strong> ทันที โดยไม่ต้องรอสายบังคับบัญชาอนุมัติ เหมาะสำหรับกรณีที่พนักงานไม่สามารถบันทึกได้ด้วยตนเอง
        — ให้เลือกผู้ลงนามจริงของแต่ละขั้นตามเอกสารกระดาษ แบบฟอร์มจะพิมพ์ชื่อผู้ลงนามเหล่านั้น ไม่ใช่ชื่อผู้บันทึก
      </p>

      <form @submit.prevent="handleManualSubmit" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อพนักงาน *</label>
            <select v-model="manualForm.user_id" required class="w-full bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-[#800000] focus:border-[#800000] p-2.5">
              <option value="" disabled>เลือกพนักงาน</option>
              <option v-for="user in users" :key="user.id" :value="user.id">
                {{ user.name }} {{ user.department ? `(${user.department.name})` : '' }}
              </option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">ประเภทการลา *</label>
            <select v-model="manualForm.leave_type_id" required class="w-full bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-[#800000] focus:border-[#800000] p-2.5">
              <option value="" disabled>เลือกประเภทการลา</option>
              <option v-for="t in leaveTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">วันเริ่มต้นที่ลา *</label>
            <ThaiDatePicker v-model="manualForm.start_date" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">วันสิ้นสุดการลา *</label>
            <ThaiDatePicker v-model="manualForm.end_date" :min-date="manualForm.start_date" />
          </div>
        </div>

        <div>
          <label for="manual-leave-period" class="block text-sm font-medium text-gray-700 mb-1">ช่วงเวลา</label>
          <select id="manual-leave-period" v-model="manualForm.leave_period" class="w-full bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-[#800000] focus:border-[#800000] p-2.5">
            <option value="full_day">เต็มวัน</option>
            <option value="morning">ครึ่งวันเช้า</option>
            <option value="afternoon">ครึ่งวันบ่าย</option>
          </select>
          <p class="mt-1 text-xs text-gray-500">ลาครึ่งวันต้องเลือกวันเริ่มต้นและสิ้นสุดเป็นวันเดียวกัน · บันทึกย้อนหลังได้ ระบบนับเป็นวันที่ใช้ไปและอนุมัติทุกขั้นให้ทันที</p>
        </div>

        <!-- ผู้ลงนามจริงแต่ละขั้น (DELEGATION-01) -->
        <div v-if="manualForm.user_id" class="border border-amber-200 bg-amber-50 rounded-xl p-4">
          <p class="text-sm font-semibold text-amber-900 mb-1">ผู้ลงนามตามเอกสาร *</p>
          <p class="text-xs text-amber-800 mb-3">เลือกผู้มีอำนาจ (หรือผู้ปฏิบัติหน้าที่แทน) ที่ลงนามในใบลากระดาษของแต่ละขั้น</p>
          <p v-if="signerSteps.loading" class="text-xs text-gray-500">กำลังโหลดผู้มีอำนาจ...</p>
          <p v-else-if="!signerSteps.items.length" class="text-xs text-red-600">ยังไม่ได้ตั้งค่าลำดับการอนุมัติ</p>
          <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <label v-for="step in signerSteps.items" :key="step.step_order" class="block text-xs text-gray-700">
              {{ step.step_order }}. {{ step.name }}
              <select v-model="manualForm.signers[step.step_order]" required class="mt-1 w-full bg-white border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-[#800000] focus:border-[#800000] p-2">
                <option :value="null" disabled>— เลือกผู้ลงนาม —</option>
                <option v-for="c in step.candidates" :key="c.id" :value="c.id">
                  {{ c.name }}{{ c.acting_line ? ` (${c.acting_line})` : (c.position ? ` (${c.position})` : '') }}
                </option>
              </select>
              <span v-if="!step.candidates.length" class="text-red-600">ไม่พบผู้มีอำนาจของขั้นนี้</span>
            </label>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">เหตุผลการลา *</label>
          <textarea v-model="manualForm.reason" required rows="3" class="w-full bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-[#800000] focus:border-[#800000] p-2.5" placeholder="กรุณาระบุเหตุผลการลา..."></textarea>
        </div>

        <div class="pt-4 border-t border-gray-100 flex justify-end">
          <button type="submit" :disabled="manualLoading" class="px-6 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-medium rounded-lg transition-colors flex items-center gap-2">
            <svg class="w-4 h-4 flex-shrink-0" :class="{ 'animate-spin': manualLoading }" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="manualLoading ? ICONS.refresh : ICONS.save"/>
            </svg>
            {{ manualLoading ? 'กำลังบันทึก...' : 'บันทึกวันลา' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, watch, onMounted } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import ThaiDatePicker from '../../components/ThaiDatePicker.vue'
import { ICONS } from '../../utils/icons'

const alertStore = useAlertStore()

const activeTab = ref('sync')
const months = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม']

const syncForm = ref({
  month: new Date().getMonth() + 1,
  year: new Date().getFullYear()
})
const syncLoading = ref(false)
const syncResult = ref(null)

const emptyManualForm = () => ({
  user_id: '',
  leave_type_id: '',
  start_date: '',
  end_date: '',
  leave_period: 'full_day',
  reason: '',
  signers: {}
})
const manualForm = ref(emptyManualForm())
const manualLoading = ref(false)

// ผู้ลงนามที่เลือกได้ในแต่ละขั้น — ขึ้นกับสายอนุมัติของบุคลากรที่เลือก
const signerSteps = reactive({ loading: false, items: [] })
watch(() => manualForm.value.user_id, async (userId) => {
  signerSteps.items = []
  manualForm.value.signers = {}
  if (!userId) return
  signerSteps.loading = true
  try {
    const res = await api.get('/admin/leaves/record-signers', { params: { user_id: userId } })
    signerSteps.items = res.data || []
    const signers = {}
    for (const step of signerSteps.items) {
      signers[step.step_order] = step.candidates.length === 1 ? step.candidates[0].id : null
    }
    manualForm.value.signers = signers
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'โหลดรายชื่อผู้มีอำนาจไม่สำเร็จ')
  } finally {
    signerSteps.loading = false
  }
})

const users = ref([])
const leaveTypes = ref([])

const handleSync = async () => {
  syncLoading.value = true
  syncResult.value = null
  try {
    const res = await api.post('/admin/attendances/sync-leaves', syncForm.value)
    syncResult.value = res.data.message
  } catch (error) {
    syncResult.value = error.response?.data?.message || 'เกิดข้อผิดพลาดในการดึงข้อมูล'
  } finally {
    syncLoading.value = false
  }
}

const handleManualSubmit = async () => {
  manualLoading.value = true
  try {
    await api.post('/admin/leaves', manualForm.value)
    alertStore.success('บันทึกวันลาให้พนักงานสำเร็จ!')
    // Reset form
    manualForm.value = emptyManualForm()
  } catch (error) {
    const errors = error.response?.data?.errors
    if (errors) {
      alertStore.error(Object.values(errors).flat().join(' | '))
    } else {
      alertStore.error(error.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึกข้อมูล')
    }
  } finally {
    manualLoading.value = false
  }
}

onMounted(async () => {
  try {
    const [usersRes, typesRes] = await Promise.all([
      api.get('/admin/users'),
      api.get('/leaves/types')
    ])
    // The admin users endpoint returns a paginated list or data array. Let's assume it returns { data: [...] } or [...] 
    users.value = usersRes.data.data || usersRes.data
    leaveTypes.value = typesRes.data
  } catch (error) {
    console.error('Failed to load initial data', error)
  }
})
</script>
