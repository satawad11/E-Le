<template>
  <div class="max-w-4xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">{{ pageTitle }}</h1>
        <p class="text-sm text-gray-500 mt-1">
          หนังสือที่เสนอเข้าสู่กระบวนการแล้วจะแก้ไขไม่ได้ การแนบไฟล์ใหม่จะบันทึกเป็นฉบับใหม่เสมอ
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <!-- เกษียนหนังสือ (สำหรับผู้บริหาร / ผอ. - รอง ผอ. ๔ ฝ่าย / ผู้ตรวจทาน) -->
        <AppButton
          v-if="isEdit && !documentRecord?.finalized_at && versions.length"
          v-can="'saraban.endorse.create'"
          variant="secondary"
          @click="openEndorse"
        >
          เกษียนหนังสือ
        </AppButton>
        <!--
          ลงนามเป็นหน้าเต็ม (ปิด RISK-30) — ผู้ลงนามต้องเห็นเอกสารและลากวาง
          ลายเซ็นบนตำแหน่งจริง ซึ่งทำใน modal ไม่ได้
        -->
        <AppButton
          v-if="isEdit && !documentRecord?.finalized_at && versions.length"
          v-can="'saraban.signature.sign'"
          :variant="isSignatureStep ? 'primary' : 'secondary'"
          @click="openSigning"
        >
          ลงนาม{{ isSignatureStep ? ' (ถึงขั้นตอนแล้ว)' : '' }}
        </AppButton>
        <!-- INT-13 — เวียนหนังสือถึงผู้รับ -->
        <AppButton
          v-if="isEdit && (documentRecord?.status === 'signed' || documentRecord?.status === 'registered')"
          v-can="'saraban.distribution.send'"
          variant="secondary"
          @click="distributing = true"
        >
          เวียนหนังสือ
        </AppButton>
        <!-- INT-10 — พื้นที่ประทับตราของหนังสือฉบับนี้ (เฉพาะหนังสือรับเข้า) -->
        <AppButton
          v-if="isEdit && (documentRecord?.direction === 'incoming' || documentRecord?.incoming_registration)"
          variant="outline"
          @click="openWorkspace"
        >
          ประทับตรา
        </AppButton>
        <AppButton variant="ghost" @click="backToList">กลับสู่ทะเบียน</AppButton>
      </div>

      <DistributeDocumentModal
        v-if="isEdit && distributing"
        :document-id="route.params.id"
        :confidentiality="form.confidentiality"
        @close="distributing = false"
        @sent="onDistributed"
      />
    </div>

    <!-- แผงการเดินเรื่องและอนุมัติด่วน (Workflow Quick Actions ด้านบน) -->
    <section
      v-if="workflow.started && workflow.transitions.length"
      class="bg-gradient-to-r from-amber-50 to-orange-50 border border-amber-200 rounded-2xl p-5 shadow-xs space-y-3"
    >
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-amber-200/70 pb-3">
        <div>
          <div class="flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full bg-amber-500 animate-ping" />
            <h2 class="font-bold text-amber-900 text-sm">การเดินเรื่อง — ขั้นตอนที่รอท่านดำเนินการ</h2>
          </div>
          <p class="text-xs text-amber-800 mt-0.5">
            ขั้นปัจจุบัน: <strong>{{ workflow.current_step?.name || '—' }}</strong>
          </p>
        </div>
        <AppBadge :variant="workflowVariant">{{ workflowStatusLabel }}</AppBadge>
      </div>

      <div class="space-y-3">
        <AppTextarea v-model="workflowComment" label="ความเห็นประกอบการพิจารณา" :rows="2" placeholder="ระบุความเห็นหรือคำสั่งการ..." />
        <div class="flex flex-wrap gap-2">
          <AppButton
            v-for="transition in workflow.transitions"
            :key="transition.action"
            :variant="transition.action === 'approve' ? 'primary' : 'outline'"
            :loading="actingOn === transition.action"
            @click="performWorkflow(transition)"
          >
            {{ transition.name }}
          </AppButton>
        </div>
      </div>
    </section>

    <LoadingState v-if="loading" type="skeleton-form" />
    <ErrorState v-else-if="loadError" :message="loadError" @retry="boot" />

    <form v-else class="space-y-6" @submit.prevent="save">
      <!-- แม่แบบหนังสือราชการมาตรฐานสำหรับสร้างหนังสือใหม่ -->
      <section v-if="!isEdit" class="bg-gradient-to-r from-primary-50/70 to-blue-50/70 border border-primary-100 rounded-2xl p-5 shadow-xs">
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-2">
            <span class="text-base">📋</span>
            <h2 class="font-semibold text-gray-800 text-sm">แม่แบบหนังสือราชการมาตรฐาน (ระเบียบสำนักนายกฯ ๒๕๖๔)</h2>
          </div>
          <span class="text-xs text-primary-700 font-medium">คลิกเพื่อใส่โครงสร้างเอกสารอัตโนมัติ</span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <button
            type="button"
            class="flex flex-col text-left p-3.5 rounded-xl border transition-all bg-white hover:border-primary-400 hover:shadow-xs"
            :class="selectedTemplate === 'memo' ? 'border-primary-500 ring-2 ring-primary-200' : 'border-gray-200'"
            @click="applyTemplate('memo')"
          >
            <span class="font-semibold text-sm text-gray-800 flex items-center justify-between">
              บันทึกข้อความ (แบบที่ ๒)
              <span class="text-[11px] px-2 py-0.5 rounded-full bg-primary-100 text-primary-800 font-normal">ครุฑซ้าย ๑.๕ ซม.</span>
            </span>
            <span class="text-xs text-gray-500 mt-1">ใช้ติดต่อราชการภายในวิทยาลัย / เสนอ ผอ. - รอง ผอ. ตามสายงาน</span>
          </button>

          <button
            type="button"
            class="flex flex-col text-left p-3.5 rounded-xl border transition-all bg-white hover:border-primary-400 hover:shadow-xs"
            :class="selectedTemplate === 'external' ? 'border-primary-500 ring-2 ring-primary-200' : 'border-gray-200'"
            @click="applyTemplate('external')"
          >
            <span class="font-semibold text-sm text-gray-800 flex items-center justify-between">
              หนังสือภายนอก (แบบที่ ๑)
              <span class="text-[11px] px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 font-normal">ครุฑกลาง ๓ ซม.</span>
            </span>
            <span class="text-xs text-gray-500 mt-1">ใช้ติดต่อราชการภายนอกวิทยาลัย / ติดต่อหน่วยงานอื่น / เอกชน</span>
          </button>
        </div>

        <!-- ข้อความปฏิเสธความรับผิดสำหรับแนบท้ายอีเมลหนังสือภายนอก (ภาคผนวก ๗) -->
        <div v-if="externalDisclaimer" class="mt-3 rounded-xl bg-white/70 border border-primary-100 p-3">
          <p class="text-xs font-semibold text-gray-700 mb-1">ข้อความปฏิเสธความรับผิดสำหรับแนบท้ายอีเมล (๒ ภาษา)</p>
          <pre class="text-[11px] text-gray-500 whitespace-pre-wrap font-sans leading-relaxed">{{ externalDisclaimer }}</pre>
        </div>
      </section>

      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100">
          <h2 class="font-semibold text-gray-800">รายละเอียดหนังสือ</h2>
        </header>

        <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <AppSelect
            v-model="form.document_type_id"
            label="ประเภทหนังสือ"
            required
            :disabled="readOnly"
            :error="errors.document_type_id"
          >
            <option value="">เลือกประเภท</option>
            <option v-for="type in options.document_types" :key="type.id" :value="type.id">
              {{ type.name }}
            </option>
          </AppSelect>

          <AppSelect
            v-model="form.direction"
            label="ทิศทางของหนังสือ"
            required
            :disabled="readOnly"
            :error="errors.direction"
          >
            <option v-for="d in DIRECTIONS" :key="d.value" :value="d.value">{{ d.label }}</option>
          </AppSelect>

          <div class="sm:col-span-2">
            <AppInput v-model="form.subject" label="เรื่อง" required :disabled="readOnly" :error="errors.subject" />
          </div>

          <div class="sm:col-span-2">
            <AppTextarea
              v-model="form.summary"
              label="สาระสำคัญ"
              :disabled="readOnly"
              :error="errors.summary"
            />
          </div>

          <AppSelect
            v-model="form.confidentiality"
            label="ชั้นความลับ"
            required
            :disabled="readOnly"
            :error="errors.confidentiality"
          >
            <option v-for="c in CONFIDENTIALITY" :key="c.value" :value="c.value">{{ c.label }}</option>
          </AppSelect>

          <AppSelect
            v-model="form.urgency"
            label="ชั้นความเร็ว"
            required
            :disabled="readOnly"
            :error="errors.urgency"
          >
            <option v-for="u in URGENCY" :key="u.value" :value="u.value">{{ u.label }}</option>
          </AppSelect>

          <div class="sm:col-span-2">
            <AppSelect
              v-model="form.owner_org_unit_id"
              label="หน่วยงานเจ้าของเรื่อง"
              required
              :disabled="readOnly"
              :error="errors.owner_org_unit_id"
            >
              <option value="">เลือกหน่วยงาน</option>
              <option v-for="unit in orgUnits" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
            </AppSelect>
          </div>
        </div>
      </section>

      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100">
          <h2 class="font-semibold text-gray-800">ไฟล์หนังสือ</h2>
          <p class="text-sm text-gray-500 mt-0.5">
            รับไฟล์ PDF, JPG หรือ PNG · ทุกไฟล์ผ่านการตรวจความปลอดภัยก่อนจัดเก็บ
          </p>
        </header>

        <div class="p-5 space-y-4">
          <div v-if="!readOnly">
            <label for="document-file" class="block text-sm font-semibold text-gray-700 mb-1.5">
              แนบไฟล์{{ isEdit ? 'ฉบับใหม่' : 'ต้นฉบับ' }}
            </label>
            <input
              id="document-file"
              type="file"
              accept=".pdf,.jpg,.jpeg,.png"
              class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-xl file:border-0 file:bg-[#800000] file:px-4 file:py-2 file:text-white file:text-sm file:font-semibold hover:file:bg-[#600000]"
              @change="onFileChange"
            />
            <p v-if="errors.file" class="mt-1 text-xs text-red-600" role="alert">{{ errors.file }}</p>
            <p class="mt-1.5 text-xs text-gray-400">
              ข้อแนะนำตามระเบียบงานสารบรรณ (ภาคผนวก ๗ ข้อ ๔.๓): การตั้งชื่อไฟล์ควรใช้รูปแบบ "เลขหนังสือ_ปีพ.ศ._ชื่อเรื่องย่อ.pdf" เช่น ศธ04001-125_2569_เชิญประชุม.pdf เพื่อความเป็นระเบียบและค้นหาง่าย
            </p>
          </div>

          <div v-if="versions.length" class="overflow-x-auto">
            <table class="w-full text-sm min-w-[480px]">
              <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                <tr>
                  <th class="text-left px-4 py-2.5 font-semibold">ฉบับที่</th>
                  <th class="text-left px-4 py-2.5 font-semibold">ขั้น</th>
                  <th class="text-left px-4 py-2.5 font-semibold">บันทึกเมื่อ</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-50">
                <tr v-for="version in versions" :key="version.id">
                  <td class="px-4 py-2.5 font-medium text-gray-900">{{ version.version_no }}</td>
                  <td class="px-4 py-2.5 text-gray-600">{{ stageLabel(version.stage) }}</td>
                  <td class="px-4 py-2.5 text-gray-500">{{ version.created_at }}</td>
                  <td class="px-4 py-2.5 text-right whitespace-nowrap">
                    <AppButton size="sm" variant="ghost" @click="preview(version)">ดูเอกสาร</AppButton>
                    <AppButton size="sm" variant="ghost" :loading="downloading" @click="downloadOfficial">ดาวน์โหลด</AppButton>
                  </td>
                </tr>
              </tbody>
            </table>
            <p class="px-4 py-2 text-xs text-gray-400">ต้นฉบับถูกเก็บไว้ทุกฉบับและไม่ถูกเขียนทับ</p>
          </div>

          <!--
            ตัวอย่างเอกสาร (NAV §4.2, §4.3)

            **ดึงผ่านด่านไฟล์ที่มีสิทธิ์เสมอ** (`fetchObjectUrl`) ไม่ใช่ลิงก์ตรง —
            ไฟล์อยู่บน private disk และต้องผ่าน `SecureFileController` ทุกครั้ง
            ตามสัญญา INT-01 §1.12 · การฝัง URL ตรงจะทำให้ไฟล์หลุดออกนอกด่าน
          -->
          <div v-if="previewError" role="alert" class="text-sm text-red-700">{{ previewError }}</div>

          <div v-if="previewUrl" class="border border-gray-200 rounded-xl overflow-hidden">
            <div class="flex items-center justify-between px-4 py-2 bg-gray-50 border-b border-gray-200">
              <span class="text-sm text-gray-700">ตัวอย่างฉบับที่ {{ previewVersion?.version_no }}</span>
              <AppButton size="sm" variant="ghost" @click="closePreview">ปิดตัวอย่าง</AppButton>
            </div>
            <iframe
              :src="previewUrl"
              title="ตัวอย่างเอกสาร"
              class="w-full h-[32rem] bg-white"
            />
          </div>
        </div>
      </section>

      <!-- การเดินเรื่อง (INT-08) — ปุ่มที่แสดงมาจากสิทธิ์จริงที่ engine ตัดสิน -->
      <section
        v-if="workflow.started"
        class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden"
      >
        <header class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center gap-3">
          <div class="flex-1">
            <h2 class="font-semibold text-gray-800">การเดินเรื่อง</h2>
            <p class="text-sm text-gray-500 mt-0.5">
              ขั้นปัจจุบัน: {{ workflow.current_step?.name || 'จบกระบวนการแล้ว' }}
            </p>
          </div>
          <AppBadge :variant="workflowVariant">{{ workflowStatusLabel }}</AppBadge>
        </header>

        <div class="p-5 space-y-4">
          <div v-if="workflow.transitions.length" class="space-y-3">
            <AppTextarea v-model="workflowComment" label="ความเห็น" />
            <div class="flex flex-wrap gap-2">
              <AppButton
                v-for="transition in workflow.transitions"
                :key="transition.action"
                :variant="transition.action === 'approve' ? 'primary' : 'outline'"
                :loading="actingOn === transition.action"
                @click="performWorkflow(transition)"
              >
                {{ transition.name }}
              </AppButton>
            </div>
          </div>
          <p v-else class="text-sm text-gray-500">
            ขณะนี้ท่านไม่มีขั้นตอนที่ต้องดำเนินการกับหนังสือฉบับนี้
          </p>

          <div v-if="workflow.history.length" class="border-t border-gray-100 pt-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">ประวัติการดำเนินการ</h3>
            <ul class="space-y-1.5">
              <li v-for="(entry, index) in workflow.history" :key="index" class="text-sm text-gray-600">
                <span class="font-medium text-gray-800">{{ actionLabel(entry.action) }}</span>
                <span v-if="entry.actor"> โดย {{ entry.actor }}</span>
                <span class="text-gray-400"> · {{ entry.at }}</span>
                <span v-if="entry.comment" class="block text-xs text-gray-500">{{ entry.comment }}</span>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <div class="flex flex-wrap justify-end gap-3">
        <AppButton v-if="!readOnly" type="submit" :loading="saving">
          {{ isEdit ? 'บันทึกการแก้ไข' : 'บันทึกฉบับร่าง' }}
        </AppButton>
        <AppButton v-if="canSubmit" variant="secondary" :loading="submitting" @click="submitDocument">
          เสนอเรื่อง
        </AppButton>
      </div>
    </form>
    <DocumentTools v-if="documentRecord && !loading" :key="documentRecord.id" :document="documentRecord" @changed="boot" />
    <RetentionPanel v-if="documentRecord && !loading" :key="`retention-${documentRecord.id}`" :document-id="documentRecord.id" />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import { fetchObjectUrl } from '../../utils/secureFile'
import { useAlertStore } from '../../stores/alert'
import AppButton from '../../components/ui/AppButton.vue'
import AppInput from '../../components/ui/AppInput.vue'
import AppSelect from '../../components/ui/AppSelect.vue'
import AppTextarea from '../../components/ui/AppTextarea.vue'
import AppBadge from '../../components/ui/AppBadge.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import DistributeDocumentModal from '../../components/saraban/DistributeDocumentModal.vue'
import DocumentTools from '../../components/saraban/DocumentTools.vue'
import RetentionPanel from '../../components/saraban/RetentionPanel.vue'

const DIRECTIONS = [
  { value: 'incoming', label: 'หนังสือรับ' },
  { value: 'outgoing', label: 'หนังสือส่ง' },
  { value: 'internal', label: 'หนังสือภายใน' },
]
const CONFIDENTIALITY = [
  { value: 'normal', label: 'ปกติ' },
  { value: 'confidential', label: 'ลับ' },
  { value: 'secret', label: 'ลับมาก' },
  { value: 'top_secret', label: 'ลับที่สุด' },
]
const URGENCY = [
  { value: 'normal', label: 'ปกติ' },
  { value: 'urgent', label: 'ด่วน' },
  { value: 'very_urgent', label: 'ด่วนมาก' },
  { value: 'most_urgent', label: 'ด่วนที่สุด' },
]
const STAGES = {
  original: 'ต้นฉบับ',
  revised: 'ฉบับแก้ไข',
  reviewed: 'ผ่านการตรวจ',
  endorsed: 'เกษียนแล้ว',
  signed: 'ลงนามแล้ว',
  final: 'ฉบับสมบูรณ์',
}

const route = useRoute()
const router = useRouter()
const alertStore = useAlertStore()

const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const submitting = ref(false)
const documentRecord = ref(null)
const options = ref({ document_types: [] })
const orgUnits = ref([])
const errors = reactive({})
const selectedFile = ref(null)
const selectedTemplate = ref('')

const applyTemplate = (type) => {
  /*
   * แม่แบบเป็นโครงตั้งต้น ไม่ใช่การล้างของเดิม — ถ้าผู้ใช้พิมพ์เนื้อเรื่องไว้แล้ว
   * ต้องถามก่อนทับ ไม่งั้นการกดเทียบแม่แบบสองแบบจะทำให้งานที่ร่างไว้หายทันที
   */
  if (form.summary && !window.confirm('เนื้อเรื่องที่พิมพ์ไว้จะถูกแทนที่ด้วยแม่แบบ ต้องการดำเนินการต่อหรือไม่?')) {
    return
  }

  selectedTemplate.value = type
  if (type === 'memo') {
    form.direction = 'internal'
    if (!form.subject) {
      form.subject = 'ขออนุมัติ...'
    }
    form.summary = 'เรียน  ผู้อำนวยการ\n\nด้วย [ระบุความเป็นมา หรือเหตุผลความจำเป็น]\n\nในการนี้ [ระบุวัตถุประสงค์ หรือรายละเอียดการดำเนินงาน]\n\nจึงเรียนมาเพื่อโปรดพิจารณาอนุมัติ'
  } else if (type === 'external') {
    form.direction = 'outgoing'
    if (!form.subject) {
      form.subject = 'ขอความอนุเคราะห์...'
    }
    form.summary = 'เรียน  [ระบุตำแหน่งผู้รับ]\nอ้างถึง  [ถ้ามี]\nสิ่งที่ส่งมาด้วย  [ถ้ามี]\n\nด้วย [ระบุความเป็นมา หรือข้อเท็จจริง]\n\nในการนี้ [ระบุวัตถุประสงค์ หรือสิ่งที่ขอความร่วมมือ]\n\nจึงเรียนมาเพื่อโปรดพิจารณาให้ความอนุเคราะห์\n\nขอแสดงความนับถือ'
    loadExternalDisclaimer()
  }
}

/*
 * ข้อความปฏิเสธความรับผิด ๒ ภาษาสำหรับแนบท้ายอีเมลหนังสือภายนอก (ภาคผนวก ๗)
 * โหลดเมื่อเลือกแม่แบบหนังสือภายนอกเท่านั้น เพราะเกี่ยวกับการส่งออกภายนอกโดยตรง
 */
const externalDisclaimer = ref('')

const loadExternalDisclaimer = async () => {
  if (externalDisclaimer.value) return
  try {
    const { data } = await api.get('/saraban/templates/disclaimer')
    externalDisclaimer.value = data.disclaimer?.formatted || ''
  } catch {
    // ไม่รบกวนผู้ใช้ — เป็นข้อมูลประกอบ ไม่ใช่ขั้นตอนบังคับ
  }
}

const form = reactive({
  document_type_id: '',
  direction: 'internal',
  subject: '',
  summary: '',
  confidentiality: 'normal',
  urgency: 'normal',
  owner_org_unit_id: '',
})

const isEdit = computed(() => Boolean(route.params.id))
const readOnly = computed(() => Boolean(documentRecord.value) && documentRecord.value.status !== 'draft')
const versions = computed(() => documentRecord.value?.versions || [])
const canSubmit = computed(
  () => isEdit.value && documentRecord.value?.status === 'draft' && versions.value.length > 0,
)
const pageTitle = computed(() => {
  if (!isEdit.value) return 'สร้างหนังสือ'

  return readOnly.value ? 'รายละเอียดหนังสือ' : 'แก้ไขหนังสือฉบับร่าง'
})

const stageLabel = (stage) => STAGES[stage] || stage

// ── ตัวอย่างเอกสาร (NAV §4.3) ────────────────────────────────────────────
const previewUrl = ref('')
const previewVersion = ref(null)
const previewError = ref('')

function closePreview() {
  /* คืน object URL ทุกครั้ง — ไม่คืนแล้วเบราว์เซอร์จะถือไฟล์ไว้ในหน่วยความจำจนปิดแท็บ */
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = ''
  previewVersion.value = null
}

async function preview(version) {
  const path = version.pdf_file_path || version.original_file_path

  if (!path) {
    previewError.value = 'ฉบับนี้ไม่มีไฟล์ให้แสดง'

    return
  }

  previewError.value = ''
  closePreview()

  try {
    previewUrl.value = await fetchObjectUrl(`/storage/${path}`)
    previewVersion.value = version
  } catch (e) {
    previewError.value = getErrorMessage(e, 'เปิดตัวอย่างเอกสารไม่สำเร็จ')
  }
}

const downloading = ref(false)

/*
 * ดาวน์โหลดหนังสือพร้อมชื่อไฟล์ทางการตามระเบียบฯ (ภาคผนวก ๗ ข้อ ๔.๓)
 *
 * ชื่อไฟล์มาจาก Content-Disposition ที่ฝั่ง server สร้างให้ ไม่ใช่ประกอบเองที่นี่
 * เพื่อให้ตรงกับกฎการตั้งชื่อไฟล์ราชการชุดเดียวกันเสมอ
 */
async function downloadOfficial() {
  downloading.value = true
  try {
    const res = await api.get(`/saraban/documents/${route.params.id}/download`, { responseType: 'blob' })
    const disposition = res.headers['content-disposition'] || ''
    const match = disposition.match(/filename\*?=(?:UTF-8''|")?([^";]+)/i)
    const filename = match ? decodeURIComponent(match[1].replace(/"$/, '')) : `document-${route.params.id}.pdf`
    const url = URL.createObjectURL(res.data)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
  } catch (e) {
    previewError.value = getErrorMessage(e, 'ดาวน์โหลดหนังสือไม่สำเร็จ')
  } finally {
    downloading.value = false
  }
}

onBeforeUnmount(closePreview)

const onFileChange = (event) => {
  selectedFile.value = event.target.files?.[0] || null
}

const clearErrors = () => Object.keys(errors).forEach((key) => delete errors[key])

const applyValidationErrors = (err) => {
  const bag = err.response?.data?.errors
  if (!bag) return false

  Object.entries(bag).forEach(([field, messages]) => {
    errors[field] = messages[0]
  })

  return true
}

const buildPayload = () => {
  const payload = new FormData()
  Object.entries(form).forEach(([key, value]) => payload.append(key, value ?? ''))
  if (selectedFile.value) payload.append('file', selectedFile.value)

  return payload
}

const boot = async () => {
  loading.value = true
  loadError.value = ''
  try {
    /*
     * หน่วยงานมาจาก endpoint ของสารบรรณเอง ไม่ใช่ `/admin/departments`
     * ซึ่งต้องมีสิทธิ์จัดการโครงสร้างองค์กร — ผู้ที่สร้างหนังสือได้ไม่ควรต้องมีสิทธิ์นั้น
     */
    const { data: reference } = await api.get('/saraban/documents/options')

    options.value = reference
    orgUnits.value = reference.org_units || []

    if (isEdit.value) {
      const { data: existing } = await api.get(`/saraban/documents/${route.params.id}`)
      documentRecord.value = existing
      Object.keys(form).forEach((key) => {
        if (existing[key] !== undefined && existing[key] !== null) form[key] = existing[key]
      })
    }
  } catch (err) {
    loadError.value = getErrorMessage(err, 'โหลดข้อมูลหนังสือไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const save = async () => {
  saving.value = true
  clearErrors()
  try {
    const url = isEdit.value ? `/saraban/documents/${route.params.id}` : '/saraban/documents'
    const { data } = await api.post(url, buildPayload(), {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    alertStore.success(isEdit.value ? 'บันทึกการแก้ไขแล้ว' : 'บันทึกฉบับร่างแล้ว')

    if (!isEdit.value) {
      router.push({ name: 'saraban-document-detail', params: { id: data.id } })

      return
    }

    documentRecord.value = data
    selectedFile.value = null
  } catch (err) {
    if (!applyValidationErrors(err)) {
      alertStore.error(getErrorMessage(err, 'บันทึกไม่สำเร็จ'))
    }
  } finally {
    saving.value = false
  }
}

const submitDocument = async () => {
  submitting.value = true
  try {
    const { data } = await api.post(`/saraban/documents/${route.params.id}/submit`)
    documentRecord.value = { ...documentRecord.value, ...data }
    alertStore.success('เสนอเรื่องเรียบร้อยแล้ว')
  } catch (err) {
    alertStore.error(getErrorMessage(err, 'เสนอเรื่องไม่สำเร็จ'))
  } finally {
    submitting.value = false
  }
}

// ── การเดินเรื่อง (INT-08) ──────────────────────────────────────────────
const WORKFLOW_STATUS = {
  running: { label: 'กำลังดำเนินการ', variant: 'info' },
  completed: { label: 'ลงนามแล้ว', variant: 'success' },
  rejected: { label: 'ไม่เห็นชอบ', variant: 'danger' },
  returned: { label: 'ส่งกลับแก้ไข', variant: 'warning' },
  cancelled: { label: 'ยกเลิก', variant: 'gray' },
}
const ACTIONS = {
  started: 'เริ่มกระบวนการ',
  approve: 'เห็นชอบ',
  reject: 'ไม่เห็นชอบ',
  return: 'ส่งกลับแก้ไข',
  cancel: 'ยกเลิก',
}

const workflow = ref({ started: false, status: null, current_step: null, transitions: [], history: [] })
const workflowComment = ref('')
// INT-13 — กล่องเวียนหนังสือ
const distributing = ref(false)

const onDistributed = async () => {
  distributing.value = false
  await boot()
}

const actingOn = ref(null)

const workflowStatusLabel = computed(() => WORKFLOW_STATUS[workflow.value.status]?.label || '—')
const workflowVariant = computed(() => WORKFLOW_STATUS[workflow.value.status]?.variant || 'gray')
const actionLabel = (action) => ACTIONS[action] || action

const loadWorkflow = async () => {
  if (!isEdit.value) return

  try {
    const { data } = await api.get(`/saraban/documents/${route.params.id}/workflow`)
    workflow.value = data
  } catch {
    // สถานะการเดินเรื่องโหลดไม่ได้ไม่ควรทำให้ทั้งหน้าใช้ไม่ได้ — ส่วนอื่นยังทำงานได้
    workflow.value = { started: false, status: null, current_step: null, transitions: [], history: [] }
  }
}

const performWorkflow = async (transition) => {
  if (transition.requires_comment && !workflowComment.value.trim()) {
    alertStore.error('การดำเนินการนี้ต้องระบุความเห็นประกอบ')

    return
  }

  actingOn.value = transition.action
  try {
    const { data } = await api.post(`/saraban/documents/${route.params.id}/workflow`, {
      action: transition.action,
      comment: workflowComment.value || undefined,
    })

    workflow.value = data
    workflowComment.value = ''
    alertStore.success('บันทึกการดำเนินการแล้ว')

    // สถานะของหนังสืออาจเปลี่ยน (เช่น ได้เลขหนังสือหลังลงนาม) จึงโหลดใหม่
    await boot()
    await loadWorkflow()
  } catch (err) {
    alertStore.error(getErrorMessage(err, 'ดำเนินการไม่สำเร็จ'))
  } finally {
    actingOn.value = null
  }
}

const isSignatureStep = computed(
  () =>
    workflow.value?.current_step?.code === 'signature' ||
    workflow.value?.current_step?.assignee_permission === 'saraban.signature.sign',
)

const openEndorse = () =>
  router.push({ name: 'saraban-document-endorse', params: { id: route.params.id } })

const openWorkspace = () =>
  router.push({ name: 'saraban-document-workspace', params: { id: route.params.id } })

const openSigning = () =>
  router.push({ name: 'saraban-document-sign', params: { id: route.params.id } })

const backToList = () => router.push({ name: 'saraban-documents' })

onMounted(async () => {
  await boot()
  await loadWorkflow()
})
</script>
