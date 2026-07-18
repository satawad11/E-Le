<template>
  <div>
    <!-- Header -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">จัดการประเภทการลา</h1>
        <p class="text-gray-500 mt-1 text-sm">เพิ่ม แก้ไข หรือลบประเภทการลาในระบบ</p>
      </div>
      <button @click="openCreate"
        class="inline-flex items-center gap-2 px-4 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        เพิ่มประเภทการลา
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex justify-center py-16">
      <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <!-- Table -->
    <div v-else class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase">ชื่อประเภทการลา</th>
              <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase">รหัสเริ่มต้น</th>
              <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase">โควต้าสูงสุด (วัน)</th>
              <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase">ทบวันลาข้ามปี</th>
              <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase">ต้องมีเอกสาร</th>
              <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase">คำอธิบาย</th>
              <th class="px-6 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-if="leaveTypes.length === 0">
              <td colspan="5" class="text-center text-gray-400 py-8">ไม่มีข้อมูลประเภทการลา</td>
            </tr>
            <tr v-for="lt in leaveTypes" :key="lt.id" class="hover:bg-gray-50 transition-colors">
              <td class="px-6 py-4 font-semibold text-gray-900">{{ lt.name }}</td>
              <td class="px-6 py-4 text-gray-700">{{ lt.code_prefix || '—' }}</td>
              <td class="px-6 py-4 text-gray-700">{{ lt.max_days ?? '—' }} วัน</td>
              <td class="px-6 py-4">
                <span v-if="lt.is_carry_forward" class="text-xs font-semibold text-green-700 bg-green-100 px-2 py-0.5 rounded-full">
                  ✓ ได้ (สูงสุด {{ lt.max_carry_forward ?? 'ไม่จำกัด' }} วัน)
                </span>
                <span v-else class="text-xs text-gray-500">—</span>
              </td>
              <td class="px-6 py-4">
                <span :class="[
                  'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold',
                  lt.requires_doc ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-500'
                ]">
                  {{ lt.requires_doc ? '✓ ต้องมีเอกสาร' : 'ไม่จำเป็น' }}
                </span>
              </td>
              <td class="px-6 py-4 text-gray-500 max-w-xs truncate">{{ lt.description || '—' }}</td>
              <td class="px-6 py-4">
                <div class="flex items-center gap-2 justify-end">
                  <button @click="openEdit(lt)"
                    class="p-1.5 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                  </button>
                  <button @click="confirmDelete(lt)"
                    class="p-1.5 text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
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
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md">
          <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-bold text-gray-900 text-lg">
              {{ editMode ? 'แก้ไขประเภทการลา' : 'เพิ่มประเภทการลา' }}
            </h3>
            <button @click="showModal = false" class="p-1.5 text-gray-400 hover:text-gray-600 rounded-lg">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
          <form @submit.prevent="submitForm" class="p-6 space-y-4">
            <!-- Error -->
            <div v-if="formError" class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3">
              {{ formError }}
            </div>

            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-1.5">ชื่อประเภทการลา <span class="text-red-500">*</span></label>
              <input v-model="form.name" type="text" required placeholder="เช่น ลาป่วย"
                class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#800000]/30 focus:border-[#800000]"/>
            </div>

            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-1.5">รหัสเริ่มต้นใบลา</label>
              <input v-model="form.code_prefix" type="text" placeholder="เช่น L01" maxlength="10"
                class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#800000]/30 focus:border-[#800000]"/>
            </div>

            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-1.5">โควต้าสูงสุด (วันต่อปี) <span class="text-red-500">*</span></label>
              <input v-model.number="form.max_days" type="number" min="1" max="365" required placeholder="30"
                class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#800000]/30 focus:border-[#800000]"/>
            </div>

            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-1.5">คำอธิบาย</label>
              <textarea v-model="form.description" rows="2" placeholder="รายละเอียดเพิ่มเติม..."
                class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#800000]/30 focus:border-[#800000] resize-none"/>
            </div>

            <div class="flex items-center justify-between border-t border-gray-100 pt-3">
              <div class="flex items-center gap-3">
                <button type="button" @click="form.is_carry_forward = !form.is_carry_forward"
                  :class="[
                    'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                    form.is_carry_forward ? 'bg-[#800000]' : 'bg-gray-200'
                  ]">
                  <span :class="[
                    'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                    form.is_carry_forward ? 'translate-x-6' : 'translate-x-1'
                  ]"/>
                </button>
                <label class="text-sm font-medium text-gray-700">อนุญาตให้ทบวันลาข้ามปีได้</label>
              </div>
            </div>

            <div v-if="form.is_carry_forward" class="pl-14">
              <label class="block text-xs font-semibold text-gray-700 mb-1">จำนวนที่ทบได้สูงสุด (วัน) <span class="text-gray-400 font-normal">(เว้นว่างหากไม่จำกัด)</span></label>
              <input v-model.number="form.max_carry_forward" type="number" min="1" max="365" placeholder="ไม่จำกัด"
                class="w-full border border-gray-200 rounded-xl px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#800000]/30 focus:border-[#800000]"/>
            </div>

            <div class="flex items-center gap-3 border-t border-gray-100 pt-3">
              <button type="button" @click="form.is_prorated = !form.is_prorated"
                :class="[
                  'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                  form.is_prorated ? 'bg-[#800000]' : 'bg-gray-200'
                ]">
                <span :class="[
                  'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                  form.is_prorated ? 'translate-x-6' : 'translate-x-1'
                ]"/>
              </button>
              <label class="text-sm font-medium text-gray-700 flex flex-col">
                <span>คำนวณวันลาตามสัดส่วนอายุงาน (Proration)</span>
                <span class="text-xs text-gray-500 font-normal">เช่น ได้ 1 วันต่อเดือนสำหรับอายุงานไม่ถึง 1 ปี</span>
              </label>
            </div>

            <div class="flex items-center gap-3 border-t border-gray-100 pt-3">
              <button type="button" @click="form.requires_doc = !form.requires_doc"
                :class="[
                  'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                  form.requires_doc ? 'bg-[#800000]' : 'bg-gray-200'
                ]">
                <span :class="[
                  'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                  form.requires_doc ? 'translate-x-6' : 'translate-x-1'
                ]"/>
              </button>
              <label class="text-sm font-medium text-gray-700">ต้องมีเอกสารแนบ</label>
            </div>

            <div class="flex gap-3 pt-2">
              <button type="button" @click="showModal = false"
                class="flex-1 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl text-sm transition-colors">
                ยกเลิก
              </button>
              <button type="submit" :disabled="saving"
                class="flex-1 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm transition-colors disabled:opacity-60">
                {{ saving ? 'กำลังบันทึก...' : (editMode ? 'บันทึกการเปลี่ยนแปลง' : 'เพิ่มประเภทการลา') }}
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
          <div class="w-14 h-14 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-7 h-7 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
          </div>
          <h3 class="font-bold text-gray-900 text-lg mb-1">ลบประเภทการลา?</h3>
          <p class="text-gray-500 text-sm mb-6">
            ต้องการลบ "<strong>{{ deleteTarget?.name }}</strong>" ใช่หรือไม่?
          </p>
          <div class="flex gap-3">
            <button @click="showDeleteModal = false"
              class="flex-1 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl text-sm transition-colors">
              ยกเลิก
            </button>
            <button @click="doDelete" :disabled="deleting"
              class="flex-1 py-2.5 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-xl text-sm transition-colors disabled:opacity-60">
              {{ deleting ? 'กำลังลบ...' : 'ลบ' }}
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../axios'

const loading = ref(true)
const saving  = ref(false)
const deleting = ref(false)
const leaveTypes = ref([])
const showModal = ref(false)
const showDeleteModal = ref(false)
const editMode = ref(false)
const editId   = ref(null)
const deleteTarget = ref(null)
const formError = ref('')

const form = ref({
  name: '',
  code_prefix: '',
  max_days: 30,
  description: '',
  requires_doc: false,
  is_carry_forward: false,
  max_carry_forward: null,
  is_prorated: false,
})

const resetForm = () => {
  form.value = { name: '', code_prefix: '', max_days: 30, description: '', requires_doc: false, is_carry_forward: false, max_carry_forward: null, is_prorated: false }
  formError.value = ''
}

const fetchTypes = async () => {
  const res = await api.get('/admin/leave-types')
  leaveTypes.value = res.data
}

const openCreate = () => {
  editMode.value = false
  editId.value = null
  resetForm()
  showModal.value = true
}

const openEdit = (lt) => {
  editMode.value = true
  editId.value = lt.id
  form.value = {
    name: lt.name,
    code_prefix: lt.code_prefix || '',
    max_days: lt.max_days ?? 30,
    description: lt.description ?? '',
    requires_doc: !!lt.requires_doc,
    is_carry_forward: !!lt.is_carry_forward,
    max_carry_forward: lt.max_carry_forward ?? null,
    is_prorated: !!lt.is_prorated,
  }
  formError.value = ''
  showModal.value = true
}

const submitForm = async () => {
  saving.value = true
  formError.value = ''
  try {
    if (editMode.value) {
      await api.put(`/admin/leave-types/${editId.value}`, form.value)
    } else {
      await api.post('/admin/leave-types', form.value)
    }
    await fetchTypes()
    showModal.value = false
  } catch (e) {
    formError.value = e?.response?.data?.message
      || Object.values(e?.response?.data?.errors || {})[0]?.[0]
      || 'เกิดข้อผิดพลาด กรุณาลองใหม่'
  } finally {
    saving.value = false
  }
}

const confirmDelete = (lt) => {
  deleteTarget.value = lt
  showDeleteModal.value = true
}

const doDelete = async () => {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await api.delete(`/admin/leave-types/${deleteTarget.value.id}`)
    await fetchTypes()
    showDeleteModal.value = false
    deleteTarget.value = null
  } catch (e) {
    alert(e?.response?.data?.message || 'ลบไม่สำเร็จ')
  } finally {
    deleting.value = false
  }
}

onMounted(async () => {
  try {
    await fetchTypes()
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
