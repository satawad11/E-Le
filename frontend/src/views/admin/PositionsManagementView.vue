<template>
  <div class="space-y-6">
    <div class="flex flex-wrap justify-between items-center gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">ทะเบียนตำแหน่ง</h1>
        <p class="text-sm text-gray-500 mt-1">ตำแหน่งและกลุ่มบุคลากรใช้แสดงผลและแยกรายงานเท่านั้น ไม่ได้ให้สิทธิ์การใช้งานระบบ (สิทธิ์มาจากบทบาทและหน่วยงาน)</p>
      </div>
      <div class="flex gap-2">
        <button type="button" class="border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2 rounded-md font-medium text-sm" @click="openGroupModal()">
          เพิ่มกลุ่มบุคลากร
        </button>
        <button type="button" class="bg-[#10b981] hover:bg-[#059669] text-white px-4 py-2 rounded-md font-medium text-sm" :disabled="activeGroups.length === 0" @click="openPositionModal()">
          เพิ่มตำแหน่ง
        </button>
      </div>
    </div>

    <div v-if="loading" class="bg-white shadow rounded-lg p-6">
      <LoadingState type="skeleton-table" :rows="4" :cols="4" message="กำลังโหลดทะเบียนตำแหน่ง..." />
    </div>

    <div v-else-if="loadError" class="bg-white shadow rounded-lg p-6">
      <ErrorState :message="loadError" @retry="loadData" />
    </div>

    <EmptyState
      v-else-if="groups.length === 0"
      title="ยังไม่มีกลุ่มบุคลากร"
      description="เพิ่มกลุ่ม เช่น ผู้บริหาร ครู เจ้าหน้าที่ ก่อนเพิ่มตำแหน่ง"
      action-text="เพิ่มกลุ่มบุคลากร"
      @action="openGroupModal()"
    />

    <section v-for="group in groups" v-else :key="group.uuid" class="bg-white shadow rounded-lg overflow-hidden">
      <header class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 bg-gray-50 border-b border-gray-200">
        <div class="flex items-center gap-2">
          <h2 class="font-semibold text-gray-800">{{ group.name }}</h2>
          <span class="text-xs text-gray-400">{{ group.code }}</span>
          <span v-if="!group.is_active" class="text-xs rounded-full bg-gray-200 text-gray-600 px-2 py-0.5">ปิดใช้งาน</span>
        </div>
        <button type="button" class="text-sm text-blue-600 hover:underline" @click="openGroupModal(group)">แก้ไขกลุ่ม</button>
      </header>

      <p v-if="group.positions.length === 0" class="px-4 py-6 text-sm text-gray-500">ยังไม่มีตำแหน่งในกลุ่มนี้</p>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-100 text-gray-600">
            <tr>
              <th scope="col" class="px-4 py-2 text-left font-medium">ตำแหน่ง</th>
              <th scope="col" class="px-4 py-2 text-left font-medium">รหัส</th>
              <th scope="col" class="px-4 py-2 text-center font-medium">ลำดับ</th>
              <th scope="col" class="px-4 py-2 text-center font-medium">สถานะ</th>
              <th scope="col" class="px-4 py-2 text-center font-medium">จัดการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="position in group.positions" :key="position.uuid" :class="position.is_active ? '' : 'text-gray-400'">
              <td class="px-4 py-2">{{ position.name }}</td>
              <td class="px-4 py-2 font-mono text-xs">{{ position.code }}</td>
              <td class="px-4 py-2 text-center">{{ position.sort_order }}</td>
              <td class="px-4 py-2 text-center">{{ position.is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}</td>
              <td class="px-4 py-2 text-center">
                <button type="button" class="text-blue-600 hover:underline" @click="openPositionModal(position, group)">แก้ไข</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <AppModal v-model="groupModal.show" :title="groupModal.uuid ? 'แก้ไขกลุ่มบุคลากร' : 'เพิ่มกลุ่มบุคลากร'">
      <form id="group-form" class="space-y-4" @submit.prevent="saveGroup">
        <label class="block text-sm text-gray-700">
          ชื่อกลุ่ม
          <input v-model="groupModal.name" required maxlength="255" type="text" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded text-sm">
        </label>
        <label class="block text-sm text-gray-700">
          รหัส (อักษรอังกฤษตัวใหญ่ ตัวเลข และ _)
          <input v-model="groupModal.code" required maxlength="50" pattern="[A-Z0-9_]+" type="text" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded text-sm font-mono">
        </label>
        <label class="block text-sm text-gray-700">
          ลำดับการแสดง
          <input v-model.number="groupModal.sort_order" min="0" type="number" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded text-sm">
        </label>
        <label v-if="groupModal.uuid" class="flex items-center gap-2 text-sm text-gray-700">
          <input v-model="groupModal.is_active" type="checkbox">
          ใช้งาน
        </label>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2">
          <button type="button" class="px-4 py-2 rounded border border-gray-300 text-sm" @click="groupModal.show = false">ยกเลิก</button>
          <button type="submit" form="group-form" :disabled="saving" class="px-4 py-2 rounded bg-[#800000] hover:bg-[#660000] text-white text-sm disabled:opacity-50">บันทึก</button>
        </div>
      </template>
    </AppModal>

    <AppModal v-model="positionModal.show" :title="positionModal.uuid ? 'แก้ไขตำแหน่ง' : 'เพิ่มตำแหน่ง'">
      <form id="position-form" class="space-y-4" @submit.prevent="savePosition">
        <label class="block text-sm text-gray-700">
          ชื่อตำแหน่ง (ข้อความที่พิมพ์ในแบบฟอร์ม)
          <input v-model="positionModal.name" required maxlength="255" type="text" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded text-sm">
        </label>
        <p v-if="positionModal.uuid" class="text-xs text-gray-500">การแก้ชื่อมีผลกับการผูกตำแหน่งครั้งต่อไปเท่านั้น ข้อความในประวัติและแบบฟอร์มที่ออกไปแล้วไม่เปลี่ยน</p>
        <label class="block text-sm text-gray-700">
          รหัส (อักษรอังกฤษตัวใหญ่ ตัวเลข และ _)
          <input v-model="positionModal.code" required maxlength="50" pattern="[A-Z0-9_]+" type="text" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded text-sm font-mono">
        </label>
        <label class="block text-sm text-gray-700">
          กลุ่มบุคลากร
          <select v-model="positionModal.group_uuid" required class="mt-1 w-full px-3 py-2 border border-gray-300 rounded text-sm">
            <option v-for="group in activeGroups" :key="group.uuid" :value="group.uuid">{{ group.name }}</option>
          </select>
        </label>
        <label class="block text-sm text-gray-700">
          ลำดับการแสดง
          <input v-model.number="positionModal.sort_order" min="0" type="number" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded text-sm">
        </label>
        <label v-if="positionModal.uuid" class="flex items-center gap-2 text-sm text-gray-700">
          <input v-model="positionModal.is_active" type="checkbox">
          ใช้งาน (ปิดแล้วเลือกให้บุคลากรใหม่ไม่ได้ แต่ประวัติเดิมยังอยู่)
        </label>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2">
          <button type="button" class="px-4 py-2 rounded border border-gray-300 text-sm" @click="positionModal.show = false">ยกเลิก</button>
          <button type="submit" form="position-form" :disabled="saving" class="px-4 py-2 rounded bg-[#800000] hover:bg-[#660000] text-white text-sm disabled:opacity-50">บันทึก</button>
        </div>
      </template>
    </AppModal>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { getErrorMessage } from '../../utils/errorHandler'
import AppModal from '../../components/ui/AppModal.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'

const alertStore = useAlertStore()
const groups = ref([])
const loading = ref(true)
const loadError = ref('')
const saving = ref(false)

const activeGroups = computed(() => groups.value.filter(g => g.is_active))

const groupModal = reactive({ show: false, uuid: null, name: '', code: '', sort_order: 0, is_active: true })
const positionModal = reactive({ show: false, uuid: null, name: '', code: '', group_uuid: '', sort_order: 0, is_active: true })

const loadData = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get('/positions')
    groups.value = Array.isArray(res.data) ? res.data : []
  } catch (e) {
    loadError.value = getErrorMessage(e, 'ไม่สามารถโหลดทะเบียนตำแหน่งได้')
  } finally {
    loading.value = false
  }
}

const openGroupModal = (group = null) => {
  Object.assign(groupModal, {
    show: true,
    uuid: group?.uuid ?? null,
    name: group?.name ?? '',
    code: group?.code ?? '',
    sort_order: group?.sort_order ?? groups.value.length + 1,
    is_active: group?.is_active ?? true
  })
}

const openPositionModal = (position = null, group = null) => {
  Object.assign(positionModal, {
    show: true,
    uuid: position?.uuid ?? null,
    name: position?.name ?? '',
    code: position?.code ?? '',
    group_uuid: group?.uuid ?? activeGroups.value[0]?.uuid ?? '',
    sort_order: position?.sort_order ?? 0,
    is_active: position?.is_active ?? true
  })
}

const saveGroup = async () => {
  saving.value = true
  try {
    const payload = { name: groupModal.name, code: groupModal.code, sort_order: groupModal.sort_order }
    if (groupModal.uuid) {
      await api.put(`/admin/position-groups/${groupModal.uuid}`, { ...payload, is_active: groupModal.is_active })
      alertStore.success('แก้ไขกลุ่มบุคลากรแล้ว')
    } else {
      await api.post('/admin/position-groups', payload)
      alertStore.success('เพิ่มกลุ่มบุคลากรแล้ว')
    }
    groupModal.show = false
    await loadData()
  } catch (e) {
    alertStore.error(getErrorMessage(e, 'บันทึกกลุ่มบุคลากรไม่สำเร็จ'))
  } finally {
    saving.value = false
  }
}

const savePosition = async () => {
  saving.value = true
  try {
    const payload = { name: positionModal.name, code: positionModal.code, group_uuid: positionModal.group_uuid, sort_order: positionModal.sort_order }
    if (positionModal.uuid) {
      await api.put(`/admin/positions/${positionModal.uuid}`, { ...payload, is_active: positionModal.is_active })
      alertStore.success('แก้ไขตำแหน่งแล้ว')
    } else {
      await api.post('/admin/positions', payload)
      alertStore.success('เพิ่มตำแหน่งแล้ว')
    }
    positionModal.show = false
    await loadData()
  } catch (e) {
    alertStore.error(getErrorMessage(e, 'บันทึกตำแหน่งไม่สำเร็จ'))
  } finally {
    saving.value = false
  }
}

onMounted(loadData)
</script>
