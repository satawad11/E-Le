<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-800">ปีงบประมาณ</h1>
      <button @click="openCreate" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md font-medium text-sm flex items-center gap-2 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        เพิ่มปีงบประมาณ
      </button>
    </div>

    <!-- Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b">
            <tr>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">#</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">ปีงบประมาณ (พ.ศ.)</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">ชื่อ</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">วันที่เริ่มต้น</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">วันที่สิ้นสุด</th>
              <th class="px-4 py-3 text-center font-semibold text-gray-600">สถานะ</th>
              <th class="px-4 py-3 text-center font-semibold text-gray-600">การดำเนินการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading" class="border-b">
              <td colspan="7" class="px-4 py-8 text-center text-gray-400">กำลังโหลด...</td>
            </tr>
            <tr v-else-if="fiscalYears.length === 0" class="border-b">
              <td colspan="7" class="px-4 py-8 text-center text-gray-400">ยังไม่มีข้อมูลปีงบประมาณ</td>
            </tr>
            <tr v-for="(fy, idx) in fiscalYears" :key="fy.id" class="border-b hover:bg-gray-50 transition-colors">
              <td class="px-4 py-3 text-gray-500">{{ idx + 1 }}</td>
              <td class="px-4 py-3 font-semibold text-gray-800">{{ fy.year }}</td>
              <td class="px-4 py-3 text-gray-700">{{ fy.name }}</td>
              <td class="px-4 py-3 text-gray-700">{{ formatThaiDate(fy.start_date) }}</td>
              <td class="px-4 py-3 text-gray-700">{{ formatThaiDate(fy.end_date) }}</td>
              <td class="px-4 py-3 text-center">
                <span v-if="fy.is_current" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                  <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.check"/>
                  </svg>
                  ปีปัจจุบัน
                </span>
                <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                  ไม่ใช้งาน
                </span>
              </td>
              <td class="px-4 py-3 text-center">
                <div class="flex items-center justify-center gap-2">
                  <button @click="openEdit(fy)" class="bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1.5 rounded text-xs font-semibold transition-colors">
                    แก้ไข
                  </button>
                  <button @click="confirmDelete(fy)" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1.5 rounded text-xs font-semibold transition-colors">
                    ลบ
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Create/Edit Modal -->
    <transition name="fade">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
          <h3 class="text-lg font-bold text-gray-900 mb-4">{{ isEdit ? 'แก้ไขปีงบประมาณ' : 'เพิ่มปีงบประมาณ' }}</h3>

          <form @submit.prevent="submitForm" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">ปีงบประมาณ (พ.ศ.) *</label>
              <input type="number" v-model="form.year" required min="2500" max="2700" class="block w-full border border-gray-300 rounded-lg shadow-sm py-2 px-3 focus:ring-red-500 focus:border-red-500 sm:text-sm" placeholder="เช่น 2567">
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อ *</label>
              <input type="text" v-model="form.name" required class="block w-full border border-gray-300 rounded-lg shadow-sm py-2 px-3 focus:ring-red-500 focus:border-red-500 sm:text-sm" placeholder="เช่น ปีงบประมาณ 2567">
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">วันที่เริ่มต้น *</label>
                <ThaiDatePicker
                  v-model="form.start_date"
                  required
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">วันที่สิ้นสุด *</label>
                <ThaiDatePicker
                  v-model="form.end_date"
                  :minDate="form.start_date || ''"
                  required
                />
              </div>
            </div>
            <div class="flex items-center gap-2">
              <input type="checkbox" v-model="form.is_current" id="is_current" class="rounded text-red-600 focus:ring-red-500">
              <label for="is_current" class="text-sm text-gray-700 font-medium cursor-pointer">ตั้งเป็นปีงบประมาณปัจจุบัน</label>
            </div>

            <div class="flex gap-3 pt-2">
              <button type="button" @click="showModal = false" class="flex-1 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl text-sm transition-colors">ยกเลิก</button>
              <button type="submit" :disabled="submitting" class="flex-1 py-2.5 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-xl text-sm transition-colors disabled:opacity-50">
                {{ submitting ? 'กำลังบันทึก...' : (isEdit ? 'อัปเดต' : 'บันทึก') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </transition>

    <!-- Delete Confirmation Modal -->
    <transition name="fade">
      <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
          <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
          </div>
          <h3 class="font-bold text-gray-900 text-lg mb-1">ลบปีงบประมาณ?</h3>
          <p class="text-gray-500 text-sm mb-6">ต้องการลบ "{{ deleteTarget?.name }}" ใช่หรือไม่? การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
          <div class="flex gap-3">
            <button @click="showDeleteModal = false" class="flex-1 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl text-sm transition-colors">ยกเลิก</button>
            <button @click="doDelete" class="flex-1 py-2.5 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-xl text-sm transition-colors">ลบ</button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import ThaiDatePicker from '../../components/ThaiDatePicker.vue'
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'
import { ICONS } from '../../utils/icons'

const alertStore = useAlertStore()

const fiscalYears = ref([])
const loading = ref(true)
const showModal = ref(false)
const showDeleteModal = ref(false)
const isEdit = ref(false)
const editId = ref(null)
const deleteTarget = ref(null)
const submitting = ref(false)

const form = ref({
  name: '',
  year: new Date().getFullYear() + 543,
  start_date: '',
  end_date: '',
  is_current: false,
})

const formatThaiDate = (dateString) => {
  return ThaiDateFormatter.toThaiShortText(dateString)
}

// Auto-fill name when year changes
watch(() => form.value.year, (newYear) => {
  if (!isEdit.value && newYear) {
    form.value.name = `ปีงบประมาณ ${newYear}`
  }
})

const fetchFiscalYears = async () => {
  loading.value = true
  try {
    const res = await api.get('/admin/fiscal-years')
    fiscalYears.value = res.data
  } catch (e) {
    alertStore.error('ไม่สามารถโหลดข้อมูลปีงบประมาณได้')
  } finally {
    loading.value = false
  }
}

const openCreate = () => {
  isEdit.value = false
  editId.value = null
  const y = new Date().getFullYear() + 543
  form.value = {
    name: `ปีงบประมาณ ${y}`,
    year: y,
    start_date: '',
    end_date: '',
    is_current: false,
  }
  showModal.value = true
}

const openEdit = (fy) => {
  isEdit.value = true
  editId.value = fy.id
  form.value = {
    name: fy.name,
    year: fy.year,
    start_date: fy.start_date?.substring(0, 10) || '',
    end_date: fy.end_date?.substring(0, 10) || '',
    is_current: fy.is_current,
  }
  showModal.value = true
}

const submitForm = async () => {
  submitting.value = true
  try {
    if (isEdit.value) {
      await api.put(`/admin/fiscal-years/${editId.value}`, form.value)
      alertStore.success('อัปเดตปีงบประมาณเรียบร้อยแล้ว')
    } else {
      await api.post('/admin/fiscal-years', form.value)
      alertStore.success('เพิ่มปีงบประมาณเรียบร้อยแล้ว')
    }
    showModal.value = false
    fetchFiscalYears()
  } catch (e) {
    const errors = e.response?.data?.errors
    if (errors) {
      alertStore.error(Object.values(errors).flat().join(' | '))
    } else {
      const msg = e.response?.data?.message || 'เกิดข้อผิดพลาด'
      alertStore.error(msg)
    }
  } finally {
    submitting.value = false
  }
}

const confirmDelete = (fy) => {
  deleteTarget.value = fy
  showDeleteModal.value = true
}

const doDelete = async () => {
  try {
    await api.delete(`/admin/fiscal-years/${deleteTarget.value.id}`)
    alertStore.success('ลบปีงบประมาณเรียบร้อยแล้ว')
    showDeleteModal.value = false
    fetchFiscalYears()
  } catch (e) {
    alertStore.error(e.response?.data?.message || 'ไม่สามารถลบได้')
  }
}

onMounted(() => {
  fetchFiscalYears()
})
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
