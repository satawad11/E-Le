<template>
  <section class="bg-white border rounded-2xl p-5 space-y-3">
    <h2 class="font-semibold">{{ documentId ? 'การเก็บรักษาและอายัดหนังสือ' : 'หนังสือครบกำหนดเก็บรักษา' }}</h2>
    <AppButton variant="secondary" :loading="busy" @click="load">ตรวจสอบรายการ</AppButton>
    <p v-if="error" class="text-red-700" role="alert">{{ error }}</p>
    <template v-if="state && documentId">
      <p>{{ state.on_hold ? 'หนังสือถูกอายัด ห้ามทำลาย' : 'ไม่มีการอายัดที่มีผล' }}</p>
      <p v-if="state.schedule">เก็บถึง {{ state.schedule.retain_until }} · {{ statusLabel(state.schedule.status) }}</p>
      <div v-can="'saraban.config.manage'" class="flex flex-wrap items-end gap-3">
        <AppInput v-model.number="years" type="number" min="1" max="100" label="ระยะเวลาเก็บรักษา (ปี)" />
        <AppButton :disabled="!years" @click="schedule">กำหนดการเก็บรักษา</AppButton>
      </div>
      <div v-if="canHold" class="grid sm:grid-cols-2 gap-3">
        <AppInput v-model="reference" label="เลขอ้างอิงการอายัด" />
        <AppInput v-model="reason" label="เหตุผลการอายัด" />
        <AppButton :disabled="!reference.trim() || reason.trim().length < 5" @click="hold">อายัดหนังสือ</AppButton>
      </div>
      <ul class="space-y-2"><li v-for="h in state.holds" :key="h.id" class="flex flex-wrap gap-3 items-center">
        <span>{{ h.reference }} · {{ h.reason }} · {{ h.active ? 'มีผล' : 'ปลดแล้ว' }}</span>
        <AppButton v-if="h.active && canHold" variant="secondary" @click="release(h)">ปลดอายัด</AppButton>
      </li></ul>
    </template>
    <p v-if="rows && !rows.length" class="text-gray-500">ไม่มีรายการ</p>
    <ul class="space-y-4"><li v-for="row in rows || []" :key="row.id" class="border rounded-xl p-3 space-y-2">
      <p>{{ row.document?.subject || 'กำหนดการของหนังสือฉบับนี้' }} · {{ statusLabel(row.status) }}</p>
      <p class="text-sm">เก็บถึง {{ row.retain_until }}</p>
      <router-link v-if="row.document?.id && !documentId" :to="`/saraban/documents/${row.document.id}`" class="text-[#800000] underline">เปิดหนังสือและประวัติการอายัด</router-link>
      <div class="flex flex-wrap gap-2">
        <AppButton v-if="row.status !== 'disposed' && row.status !== 'approved'" v-can="'saraban.policy.manage'" :disabled="state?.on_hold" @click="approve(row)">อนุมัติการทำลาย</AppButton>
        <AppButton v-if="row.status === 'approved'" v-can="'saraban.config.manage'" :disabled="state?.on_hold" @click="dispose(row)">ทำลายไฟล์ตามอนุมัติ</AppButton>
      </div>
    </li></ul>
    <ConfirmOperation v-if="operation" v-bind="operation" @close="operation = null" @done="completed" />
  </section>
</template>
<script setup>
import { computed, ref } from 'vue'
import api from '../../axios'
import { useAuthStore } from '../../stores/auth'
import { getErrorMessage } from '../../utils/errorHandler'
import AppButton from '../ui/AppButton.vue'
import AppInput from '../ui/AppInput.vue'
import ConfirmOperation from './ConfirmOperation.vue'
const props = defineProps({ documentId: { type: [String, Number], default: null } })
const auth = useAuthStore()
const canHold = computed(() => auth.hasAnyPermission(['saraban.policy.manage', 'saraban.config.manage']))
const state = ref(null), rows = ref(null), busy = ref(false), error = ref(''), operation = ref(null)
const years = ref(''), reference = ref(''), reason = ref('')
const statusLabel = s => ({ scheduled: 'อยู่ระหว่างเก็บรักษา', approved: 'อนุมัติทำลายแล้ว', disposed: 'ทำลายแล้ว', pending: 'รอพิจารณา' }[s] || 'อยู่ระหว่างดำเนินการ')
async function load() { busy.value = true; error.value = ''; try {
  if (props.documentId) { state.value = (await api.get(`/saraban/documents/${props.documentId}/retention`)).data; rows.value = state.value.schedule ? [state.value.schedule] : [] }
  else rows.value = (await api.get('/saraban/retention/due')).data.due
} catch (e) { error.value = getErrorMessage(e, 'โหลดการเก็บรักษาไม่สำเร็จ') } finally { busy.value = false } }
function schedule() { operation.value = { title: 'กำหนดการเก็บรักษา', description: `เก็บหนังสือ ${years.value} ปีตามการพิจารณาของเจ้าหน้าที่`, action: () => api.post(`/saraban/documents/${props.documentId}/retention`, { retain_years: years.value }) } }
function hold() { operation.value = { title: 'อายัดหนังสือ', description: `${reference.value}: ${reason.value}`, action: () => api.post(`/saraban/documents/${props.documentId}/holds`, { reference: reference.value, reason: reason.value }) } }
function release(h) { operation.value = { title: 'ปลดอายัด', description: `ปลดอายัดตามเลขอ้างอิง ${h.reference}`, action: () => api.post(`/saraban/holds/${h.id}/release`) } }
function approve(row) { operation.value = { title: 'อนุมัติการทำลาย', description: 'อนุมัติให้เจ้าหน้าที่อีกคนทำลายไฟล์เมื่อครบกำหนดและไม่มีการอายัด', action: () => api.post(`/saraban/retention/${row.id}/approve`) } }
function dispose(row) { operation.value = { title: 'ทำลายไฟล์', description: 'การทำลายไฟล์ย้อนกลับไม่ได้ โปรดตรวจหนังสือและอนุมัติก่อนยืนยัน ทะเบียนยังคงอยู่เพื่อการตรวจสอบ', action: () => api.post(`/saraban/retention/${row.id}/dispose`) } }
async function completed() { operation.value = null; await load() }
</script>
