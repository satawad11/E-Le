<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-800">หน่วยงานในผังองค์กร</h1>
      <button @click="openAddModal" class="bg-[#10b981] hover:bg-[#059669] text-white px-4 py-2 rounded-md font-medium text-sm flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        เพิ่มหน่วยงาน
      </button>
    </div>

    <!-- Table Card -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
      <!-- Loading State -->
      <div v-if="loading" class="p-6">
        <LoadingState type="skeleton-table" :rows="4" :cols="5" message="กำลังโหลดผังองค์กร..." />
      </div>

      <!-- Error State -->
      <div v-else-if="loadError" class="p-6">
        <ErrorState :message="loadError" @retry="loadData" />
      </div>

      <div v-else>
        <!-- Empty State -->
        <EmptyState
          v-if="orgUnits.length === 0"
          title="ไม่พบหน่วยงานในผัง"
          description="ยังไม่มีหน่วยงานในผังองค์กร"
          action-text="เพิ่มหน่วยงาน"
          @action="openAddModal"
        />

        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-500 text-white">
              <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">ลำดับ</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">หน่วยงาน</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">สังกัดใต้</th>
                <th scope="col" class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider">จำนวนคน</th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider">เลือกดำเนินการ</th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider">เปิด-ปิดการใช้งาน</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="(dept, index) in orgUnits" :key="dept.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ index + 1 }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {{ dept.name }}
                  <span v-if="dept.is_root" class="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">ยอดผัง</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ parentName(dept) }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500">{{ dept.members }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                  <div class="flex justify-center gap-2">
                    <button @click="openEditModal(dept)" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-xs flex items-center gap-1">
                      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                      แก้ไข
                    </button>
                    <button @click="confirmDelete(dept)" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-xs flex items-center gap-1">
                      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                      ลบข้อมูล
                    </button>
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-center">
                  <button @click="toggleStatus(dept)" :class="dept.is_active ? 'bg-red-600 hover:bg-red-700' : 'bg-gray-400 hover:bg-gray-500'" class="text-white px-3 py-1 rounded text-xs flex items-center justify-center gap-1 mx-auto w-24">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                    {{ dept.is_active ? 'ปิดการใช้งาน' : 'เปิดการใช้งาน' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Add/Edit Modal -->
    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
          <h3 class="text-lg font-medium text-gray-900">{{ isEditing ? 'แก้ไขหน่วยงาน' : 'เพิ่มหน่วยงาน' }}</h3>
          <button @click="closeModal" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
        <form @submit.prevent="saveOrgUnit" class="p-6 space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">ชื่อหน่วยงาน <span class="text-red-500">*</span></label>
            <input type="text" v-model="form.name" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
          </div>
          <!--
            หน่วยงานแม่ — สิ่งที่โครงเดิมทำไม่ได้
            ผังลึกได้หลายชั้น เช่น ฝ่าย > กลุ่มงาน > งาน ซึ่งเป็นโครงจริงของสถานศึกษา
            และเป็นสิ่งที่สายอนุมัติกับขอบเขตการมองเห็นใช้ไต่หาผู้รับผิดชอบ
          -->
          <div v-if="!form.is_root">
            <label class="block text-sm font-medium text-gray-700">สังกัดใต้หน่วยงาน</label>
            <select v-model="form.parent_id" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 sm:text-sm">
              <option v-for="unit in parentOptions" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
            </select>
          </div>
          <!--
            ช่อง "หัวหน้าแผนก" เดิมอยู่ตรงนี้ ตั้งค่าได้แต่ไม่มีตรรกะใดอ่านค่าเลย
            ผู้ดูแลที่ตั้งไว้แล้วรอให้ใบลาวิ่งไปหาเขา จะไม่มีวันเกิดขึ้นและไม่มีอะไรบอก
            ของจริงคือการมอบหมายที่มีวันมีผลใน "ผู้รับผิดชอบหน่วยงาน"
          -->
          <p class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-900">
            กำหนด<strong>หัวหน้าหน่วยงาน</strong>ได้ที่หน้า
            <router-link :to="{ name: 'admin-org-unit-managers' }" class="font-semibold underline underline-offset-2">
              ผู้รับผิดชอบหน่วยงาน
            </router-link>
            — เป็นข้อมูลที่ระบบใช้หาผู้อนุมัติใบลาจริง และเก็บวันที่เริ่มมีผลไว้เป็นประวัติ
          </p>
          <div class="flex items-center mt-4">
            <input type="checkbox" id="is_active" v-model="form.is_active" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
            <label for="is_active" class="ml-2 block text-sm text-gray-900">เปิดใช้งาน</label>
          </div>
          <div class="pt-4 flex gap-3 justify-end">
            <button type="button" @click="closeModal" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm font-medium transition-colors">ยกเลิก</button>
            <button type="submit" :disabled="isSaving" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm font-medium transition-colors disabled:opacity-50">
              {{ isSaving ? 'กำลังบันทึก...' : 'บันทึก' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { getErrorMessage } from '../../utils/errorHandler'
import LoadingState from '../../components/ui/LoadingState.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'

const alertStore = useAlertStore()

const loading = ref(true)
const loadError = ref('')
const orgUnits = ref([])

const isModalOpen = ref(false)
const isEditing = ref(false)
const isSaving = ref(false)

const form = ref({
  id: null,
  name: '',
  parent_id: null,
  is_active: true,
  is_root: false,
})

// ยอดผังเป็นหน่วยงานแม่ของตัวเองไม่ได้ และหน่วยงานย้ายไปอยู่ใต้ตัวเองไม่ได้
const parentOptions = computed(
  () => orgUnits.value.filter(unit => unit.id !== form.value.id),
)

const parentName = (unit) => orgUnits.value.find(u => u.id === unit.parent_id)?.name || '—'

const loadData = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const deptRes = await api.get('/admin/org-units')
    orgUnits.value = deptRes.data
  } catch (error) {
    console.error(error)
    loadError.value = getErrorMessage(error, 'ไม่สามารถโหลดข้อมูลหน่วยงานได้')
  } finally {
    loading.value = false
  }
}

const fetchOrgUnits = async () => {
  try {
    const res = await api.get('/admin/org-units')
    orgUnits.value = res.data
  } catch (error) {
    alertStore.error(getErrorMessage(error, 'ไม่สามารถโหลดข้อมูลหน่วยงานได้'))
  }
}

onMounted(() => {
  loadData()
})

const openAddModal = () => {
  form.value = {
    id: null,
    name: '',
    parent_id: orgUnits.value.find(unit => unit.is_root)?.id ?? null,
    is_active: true,
    is_root: false,
  }
  isEditing.value = false
  isModalOpen.value = true
}

const openEditModal = (dept) => {
  form.value = {
    id: dept.id,
    name: dept.name,
    parent_id: dept.parent_id,
    is_active: dept.is_active,
    is_root: dept.is_root,
  }
  isEditing.value = true
  isModalOpen.value = true
}

const closeModal = () => {
  isModalOpen.value = false
}

const saveOrgUnit = async () => {
  isSaving.value = true
  try {
    if (isEditing.value) {
      await api.put(`/admin/org-units/${form.value.id}`, form.value)
      alertStore.success('แก้ไขข้อมูลหน่วยงานเรียบร้อยแล้ว')
    } else {
      await api.post('/admin/org-units', form.value)
      alertStore.success('เพิ่มหน่วยงานวิชาสำเร็จ')
    }
    closeModal()
    fetchOrgUnits()
  } catch (error) {
    const errors = error.response?.data?.errors
    if (errors) {
      alertStore.error(Object.values(errors).flat().join(' | '))
    } else {
      alertStore.error(error.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึก')
    }
  } finally {
    isSaving.value = false
  }
}

const confirmDelete = async (dept) => {
  if (confirm(`คุณต้องการลบหน่วยงาน "${dept.name}" ใช่หรือไม่?\nการกระทำนี้ไม่สามารถกู้คืนได้`)) {
    try {
      await api.delete(`/admin/org-units/${dept.id}`)
      alertStore.success('ลบหน่วยงานเรียบร้อยแล้ว')
      fetchOrgUnits()
    } catch (error) {
      alertStore.error(error.response?.data?.message || 'ลบหน่วยงานไม่สำเร็จ')
    }
  }
}

const toggleStatus = async (dept) => {
  try {
    const res = await api.put(`/admin/org-units/${dept.id}/toggle-status`)
    dept.is_active = res.data.is_active
    alertStore.success('เปลี่ยนสถานะการใช้งานสำเร็จ')
  } catch {
    alertStore.error('ไม่สามารถเปลี่ยนสถานะได้')
  }
}
</script>
