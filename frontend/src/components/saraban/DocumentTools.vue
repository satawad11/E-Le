<template>
  <section class="bg-white rounded-2xl border p-5 space-y-4">
    <h2 class="font-semibold">ตรวจสอบและจัดทำฉบับหนังสือ</h2>
    <div class="flex flex-wrap gap-2">
      <AppButton variant="secondary" :loading="busy" @click="loadEvidence">ตรวจหลักฐานการลงนาม</AppButton>
      <AppButton v-if="!document.finalized_at" v-can="'saraban.signature.sign'" :disabled="document.status !== 'signed'" @click="finalize">ออกฉบับสมบูรณ์พร้อมรหัสตรวจสอบ</AppButton>
    </div>
    <p v-if="error" role="alert" class="text-red-700">{{ error }}</p>
    <div v-if="evidence !== null" class="text-sm space-y-2">
      <p v-if="!evidence.length">ยังไม่มีหลักฐานการลงนาม</p>
      <p v-for="item in evidence" :key="item.id">{{ item.signer_name_snapshot }} · ฉบับที่ {{ item.version_no }} · {{ item.signed_at }} · {{ item.file_intact ? 'ไฟล์สมบูรณ์' : 'ตรวจพบความผิดปกติของไฟล์' }}</p>
    </div>
    <details class="border-t pt-3">
      <summary class="cursor-pointer font-medium">เปรียบเทียบฉบับ</summary>
      <div class="grid sm:grid-cols-2 gap-3 my-3">
        <AppSelect v-model="left" label="ฉบับก่อน"><option value="">เลือกฉบับ</option><option v-for="v in versions" :key="v.id" :value="v.id">ฉบับที่ {{ v.version_no }}</option></AppSelect>
        <AppSelect v-model="right" label="ฉบับหลัง"><option value="">เลือกฉบับ</option><option v-for="v in versions" :key="v.id" :value="v.id">ฉบับที่ {{ v.version_no }}</option></AppSelect>
      </div>
      <AppButton :disabled="!left || !right || left === right || busy" @click="compare">เปรียบเทียบ</AppButton>
      <div v-if="comparison" class="text-sm mt-3 space-y-2">
        <p>{{ comparison.identical_file ? 'ไฟล์ทั้งสองเหมือนกัน' : 'ไฟล์ทั้งสองแตกต่างกัน' }}</p>
        <p>จำนวนหน้า: {{ comparison.file.left_pages ?? 'ไม่ทราบ' }} → {{ comparison.file.right_pages ?? 'ไม่ทราบ' }}</p>
        <p>ขนาดไฟล์: {{ comparison.file.left_bytes ?? 'ไม่ทราบ' }} → {{ comparison.file.right_bytes ?? 'ไม่ทราบ' }} ไบต์</p>
        <AppButton variant="secondary" @click="preview(left)">เปิดฉบับก่อน</AppButton>
        <AppButton variant="secondary" @click="preview(right)">เปิดฉบับหลัง</AppButton>
      </div>
    </details>
    <details v-if="!document.finalized_at" v-can="'saraban.endorse.create'" class="border-t pt-3">
      <summary class="cursor-pointer font-medium">เกษียนหนังสือ</summary>
      <AppInput v-model="note" label="ข้อความเกษียน" />
      <div class="grid grid-cols-3 gap-3 my-3">
        <AppInput v-model.number="placement.page" type="number" min="1" label="หน้า" />
        <AppInput v-model.number="placement.x" type="number" min="0" label="แนวนอน (จุด)" />
        <AppInput v-model.number="placement.y" type="number" min="0" label="แนวตั้ง (จุด)" />
      </div>
      <AppButton :disabled="!note.trim()" @click="endorse">บันทึกเกษียน</AppButton>
    </details>
    <details class="border-t pt-3" @toggle="loadRedactions">
      <summary class="cursor-pointer font-medium">สำเนาปกปิดข้อมูล</summary>
      <p class="text-sm my-3">เลือกฉบับและตรวจพื้นที่บนเอกสารก่อนสร้างสำเนา ต้นฉบับยังคงอยู่</p>
      <template v-if="redactions?.available">
        <div v-can="'saraban.signature.sign'" class="space-y-3">
          <AppSelect v-model="sourceId" label="ฉบับต้นทาง" @update:model-value="preview(sourceId)"><option value="">เลือกฉบับ</option><option v-for="v in versions" :key="v.id" :value="v.id">ฉบับที่ {{ v.version_no }}</option></AppSelect>
          <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
            <AppInput v-model.number="area.page" type="number" min="1" label="หน้า" />
            <AppInput v-model.number="area.x" type="number" min="0" label="แนวนอน (จุด)" />
            <AppInput v-model.number="area.y" type="number" min="0" label="แนวตั้ง (จุด)" />
            <AppInput v-model.number="area.width" type="number" min="1" label="กว้าง (จุด)" />
            <AppInput v-model.number="area.height" type="number" min="1" label="สูง (จุด)" />
          </div>
          <AppButton variant="secondary" :disabled="!sourceId || !fileUrl" @click="areas.push({ ...area, type: 'text', payload: { text: 'พื้นที่ปกปิด' } })">เพิ่มพื้นที่ปกปิด</AppButton>
          <AppInput v-model="redactionReason" label="เหตุผลการปกปิด" />
          <AppButton :disabled="!sourceId || !areas.length || redactionReason.trim().length < 5 || !fileUrl" @click="redact">สร้างสำเนาปกปิด</AppButton>
        </div>
      </template>
      <p v-else-if="redactions" class="text-amber-800">ระบบยังไม่พร้อมสร้างสำเนาปกปิด กรุณาติดต่อผู้ดูแล</p>
      <ul class="mt-3 space-y-2"><li v-for="r in redactions?.redactions || []" :key="r.id"><AppButton variant="ghost" @click="openFile(r.pdf_file_path)">{{ r.reason }} · {{ r.area_count }} พื้นที่ — เปิดสำเนา</AppButton></li></ul>
    </details>
    <PdfWorkspace v-if="fileUrl" v-model="areas" :source="fileUrl" :editable="Boolean(sourceId)" />
    <ConfirmOperation v-if="operation" v-bind="operation" @close="operation = null" @done="done" />
  </section>
</template>
<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import api from '../../axios'
import { fetchObjectUrl } from '../../utils/secureFile'
import { getErrorMessage } from '../../utils/errorHandler'
import AppButton from '../ui/AppButton.vue'
import AppInput from '../ui/AppInput.vue'
import AppSelect from '../ui/AppSelect.vue'
import PdfWorkspace from './PdfWorkspace.vue'
import ConfirmOperation from './ConfirmOperation.vue'
const props = defineProps({ document: { type: Object, required: true } })
const emit = defineEmits(['changed'])
const versions = computed(() => props.document.versions || [])
const evidence = ref(null), comparison = ref(null), redactions = ref(null), operation = ref(null)
const left = ref(''), right = ref(''), note = ref(''), sourceId = ref(''), redactionReason = ref(''), error = ref(''), fileUrl = ref('')
const busy = ref(false), areas = ref([])
const placement = reactive({ page: 1, x: 60, y: 100 })
const area = reactive({ page: 1, x: 60, y: 100, width: 160, height: 30 })
async function read(action) { busy.value = true; error.value = ''; try { await action() } catch (e) { error.value = getErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ') } finally { busy.value = false } }
const loadEvidence = () => read(async () => { evidence.value = (await api.get(`/saraban/documents/${props.document.id}/signatures`)).data.evidences })
const compare = () => read(async () => { comparison.value = (await api.get(`/saraban/documents/${props.document.id}/compare`, { params: { left: left.value, right: right.value } })).data })
const loadRedactions = (event) => { if (!event || event.target.open) return read(async () => { redactions.value = (await api.get(`/saraban/documents/${props.document.id}/redactions`)).data }) }
async function openFile(path) { return read(async () => { if (fileUrl.value) URL.revokeObjectURL(fileUrl.value); fileUrl.value = ''; areas.value = []; if (path) fileUrl.value = await fetchObjectUrl(`/storage/${path}`) }) }
function preview(id) { const version = versions.value.find(v => String(v.id) === String(id)); return openFile(version?.pdf_file_path || version?.original_file_path) }
function finalize() { operation.value = { title: 'ออกฉบับสมบูรณ์', description: 'ยืนยันออกหนังสือฉบับสมบูรณ์พร้อมรหัสตรวจสอบ หลังจากนี้แก้ไขฉบับนี้ไม่ได้', action: () => api.post(`/saraban/documents/${props.document.id}/finalize`) } }
function endorse() { operation.value = { title: 'บันทึกเกษียน', description: note.value, action: () => api.post(`/saraban/documents/${props.document.id}/endorse`, { objects: [{ ...placement, type: 'note', width: 240, height: 40, payload: { text: note.value } }] }) } }
function redact() { operation.value = { title: 'สร้างสำเนาปกปิด', description: `ตรวจพื้นที่ปกปิด ${areas.value.length} แห่งแล้ว เหตุผล: ${redactionReason.value}`, action: () => api.post(`/saraban/documents/${props.document.id}/redactions`, { source_version_id: sourceId.value, reason: redactionReason.value, areas: areas.value.map(({ page, x, y, width, height }) => ({ page, x, y, width, height })) }) } }
async function done() { operation.value = null; emit('changed'); if (redactions.value) await loadRedactions() }
onBeforeUnmount(() => { if (fileUrl.value) URL.revokeObjectURL(fileUrl.value) })
</script>
