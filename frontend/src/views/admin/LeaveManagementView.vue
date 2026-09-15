<template>
  <div>
    <div class="mb-8">
      <h1 class="text-2xl font-bold text-gray-900">จัดการข้อมูลการลา</h1>
      <p class="text-gray-500 mt-1">บันทึกวันลาแทนบุคลากร หรือ ดึงข้อมูลการลาไปอัปเดตในระบบลงเวลา</p>
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
          บันทึกวันลาแทนบุคลากร
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
          <label class="block text-sm font-medium text-gray-700 mb-1">ปี (พ.ศ.)</label>
          <select v-model="syncForm.year" class="w-full bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-[#800000] focus:border-[#800000] p-2.5">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y + 543 }}</option>
          </select>
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
      <h2 class="text-lg font-bold text-gray-800 mb-4">บันทึกวันลาให้บุคลากร</h2>
      <p class="text-sm text-gray-600 mb-6">
        เมื่อบันทึกผ่านหน้านี้ สถานะของใบลาจะถูกตั้งเป็น <strong>"อนุมัติแล้ว (Approved)"</strong> ทันที โดยไม่ต้องรอสายบังคับบัญชาอนุมัติ เหมาะสำหรับกรณีที่บุคลากรไม่สามารถบันทึกได้ด้วยตนเอง
      </p>

      <form @submit.prevent="handleManualSubmit" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อบุคลากร *</label>
            <select v-model="manualForm.user_id" required :disabled="referenceLoading" class="w-full bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-[#800000] focus:border-[#800000] p-2.5 disabled:opacity-60">
              <option value="" disabled>{{ referenceLoading ? 'กำลังโหลดรายชื่อบุคลากร…' : 'เลือกบุคลากร' }}</option>
              <option v-for="user in users" :key="user.id" :value="user.id">
                {{ user.name }} {{ user.department ? `(${user.department.name})` : '' }}
              </option>
            </select>
            <p v-if="!referenceLoading && users.length === 0" class="text-xs text-red-600 mt-1">
              ยังไม่มีรายชื่อบุคลากรให้เลือก — โหลดข้อมูลไม่สำเร็จหรือยังไม่มีบุคลากรในระบบ
            </p>
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
import { ref, onMounted } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import ThaiDatePicker from '../../components/ThaiDatePicker.vue'
import { ICONS } from '../../utils/icons'
import { fetchAllPersonnel } from '../../utils/personnelDirectory'
import { getErrorMessage } from '../../utils/errorHandler'

const alertStore = useAlertStore()

const activeTab = ref('sync')
const months = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม']

/*
 * ช่องปีแสดงเป็น พ.ศ. เหมือนทุกหน้าจอในระบบ แต่ส่ง ค.ศ. ให้ API เหมือนเดิม
 * เดิมเป็นช่องพิมพ์ตัวเลข ค.ศ. เปล่า ๆ ซึ่งผู้ใช้ราชการมักกรอกเป็น พ.ศ. แล้วได้ผลลัพธ์ว่าง
 */
const currentYear = new Date().getFullYear()
const yearOptions = Array.from({ length: 6 }, (_, i) => currentYear - 4 + i)

const syncForm = ref({
  month: new Date().getMonth() + 1,
  year: currentYear
})
const syncLoading = ref(false)
const syncResult = ref(null)

const manualForm = ref({
  user_id: '',
  leave_type_id: '',
  start_date: '',
  end_date: '',
  reason: ''
})
const manualLoading = ref(false)

const users = ref([])
const leaveTypes = ref([])
const referenceLoading = ref(false)

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
    alertStore.success('บันทึกวันลาให้บุคลากรสำเร็จ!')
    // Reset form
    manualForm.value = {
      user_id: '',
      leave_type_id: '',
      start_date: '',
      end_date: '',
      reason: ''
    }
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

/*
 * ช่องเลือกชื่อไม่มีปุ่มเลื่อนหน้า จึงต้องได้รายชื่อครบทุกคน
 * `/admin/users` แบ่งหน้าละ 25 คน — เดิมดึงหน้าเดียว ทำให้บันทึกวันลาแทนบุคลากร
 * ที่ชื่อเรียงหลังคนที่ 25 ไม่ได้เลย และเมื่อโหลดล้มเหลวก็เงียบไปเฉย ๆ
 */
onMounted(async () => {
  referenceLoading.value = true
  try {
    const [personnel, typesRes] = await Promise.all([
      fetchAllPersonnel(),
      api.get('/leaves/types')
    ])
    users.value = personnel
    leaveTypes.value = typesRes.data
  } catch (error) {
    alertStore.error(getErrorMessage(error, 'โหลดรายชื่อบุคลากรและประเภทการลาไม่สำเร็จ'))
  } finally {
    referenceLoading.value = false
  }
})
</script>
