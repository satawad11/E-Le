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
    <!--
      ตรวจสอบลายเซ็น (SIGNATURE-VERIFICATION · SoT §14)

      **สถานะและเหตุผลทุกข้อมาจาก server** — หน้าจอแสดงสิ่งที่ server ตัดสินแล้ว
      ไม่คำนวณเอง · หน้าจอที่ตัดสินเองคือหน้าจอที่แก้ผลได้ด้วยเครื่องมือนักพัฒนา
    -->
    <details class="border-t pt-3">
      <summary class="cursor-pointer font-medium">ตรวจสอบความน่าเชื่อถือของลายเซ็น</summary>
      <AppButton class="my-3" variant="secondary" :loading="busy" @click="loadVerification">ตรวจสอบลายเซ็น</AppButton>
      <div v-if="verification" class="text-sm space-y-3">
        <p class="font-medium">ผลรวม: {{ statusLabel(verification.status) }}</p>
        <p v-if="!verification.signature_count">หนังสือฉบับนี้ยังไม่มีลายเซ็น</p>
        <div v-for="signature in verification.signatures" :key="signature.id" class="border rounded-xl p-3 space-y-1">
          <p class="font-medium">
            ลำดับที่ {{ signature.sequence }} · {{ signature.signer }}
            <span v-if="signature.position"> · {{ signature.position }}</span>
          </p>
          <p>{{ statusLabel(signature.status) }} · ลงนามเมื่อ {{ signature.signed_at }}</p>
          <!--
            บอกชนิดตรงไปตรงมาเสมอ — ผู้อ่านต้องรู้ว่ากำลังดูลายเซ็นอิเล็กทรอนิกส์
            ที่ผูกกับตัวตนที่ยืนยันแล้ว ไม่ใช่ลายเซ็นดิจิทัลที่มีใบรับรองรองรับ
          -->
          <p class="text-gray-600">{{ typeLabel(signature.signature_type) }}</p>
          <ul class="list-disc ms-5">
            <li v-for="check in signature.checks" :key="check.check">
              {{ checkLabel(check.check) }}: {{ statusLabel(check.status) }} — {{ check.detail }}
            </li>
          </ul>
        </div>
        <ul v-if="verification.document_checks?.length" class="list-disc ms-5">
          <li v-for="check in verification.document_checks" :key="check.check">
            {{ checkLabel(check.check) }}: {{ statusLabel(check.status) }} — {{ check.detail }}
          </li>
        </ul>
        <p v-if="verification.revocation">
          คำขอเพิกถอน: {{ statusLabel(verification.revocation.status) }} — {{ verification.revocation.reason }}
        </p>
        <AppButton variant="secondary" :loading="busy" @click="loadReport">ดูชุดหลักฐานฉบับเต็ม</AppButton>
        <div v-if="report" class="border rounded-xl p-3 space-y-2">
          <p class="font-medium">ชุดหลักฐานการลงนาม ({{ report.reported_at }})</p>
          <div v-for="item in report.evidence_package" :key="item.id" class="space-y-1">
            <p>{{ item.signer_name_snapshot }} · {{ item.position_snapshot || 'ไม่ระบุตำแหน่ง' }} · {{ item.signed_at }}</p>
            <p class="text-gray-600 break-all">แฮชของฉบับที่ลงนาม: {{ item.document_hash }}</p>
            <p class="text-gray-600">ยืนยันตัวตนซ้ำเมื่อ: {{ item.reauthenticated_at || 'ไม่มีบันทึก' }}</p>
            <!-- แสดงหัวข้อใบรับรอง **เฉพาะเมื่อมีใบรับรองจริง** -->
            <p v-if="item.certificate" class="text-gray-600">ใบรับรอง: {{ item.certificate.issuer }} · หมายเลข {{ item.certificate.serial }}</p>
            <p v-if="item.trusted_timestamp" class="text-gray-600">เวลาที่ประทับ: {{ item.trusted_timestamp.authority }} · {{ item.trusted_timestamp.timestamped_at }}</p>
          </div>
          <p v-for="r in report.revocations" :key="r.id">
            คำขอเพิกถอนโดย {{ r.requested_by }} · {{ statusLabel(r.status === 'pending' ? 'REVOCATION_PENDING' : (r.status === 'approved' ? 'REVOKED' : 'INVALID')) }} — {{ r.reason }}
          </p>
        </div>
      </div>
      <!--
        การตัดสินคำขอ — ปรากฏเฉพาะผู้มีอำนาจอนุมัติ และเฉพาะเมื่อมีคำขอค้างอยู่
        ผู้ขอกับผู้ตัดสินต้องเป็นคนละคน ซึ่ง server บังคับอีกชั้นเสมอ
      -->
      <div
        v-if="verification?.revocation?.status === 'REVOCATION_PENDING'"
        v-can="'saraban.signature.revoke_approve'"
        class="border-t mt-3 pt-3 space-y-3"
      >
        <AppInput v-model="decisionNote" label="บันทึกการตัดสิน (จำเป็นเมื่อปฏิเสธ)" />
        <div class="flex flex-wrap gap-2">
          <AppButton @click="decide('approve')">อนุมัติการเพิกถอน</AppButton>
          <AppButton variant="secondary" :disabled="decisionNote.trim().length < 10" @click="decide('reject')">ปฏิเสธคำขอ</AppButton>
        </div>
      </div>
      <div v-if="verification && document.finalized_at" v-can="'saraban.signature.revoke'" class="border-t mt-3 pt-3 space-y-3">
        <AppInput v-model="revocationReason" label="เหตุผลในการขอเพิกถอน (อย่างน้อย 10 ตัวอักษร)" />
        <p class="text-sm text-gray-600">การเพิกถอนต้องผ่านการอนุมัติจากผู้มีอำนาจอีกคน และต้องยืนยันตัวตนซ้ำ</p>
        <AppButton :disabled="revocationReason.trim().length < 10" @click="requestRevocation">ขอเพิกถอนหนังสือฉบับนี้</AppButton>
      </div>
    </details>
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
    <!--
      ผูกเลขที่จองไว้ (SoT §9)

      **เลขจะออกจริงตอนลงนาม ไม่ใช่ตอนผูก** — บอกไว้บนหน้าจอเพราะเจ้าหน้าที่
      ที่กดผูกแล้วไม่เห็นเลขบนหนังสือจะเข้าใจว่าระบบไม่ทำงาน
    -->
    <details
      v-if="!document.finalized_at && !document.document_number_id"
      v-can="'saraban.number.allocate'"
      class="border-t pt-3"
      @toggle="loadReservedNumbers"
    >
      <summary class="cursor-pointer font-medium">ผูกเลขหนังสือที่จองไว้</summary>
      <p class="text-sm text-gray-600 my-3">
        เลือกเลขที่จองไว้สำหรับหนังสือฉบับนี้ · เลขจะออกเป็นทางการเมื่อลงนามแล้วเท่านั้น
        และคืนเลขได้ตราบใดที่ยังไม่ลงนาม
      </p>
      <template v-if="reservedNumbers !== null">
        <AppSelect v-if="reservedNumbers.length" v-model="numberId" label="เลขที่จองไว้">
          <option value="">เลือกเลข</option>
          <option v-for="n in reservedNumbers" :key="n.id" :value="n.id">
            {{ n.formatted }} · {{ n.series }}
          </option>
        </AppSelect>
        <p v-else class="text-sm text-gray-500">ยังไม่มีเลขที่จองไว้ — จองได้ที่เมนู "จองและออกเลขหนังสือ"</p>
        <AppButton v-if="reservedNumbers.length" class="mt-3" :disabled="!numberId" @click="allocateNumber">
          ผูกเลขกับหนังสือฉบับนี้
        </AppButton>
      </template>
    </details>
    <details v-if="!document.finalized_at" v-can="'saraban.endorse.create'" class="border-t pt-3">
      <summary class="cursor-pointer font-medium">เกษียนหนังสือ</summary>
      <!--
        เดิมช่องนี้ให้กรอกเลขหน้าและพิกัดเป็นตัวเลขโดยไม่เห็นเอกสาร ซึ่งผู้เกษียน
        ไม่มีทางเดาถูกว่าความเห็นจะไปตกตรงไหน · ย้ายไปหน้าเกษียนที่เห็นเอกสารจริง
        และคลิกวางได้ทุกตำแหน่งทุกหน้า
      -->
      <p class="text-sm text-gray-600 my-3">
        เกษียนโดยเขียนลายมือหรือวางข้อความลงบนหน้าเอกสารจริง
      </p>
      <AppButton @click="openEndorsePage">เปิดหน้าเกษียนหนังสือ</AppButton>
    </details>
    <details class="border-t pt-3" @toggle="loadRedactions">
      <summary class="cursor-pointer font-medium">สำเนาปกปิดข้อมูล</summary>
      <p class="text-sm my-3">เลือกฉบับและตรวจพื้นที่บนเอกสารก่อนสร้างสำเนา ต้นฉบับยังคงอยู่</p>
      <template v-if="redactions?.available">
        <div v-can="'saraban.signature.sign'" class="space-y-3">
          <AppSelect v-model="sourceId" label="ฉบับต้นทาง" @update:model-value="preview($event, true)"><option value="">เลือกฉบับ</option><option v-for="v in versions" :key="v.id" :value="v.id">ฉบับที่ {{ v.version_no }}</option></AppSelect>
          <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
            <AppInput v-model.number="area.page" type="number" min="1" label="หน้า" />
            <AppInput v-model.number="area.x" type="number" min="0" label="แนวนอน (จุด)" />
            <AppInput v-model.number="area.y" type="number" min="0" label="แนวตั้ง (จุด)" />
            <AppInput v-model.number="area.width" type="number" min="1" label="กว้าง (จุด)" />
            <AppInput v-model.number="area.height" type="number" min="1" label="สูง (จุด)" />
          </div>
          <AppButton variant="secondary" :disabled="!canRedact" @click="areas.push({ ...area, type: 'text', payload: { text: 'พื้นที่ปกปิด' } })">เพิ่มพื้นที่ปกปิด</AppButton>
          <AppInput v-model="redactionReason" label="เหตุผลการปกปิด" />
          <AppButton :disabled="!canRedact || !areas.length || redactionReason.trim().length < 5" @click="redact">สร้างสำเนาปกปิด</AppButton>
        </div>
      </template>
      <p v-else-if="redactions" class="text-amber-800">ระบบยังไม่พร้อมสร้างสำเนาปกปิด กรุณาติดต่อผู้ดูแล</p>
      <ul class="mt-3 space-y-2"><li v-for="r in redactions?.redactions || []" :key="r.id"><AppButton variant="ghost" @click="openFile(r.pdf_file_path)">{{ r.reason }} · {{ r.area_count }} พื้นที่ — เปิดสำเนา</AppButton></li></ul>
    </details>
    <PdfWorkspace v-if="fileUrl" v-model="areas" :source="fileUrl" :editable="canRedact" />
    <ConfirmOperation v-if="operation" v-bind="operation" @close="operation = null" @done="done" />
  </section>
</template>
<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
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
const router = useRouter()

/** เกษียนทำที่หน้าเต็มซึ่งเห็นเอกสารจริง — ตรงนี้เป็นเพียงทางเข้า */
const openEndorsePage = () => router.push({ name: 'saraban-document-endorse', params: { id: props.document.id } })
const versions = computed(() => props.document.versions || [])
const evidence = ref(null), comparison = ref(null), redactions = ref(null), operation = ref(null), verification = ref(null)
const left = ref(''), right = ref(''), sourceId = ref(''), redactionReason = ref(''), error = ref(''), fileUrl = ref('')
const revocationReason = ref(''), decisionNote = ref(''), report = ref(null)
const reservedNumbers = ref(null), numberId = ref('')
const busy = ref(false), areas = ref([])
const previewSourceId = ref(null)
let previewRequest = 0
const canRedact = computed(() => Boolean(fileUrl.value && sourceId.value && String(previewSourceId.value) === String(sourceId.value)))
const area = reactive({ page: 1, x: 60, y: 100, width: 160, height: 30 })
async function read(action) { busy.value = true; error.value = ''; try { await action() } catch (e) { error.value = getErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ') } finally { busy.value = false } }
const loadEvidence = () => read(async () => { evidence.value = (await api.get(`/saraban/documents/${props.document.id}/signatures`)).data.evidences })
const loadVerification = () => read(async () => { verification.value = (await api.get(`/saraban/documents/${props.document.id}/verification`)).data })
/*
 * คำอธิบายสถานะเป็นภาษาไทยที่ผู้ปฏิบัติงานอ่านแล้วรู้ว่าต้องทำอะไรต่อ
 *
 * ไม่ตัดสินสถานะเอง — แปลงรหัสที่ server ส่งมาเป็นคำเท่านั้น · รหัสที่ไม่รู้จัก
 * แสดงว่า "ตรวจสอบไม่ได้" ไม่ใช่ "ผ่าน" เพราะการเดาว่าผ่านคือความเสี่ยง
 */
const statusLabel = v => ({
  VALID: 'ผ่านการตรวจสอบ',
  VALID_WITH_WARNINGS: 'ผ่าน แต่มีข้อสังเกต',
  INVALID: 'ไม่ผ่านการตรวจสอบ',
  DOCUMENT_MODIFIED_AFTER_SIGNING: 'ไฟล์ถูกแก้หลังการลงนาม',
  CERTIFICATE_EXPIRED: 'ใบรับรองหมดอายุ',
  CERTIFICATE_REVOKED: 'ใบรับรองถูกเพิกถอน',
  CERTIFICATE_UNTRUSTED: 'ใบรับรองไม่อยู่ในรายชื่อที่เชื่อถือ',
  TIMESTAMP_INVALID: 'เวลาที่ประทับเชื่อถือไม่ได้',
  SIGNER_MISMATCH: 'ผู้ลงนามไม่ตรงกับหลักฐาน',
  AUTHORITY_INVALID: 'อำนาจลงนามไม่ถูกต้อง',
  SIGNATURE_ORDER_INVALID: 'ลงนามผิดลำดับ',
  REVOCATION_PENDING: 'มีคำขอเพิกถอนรอการตัดสิน',
  REVOKED: 'ถูกเพิกถอนแล้ว',
  UNVERIFIED: 'ยังไม่มีลายเซ็นให้ตรวจ',
}[v] || 'ตรวจสอบไม่ได้')
const checkLabel = v => ({
  version_binding: 'การผูกกับฉบับที่ลงนาม',
  integrity: 'ความสมบูรณ์ของไฟล์',
  signer_identity: 'ตัวตนผู้ลงนาม',
  authority: 'อำนาจลงนาม',
  signing_order: 'ลำดับการลงนาม',
  certificate: 'ใบรับรอง',
  timestamp: 'เวลาที่ประทับ',
  metadata_seal: 'ข้อมูลทะเบียน',
  document_status: 'สถานะหนังสือ',
}[v] || v)
/* **ไม่เรียกลายเซ็นอิเล็กทรอนิกส์ว่าลายเซ็นดิจิทัลเด็ดขาด** — เป็นคนละสิ่งกัน */
const typeLabel = v => v === 'digital'
  ? 'ลายเซ็นดิจิทัล (มีใบรับรองรองรับ)'
  : 'ลายเซ็นอิเล็กทรอนิกส์ (ผูกกับตัวตนที่ยืนยันแล้ว ไม่ใช่ลายเซ็นดิจิทัล)'
const loadReport = () => read(async () => { report.value = (await api.get(`/saraban/documents/${props.document.id}/verification/report`)).data })
function decide(decision) {
  operation.value = {
    title: decision === 'approve' ? 'อนุมัติการเพิกถอน' : 'ปฏิเสธคำขอเพิกถอน',
    description: decision === 'approve'
      ? 'หนังสือฉบับนี้จะเข้าสถานะยกเลิก ผู้ที่ตรวจสอบจาก QR จะเห็นว่าถูกเพิกถอนแล้ว ทะเบียนและไฟล์ยังคงอยู่'
      : `คำขอจะถูกปฏิเสธและยังคงอยู่ในทะเบียน · เหตุผล: ${decisionNote.value}`,
    action: () => api.post(`/saraban/revocations/${verification.value.revocation.id}/decision`, { decision, note: decisionNote.value || undefined }),
  }
}
function requestRevocation() {
  operation.value = {
    title: 'ขอเพิกถอนหนังสือ',
    description: `คำขอนี้ต้องผ่านการอนุมัติจากผู้มีอำนาจอีกคน · เหตุผล: ${revocationReason.value}`,
    action: () => api.post(`/saraban/documents/${props.document.id}/revocations`, { reason: revocationReason.value }),
  }
}
const compare = () => read(async () => { comparison.value = (await api.get(`/saraban/documents/${props.document.id}/compare`, { params: { left: Number(left.value), right: Number(right.value) } })).data })
const loadRedactions = (event) => { if (!event || event.target.open) return read(async () => { redactions.value = (await api.get(`/saraban/documents/${props.document.id}/redactions`)).data }) }
async function openFile(path, source = null) {
  const request = ++previewRequest
  previewSourceId.value = null
  if (fileUrl.value) URL.revokeObjectURL(fileUrl.value)
  fileUrl.value = ''
  areas.value = []
  error.value = ''
  if (!path) return
  try {
    const url = await fetchObjectUrl(`/storage/${path}`)
    // A slower earlier selection must never replace the currently selected file.
    if (request !== previewRequest) { URL.revokeObjectURL(url); return }
    fileUrl.value = url
    previewSourceId.value = source
  } catch (e) {
    if (request === previewRequest) error.value = getErrorMessage(e, 'เปิดเอกสารไม่สำเร็จ')
  }
}
function preview(id, forRedaction = false) {
  const version = versions.value.find(v => String(v.id) === String(id))
  return openFile(version?.pdf_file_path || version?.original_file_path, forRedaction ? version?.id : null)
}
/*
 * เลขที่จองไว้และยังไม่ถูกผูกกับหนังสือใด — โหลดตอนเปิดช่องเท่านั้น
 * เพราะเจ้าหน้าที่ส่วนใหญ่ไม่ได้ผูกเลขทุกครั้งที่เปิดหน้าหนังสือ
 */
const loadReservedNumbers = () => read(async () => {
  const { data } = await api.get('/saraban/numbers/reserved')

  reservedNumbers.value = (data.reserved || []).filter((n) => n.status === 'reserved')
})

function allocateNumber() {
  const chosen = reservedNumbers.value.find((n) => String(n.id) === String(numberId.value))

  operation.value = {
    title: 'ผูกเลขหนังสือ',
    description: `ผูกเลข ${chosen?.formatted} กับหนังสือฉบับนี้ — เลขจะออกเป็นทางการเมื่อลงนามแล้ว`,
    action: () => api.post(`/saraban/numbers/${numberId.value}/allocate`, { document_id: props.document.id }),
  }
}

function finalize() { operation.value ={ title: 'ออกฉบับสมบูรณ์', description: 'ยืนยันออกหนังสือฉบับสมบูรณ์พร้อมรหัสตรวจสอบ หลังจากนี้แก้ไขฉบับนี้ไม่ได้', action: () => api.post(`/saraban/documents/${props.document.id}/finalize`) } }
function redact() {
  if (!canRedact.value) return
  const documentId = props.document.id
  const payload = {
    source_version_id: Number(previewSourceId.value),
    reason: redactionReason.value,
    areas: areas.value.map(({ page, x, y, width, height }) => ({ page, x, y, width, height })),
  }
  operation.value = {
    title: 'สร้างสำเนาปกปิด',
    description: `ตรวจพื้นที่ปกปิด ${payload.areas.length} แห่งแล้ว เหตุผล: ${payload.reason}`,
    action: () => api.post(`/saraban/documents/${documentId}/redactions`, payload),
  }
}
async function done() { operation.value = null; emit('changed'); if (redactions.value) await loadRedactions() }
onBeforeUnmount(() => { previewRequest++; if (fileUrl.value) URL.revokeObjectURL(fileUrl.value) })
</script>
