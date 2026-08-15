<template>
  <div class="max-w-7xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
      <h1 class="text-2xl font-bold text-gray-800">จัดการหมวดหมู่เอกสาร</h1>
      <button @click="openModal()" class="bg-[#800000] text-white px-4 py-2 rounded-lg shadow hover:bg-red-800 transition text-sm font-semibold">
        + เพิ่มหมวดหมู่ใหม่
      </button>
    </div>
    
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-gray-50 text-gray-600 text-sm">
              <th class="p-4 border-b font-medium w-16 text-center">ID</th>
              <th class="p-4 border-b font-medium">ชื่อหมวดหมู่</th>
              <th class="p-4 border-b font-medium">รหัส (นำหน้าเลข)</th>
              <th class="p-4 border-b font-medium text-center">สี</th>
              <th class="p-4 border-b font-medium text-center">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="store.loading" class="text-center">
              <td colspan="5" class="p-4 text-gray-500">กำลังโหลด...</td>
            </tr>
            <tr v-else-if="store.types.length === 0" class="text-center">
              <td colspan="5" class="p-4 text-gray-500">ยังไม่มีข้อมูลหมวดหมู่</td>
            </tr>
            <tr v-for="type in store.types" :key="type.id" class="hover:bg-gray-50" v-else>
              <td class="p-4 border-b text-center">{{ type.id }}</td>
              <td class="p-4 border-b">{{ type.name }}</td>
              <td class="p-4 border-b text-gray-600">{{ type.code || '-' }}</td>
              <td class="p-4 border-b text-center">
                <span v-if="type.color" class="inline-block w-6 h-6 rounded-full border shadow-sm" :style="{ backgroundColor: type.color }"></span>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td class="p-4 border-b text-center">
                <div class="flex justify-center space-x-3">
                  <button @click="openModal(type)" class="text-blue-500 hover:text-blue-700" title="แก้ไข">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                  </button>
                  <button @click="confirmDelete(type.id)" class="text-red-500 hover:text-red-700" title="ลบ">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Form -->
    <div v-if="showModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4 overflow-y-auto">
      <div class="bg-white rounded-xl shadow-lg w-full max-w-md p-6 max-h-[90vh] overflow-y-auto">
        <h3 class="text-xl font-bold mb-4">{{ isEdit ? 'แก้ไขหมวดหมู่' : 'เพิ่มหมวดหมู่ใหม่' }}</h3>
        
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อหมวดหมู่ *</label>
          <input type="text" v-model="form.name" class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-[#800000] outline-none" required />
        </div>
        
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-1">รหัส (ตัวย่อนำหน้าเลขเอกสาร)</label>
          <input type="text" v-model="form.code" placeholder="เช่น ที่ ศธ 04001, หรือ หนังสือภายใน" class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-[#800000] outline-none" />
          <p class="text-xs text-gray-500 mt-1">รหัสนี้จะถูกนำไปใช้นำหน้าเลขเอกสารอัตโนมัติ (เว้นว่างไว้ได้)</p>
        </div>

        <div class="mb-6">
          <label class="block text-sm font-medium text-gray-700 mb-1">สีสัญลักษณ์ (Color)</label>
          <div class="flex items-center gap-3">
            <input type="color" v-model="form.color" class="w-10 h-10 p-0 border-0 rounded cursor-pointer" />
            <input type="text" v-model="form.color" placeholder="#000000" class="flex-1 px-3 py-2 border rounded focus:ring-2 focus:ring-[#800000] outline-none" />
          </div>
        </div>

        <div class="flex justify-end space-x-2">
          <button @click="showModal = false" class="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">ยกเลิก</button>
          <button @click="saveType" :disabled="loading" class="px-4 py-2 bg-[#800000] text-white rounded hover:bg-red-800">
            {{ loading ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useSarabanDocumentTypeStore } from '../../stores/useSarabanDocumentTypeStore'
import Swal from 'sweetalert2'

const store = useSarabanDocumentTypeStore()

const showModal = ref(false)
const isEdit = ref(false)
const loading = ref(false)
const form = ref({ id: null, name: '', code: '' })

onMounted(() => {
  store.fetchTypes()
})

const openModal = (type = null) => {
  if (type) {
    isEdit.value = true
    form.value = { ...type }
  } else {
    isEdit.value = false
    form.value = { id: null, name: '', code: '', color: '#1d4ed8' }
  }
  showModal.value = true
}

const saveType = async () => {
  if (!form.value.name) return Swal.fire('แจ้งเตือน', 'กรุณากรอกชื่อหมวดหมู่', 'warning')
  
  loading.value = true
  try {
    if (isEdit.value) {
      await store.updateType(form.value.id, form.value)
    } else {
      await store.createType(form.value)
    }
    showModal.value = false
    Swal.fire('สำเร็จ', 'บันทึกข้อมูลเรียบร้อย', 'success')
  } catch (err) {
    Swal.fire('ผิดพลาด', 'ไม่สามารถบันทึกข้อมูลได้: ' + (err.response?.data?.message || err.message), 'error')
  } finally {
    loading.value = false
  }
}

const confirmDelete = (id) => {
  Swal.fire({
    title: 'ยืนยันการลบ?',
    text: "คุณต้องการลบหมวดหมู่นี้ใช่หรือไม่? (ไม่สามารถกู้คืนได้)",
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d33',
    cancelButtonColor: '#9ca3af',
    confirmButtonText: 'ลบข้อมูล',
    cancelButtonText: 'ยกเลิก'
  }).then(async (result) => {
    if (result.isConfirmed) {
      try {
        await store.deleteType(id)
        Swal.fire('ลบสำเร็จ!', 'หมวดหมู่นี้ถูกลบแล้ว', 'success')
      } catch (err) {
        Swal.fire('ผิดพลาด', 'ไม่สามารถลบได้: ' + err.message, 'error')
      }
    }
  })
}
</script>
