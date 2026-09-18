<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <!-- Breadcrumb Navigation -->
    <AppBreadcrumb
      :items="[
        { title: 'งานสารบรรณ', to: '/saraban' },
        { title: 'คิวงานสารบรรณ', to: '/saraban/queue' },
        { title: version?.subject || 'รายละเอียดหนังสือ', to: `/saraban/documents/${route.params.id}` },
        { title: 'เกษียนหนังสือ' }
      ]"
      :backTo="`/saraban/documents/${route.params.id}`"
    />

    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">เกษียนหนังสือ</h1>
        <p class="text-sm text-gray-500 mt-1">
          เขียนความเห็นลงบนหน้าเอกสารจริง · คลิกตำแหน่งใดก็ได้บนหน้าใดก็ได้
        </p>
      </div>
      <AppButton variant="ghost" @click="back">กลับสู่หนังสือ</AppButton>
    </div>

    <LoadingState v-if="loading" type="skeleton-form" />
    <ErrorState v-else-if="loadError" :message="loadError" @retry="boot" />

    <template v-else>
      <!--
        **การเกษียนไม่ใช่การลงนาม** (SoT §15) — ต้องบอกไว้บนหน้าจอ ไม่ใช่รู้กัน
        เฉพาะในโค้ด เพราะหน้าตาการทำงานเหมือนการลงนามมาก
      -->
      <p class="text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3">
        การเกษียนเป็นการให้ความเห็นบนหนังสือ ไม่ใช่การลงนามรับรอง
        จึงไม่สร้างหลักฐานการลงนามและไม่ทำให้หนังสือเปลี่ยนสถานะ
      </p>

      <SigningContextPanel :workflow="workflow" :evidences="evidences" :version="version" />

      <EmptyState
        v-if="!fileUrl"
        title="หนังสือฉบับนี้ยังไม่มีไฟล์ให้เกษียน"
        description="แนบไฟล์หนังสือก่อนจึงจะเกษียนได้"
      />

      <!--
        ขั้นตรวจตัวอย่าง — ไฟล์ที่เห็นสร้างจากตัวเรนเดอร์ตัวเดียวกับของจริง
        แทนที่พื้นที่วาง เพื่อไม่ให้เผลอแก้ตำแหน่งขณะที่ตัวอย่างค้างอยู่
      -->
      <RenderedPreview
        v-else-if="previewing"
        :document-id="route.params.id"
        :objects="objects"
        confirm-label="ยืนยันบันทึกเกษียน"
        @back="previewing = false"
        @confirm="confirming = true"
      />

      <div v-else class="grid lg:grid-cols-3 gap-4">
        <section class="space-y-4">
          <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5 space-y-3">
            <h2 class="font-semibold text-gray-800">ลายมือ</h2>
            <HandwritingCanvas
              :target-width="200"
              :target-height="60"
              label="พื้นที่เขียนลายมือ"
              @change="strokes = $event"
            />
            <AppButton :disabled="!strokes.length" @click="arm('handwriting')">วางลายมือบนเอกสาร</AppButton>
          </div>

          <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5 space-y-3">
            <h2 class="font-semibold text-gray-800">ข้อความและเครื่องหมาย</h2>

            <!-- ข้อความเกษียนด่วน ๔ ฝ่ายอาชีวะสำหรับผู้บริหาร -->
            <div class="space-y-1.5">
              <label class="text-xs font-medium text-gray-500">ข้อความเกษียนด่วน (สำหรับ ผอ. / รอง ผอ. ๔ ฝ่าย):</label>
              <div class="flex flex-wrap gap-1.5 max-h-44 overflow-y-auto p-1 bg-gray-50 rounded-xl border border-gray-100">
                <button
                  v-for="(preset, idx) in VOCATIONAL_PRESETS"
                  :key="idx"
                  type="button"
                  class="text-xs px-2.5 py-1.5 rounded-lg border border-primary-200 bg-white text-primary-800 hover:bg-primary-50 hover:border-primary-300 transition-colors text-left shadow-xs"
                  @click="applyPreset(preset.text)"
                >
                  {{ preset.label }}
                </button>
              </div>
            </div>

            <AppTextarea v-model="noteText" label="ข้อความเกษียน" :rows="3" />
            <AppButton variant="secondary" :disabled="!noteText.trim()" @click="arm('note')">วางข้อความ</AppButton>
            <AppButton variant="secondary" @click="arm('checkmark')">วางเครื่องหมายถูก</AppButton>
            <AppButton variant="secondary" @click="arm('date')">วางวันที่</AppButton>
          </div>

          <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5 space-y-3">
            <h2 class="font-semibold text-gray-800">บันทึกการเกษียน</h2>
            <ul v-if="objects.length" class="text-sm text-gray-600 list-disc ms-5 space-y-1">
              <li v-for="(item, index) in objects" :key="index">
                {{ LABELS[item.type] }} ที่หน้า {{ item.page }}
                <span v-if="item.payload?.text">— “{{ item.payload.text }}”</span>
              </li>
            </ul>
            <p v-else class="text-sm text-gray-500">ยังไม่ได้วางสิ่งใดบนเอกสาร</p>
            <AppButton :disabled="!objects.length" @click="previewing = true">ตรวจตัวอย่างและบันทึกเกษียน</AppButton>
          </div>
        </section>

        <section class="lg:col-span-2 bg-white border border-gray-100 rounded-2xl shadow-sm p-4">
          <PdfWorkspace
            v-model="objects"
            :locked="existing"
            :source="fileUrl"
            :placing="placing"
            editable
            @place="drop"
            @cancel-placing="placing = null"
          />
        </section>
      </div>

      <ConfirmOperation
        v-if="confirming"
        title="บันทึกเกษียน"
        :description="confirmationText"
        :action="submit"
        @close="confirming = false"
        @done="onEndorsed"
      />
    </template>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { getErrorMessage } from '../../utils/errorHandler'
import { fetchObjectUrl } from '../../utils/secureFile'
import AppBreadcrumb from '../../components/ui/AppBreadcrumb.vue'
import AppButton from '../../components/ui/AppButton.vue'
import AppTextarea from '../../components/ui/AppTextarea.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import HandwritingCanvas from '../../components/ui/HandwritingCanvas.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import ConfirmOperation from '../../components/saraban/ConfirmOperation.vue'
import PdfWorkspace from '../../components/saraban/PdfWorkspace.vue'
import RenderedPreview from '../../components/saraban/RenderedPreview.vue'
import SigningContextPanel from '../../components/saraban/SigningContextPanel.vue'

/**
 * หน้าเกษียนหนังสือ (SoT §15 · NAV §4.7)
 *
 * ที่มา: เดิมการเกษียนทำผ่านช่องกรอกเลขหน้า/แนวนอน/แนวตั้งในแผงเครื่องมือของ
 * หน้ารายละเอียด — ผู้เกษียนต้องเดาพิกัดเป็นตัวเลขโดยไม่เห็นเอกสาร ซึ่งไม่มีทาง
 * วางตรงที่ตั้งใจได้ · หน้านี้ใช้พื้นที่ทำงานเดียวกับการลงนาม คือเห็นเอกสารจริง
 * แล้วคลิกวางตรงที่ต้องการ
 */
const LABELS = {
  handwriting: 'ลายมือ',
  note: 'ข้อความ',
  checkmark: 'เครื่องหมายถูก',
  date: 'วันที่',
}

const SIZES = {
  handwriting: { width: 200, height: 60 },
  note: { width: 240, height: 40 },
  checkmark: { width: 14, height: 14 },
  date: { width: 150, height: 20 },
}

/*
 * ชุดข้อความเกษียนด่วน ๔ ฝ่าย — ค่าตั้งต้นสำรองไว้ใช้ทันทีระหว่างรอ API
 * (`GET /saraban/templates`) เพื่อไม่ให้ปุ่มว่างเปล่าหากโหลดไม่ทัน/ล้มเหลว
 */
const VOCATIONAL_PRESETS = ref([
  { label: 'ทราบ / ดำเนินการตามระเบียบ', text: 'ทราบ ดำเนินการตามระเบียบ' },
  { label: 'อนุมัติ ตามเสนอ', text: 'อนุมัติ ตามเสนอ' },
  { label: 'มอบ รอง ผอ. ฝ่ายบริหารทรัพยากร', text: 'มอบ รองผู้อำนวยการฝ่ายบริหารทรัพยากร ดำเนินการต่อไป' },
  { label: 'มอบ รอง ผอ. ฝ่ายวิชาการ', text: 'มอบ รองผู้อำนวยการฝ่ายวิชาการ ดำเนินการต่อไป' },
  { label: 'มอบ รอง ผอ. ฝ่ายพัฒนากิจการนักเรียนฯ', text: 'มอบ รองผู้อำนวยการฝ่ายพัฒนากิจการนักเรียน นักศึกษา ดำเนินการต่อไป' },
  { label: 'มอบ รอง ผอ. ฝ่ายแผนงานและความร่วมมือ', text: 'มอบ รองผู้อำนวยการฝ่ายแผนงานและความร่วมมือ ดำเนินการต่อไป' },
  { label: 'แจ้งเวียนบุคลากรเพื่อทราบ', text: 'แจ้งเวียนบุคลากรทุกท่านเพื่อทราบและถือปฏิบัติ' },
  { label: 'ขอข้อมูลเพิ่มเติม', text: 'ขอข้อมูลและรายละเอียดเพิ่มเติมเพื่อประกอบการพิจารณา' },
])

const loadEndorsementPresets = async () => {
  try {
    const { data } = await api.get('/saraban/templates')
    if (Array.isArray(data.endorsement_presets) && data.endorsement_presets.length) {
      VOCATIONAL_PRESETS.value = data.endorsement_presets
    }
  } catch {
    // เงียบไว้ — ใช้ค่าตั้งต้นสำรองต่อได้ ไม่ต้องรบกวนผู้ใช้
  }
}

const route = useRoute()
const router = useRouter()
const alertStore = useAlertStore()

const version = ref(null)
const existing = ref([])
const workflow = ref(null)
const evidences = ref([])
const fileUrl = ref('')
const objects = ref([])
const strokes = ref([])
const noteText = ref('')
const placing = ref(null)
const loading = ref(true)
const loadError = ref('')
const previewing = ref(false)
const confirming = ref(false)

const confirmationText = computed(
  () =>
    `${objects.value
      .map((item) => `${LABELS[item.type]}ที่หน้า ${item.page}`)
      .join(' · ')} — ระบบจะสร้างหนังสือฉบับใหม่ที่มีรอยเกษียนนี้ โดยฉบับเดิมยังคงอยู่`,
)

const arm = (type) => {
  placing.value = { type, label: LABELS[type], ...SIZES[type] }
}

const applyPreset = (text) => {
  noteText.value = text
  arm('note')
}

const payloadFor = (type) => {
  if (type === 'handwriting') return { strokes: strokes.value, line_width: 1.2 }
  if (type === 'note') return { text: noteText.value.trim() }
  if (type === 'date') {
    return { text: new Date().toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' }) }
  }

  return null
}

const drop = ({ page, x, y }) => {
  const tool = placing.value
  if (!tool) return

  objects.value = [...objects.value, {
    page, x, y, width: tool.width, height: tool.height, type: tool.type, payload: payloadFor(tool.type),
  }]

  if (tool.type === 'note') noteText.value = ''
  placing.value = null
}

const releaseFile = () => {
  if (fileUrl.value) {
    URL.revokeObjectURL(fileUrl.value)
    fileUrl.value = ''
  }
}

/*
 * ดึงเอกสารพร้อมวัตถุที่วางไว้แล้ว ด้วยเหตุผลเดียวกับหน้าลงนาม — ตัวเรนเดอร์
 * วาดวัตถุทั้งหมดบนเวอร์ชันนั้นลงในฉบับใหม่ ผู้เกษียนจึงต้องเห็นของเดิมด้วย
 */
async function boot() {
  loading.value = true
  loadError.value = ''

  try {
    const [annotations, context] = await Promise.all([
      api.get(`/saraban/documents/${route.params.id}/annotations`),
      Promise.allSettled([
        api.get(`/saraban/documents/${route.params.id}/workflow`),
        api.get(`/saraban/documents/${route.params.id}/signatures`),
      ]),
    ])

    version.value = annotations.data.version
    existing.value = annotations.data.annotations || []

    const [workflowResult, evidenceResult] = context
    workflow.value = workflowResult.status === 'fulfilled' ? workflowResult.value.data : null
    evidences.value = evidenceResult.status === 'fulfilled' ? evidenceResult.value.data.evidences || [] : []

    releaseFile()

    if (!annotations.data.version?.file_path) return

    fileUrl.value = await fetchObjectUrl(`/storage/${annotations.data.version.file_path}`)
  } catch (err) {
    loadError.value = getErrorMessage(err, 'เปิดหนังสือเพื่อเกษียนไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const submit = () =>
  api.post(`/saraban/documents/${route.params.id}/endorse`, {
    objects: objects.value.map((item) => ({
      page: item.page,
      type: item.type,
      x: item.x,
      y: item.y,
      width: item.width,
      height: item.height,
      payload: item.payload,
    })),
  })

const onEndorsed = async () => {
  confirming.value = false
  alertStore.success('บันทึกเกษียนเรียบร้อยแล้ว')
  try {
    await router.push({ name: 'saraban-document-detail', params: { id: route.params.id } })
  } catch {
    await router.push(`/saraban/documents/${route.params.id}`)
  }
}

const back = async () => {
  try {
    await router.push({ name: 'saraban-document-detail', params: { id: route.params.id } })
  } catch {
    await router.push(`/saraban/documents/${route.params.id}`)
  }
}

onBeforeUnmount(() => {
  // คืนไฟล์ที่โหลดไว้ตอนออกจากหน้าจอ — ล้มแล้วไม่มีที่ให้แจ้ง หน้าจอกำลังจะหายไป
  try { releaseFile() } catch { /* ปล่อยให้ตัวเก็บขยะจัดการ */ }
})
onMounted(() => {
  boot()
  loadEndorsementPresets()
})
</script>
