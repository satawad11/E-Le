<template>
  <section class="bg-white border rounded-2xl p-4 space-y-3">
    <div class="flex flex-wrap items-end gap-3">
      <AppButton variant="secondary" :loading="busy" @click="load">มุมมองที่บันทึกไว้</AppButton>
      <AppInput v-model="name" label="ชื่อมุมมองจากตัวกรองปัจจุบัน" />
      <label class="text-sm"><input v-model="shared" type="checkbox"> แบ่งปันตัวกรอง</label>
      <AppButton :disabled="busy || !name.trim()" @click="save">บันทึกมุมมองใหม่</AppButton>
    </div>
    <p v-if="error" role="alert" class="text-red-700">{{ error }}</p>
    <p v-if="views && !views.length">ยังไม่มีมุมมองที่บันทึกไว้</p>
    <ul class="space-y-2"><li v-for="view in views || []" :key="view.id" class="flex flex-wrap gap-2 items-center">
      <AppButton variant="ghost" @click="$emit('apply', view.filters)">{{ view.name }}{{ view.is_shared ? ' · แบ่งปัน' : '' }}</AppButton>
      <template v-if="view.is_mine"><AppButton variant="secondary" @click="update(view)">แทนที่ด้วยตัวกรองปัจจุบัน</AppButton><AppButton variant="secondary" @click="remove(view)">ลบมุมมอง</AppButton></template>
    </li></ul>
    <ConfirmOperation v-if="operation" v-bind="operation" @close="operation = null" @done="completed" />
  </section>
</template>
<script setup>
import { ref } from 'vue'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import AppInput from '../ui/AppInput.vue'
import AppButton from '../ui/AppButton.vue'
import ConfirmOperation from '../ui/ConfirmOperation.vue'
const props = defineProps({ filters: { type: Object, required: true } })
defineEmits(['apply'])
const views = ref(null), name = ref(''), shared = ref(false), busy = ref(false), error = ref(''), operation = ref(null)
async function load() { busy.value = true; error.value = ''; try { views.value = (await api.get('/saraban/saved-views', { params: { resource: 'documents' } })).data.views } catch(e) { error.value = getErrorMessage(e, 'โหลดมุมมองไม่สำเร็จ') } finally { busy.value = false } }
async function save() { busy.value = true; error.value = ''; try { await api.post('/saraban/saved-views', { name: name.value, resource: 'documents', filters: { ...props.filters }, is_shared: shared.value }); name.value = ''; await load() } catch(e) { error.value = getErrorMessage(e, 'บันทึกมุมมองไม่สำเร็จ') } finally { busy.value = false } }
function update(view) { operation.value = { title: 'แทนที่ตัวกรอง', description: `อัปเดตมุมมอง ${view.name} ด้วยตัวกรองที่กำลังใช้อยู่`, action: () => api.put(`/saraban/saved-views/${view.id}`, { filters: { ...props.filters } }) } }
function remove(view) { operation.value = { title: 'ลบมุมมอง', description: `ลบตัวกรอง ${view.name} หนังสือยังคงอยู่ครบ`, action: () => api.delete(`/saraban/saved-views/${view.id}`) } }
async function completed() { operation.value = null; await load() }
</script>
