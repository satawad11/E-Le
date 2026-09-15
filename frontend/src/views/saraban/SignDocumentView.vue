<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">ลงนามในหนังสือ</h1>
        <p class="text-sm text-gray-500 mt-1">
          วางลายเซ็นบนหน้าเอกสารจริง · คลิกตำแหน่งใดก็ได้บนหน้าใดก็ได้
        </p>
      </div>
      <AppButton variant="ghost" @click="back">กลับสู่หนังสือ</AppButton>
    </div>

    <LoadingState v-if="loading" type="skeleton-form" />
    <ErrorState v-else-if="loadError" :message="loadError" @retry="boot" />

    <template v-else>
      <p v-if="error" role="alert" class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-xl px-4 py-3">
        {{ error }}
      </p>

      <!--
        บริบทของกระบวนการ — ผู้ลงนามต้องรู้ว่าตนอยู่ตรงไหนของเรื่อง
        ก่อนลงนาม ไม่ใช่เห็นแต่หน้ากระดาษเปล่า ๆ
      -->
      <SigningContextPanel :workflow="workflow" :evidences="evidences" :version="documentRecord" />

      <!--
        ขั้นตรวจตัวอย่าง — เห็นไฟล์ที่จะออกมาจริงก่อน จึงจะกดยืนยันได้
        แทนที่พื้นที่วางทั้งหมด เพื่อไม่ให้เผลอแก้ตำแหน่งระหว่างที่ตัวอย่างค้างอยู่
      -->
      <RenderedPreview
        v-if="previewing"
        :document-id="route.params.id"
        :objects="objects"
        confirm-label="ยืนยันลงนาม"
        @back="previewing = false"
        @confirm="confirming = true"
      />

      <div v-else class="grid lg:grid-cols-3 gap-4">
        <!-- แผงเครื่องมือ — เขียนลายเซ็นแล้วคลิกวางลงบนเอกสาร -->
        <section class="space-y-4">
          <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5 space-y-3">
            <h2 class="font-semibold text-gray-800">ลายเซ็นของท่าน</h2>
            <HandwritingCanvas
              :target-width="150"
              :target-height="50"
              label="พื้นที่เขียนลายเซ็น"
              @change="strokes = $event"
            />
            <p v-if="!strokes.length && !hasSignature" class="text-xs text-amber-700 bg-amber-50 rounded-lg p-2 border border-amber-200">
              ✍️ กรุณาใช้เมาส์หรือปากกาวาดลายเซ็นในกรอบด้านบนก่อน จึงจะสามารถกดปุ่มวางลายเซ็นได้
            </p>
            <p v-else-if="strokes.length && !hasSignature && !placing" class="text-xs text-emerald-700 bg-emerald-50 rounded-lg p-2 border border-emerald-200">
              ✓ ลายเซ็นพร้อมแล้ว คลิกปุ่มด้านล่างเพื่อเลือกตำแหน่งวางบนเอกสาร
            </p>
            <AppButton :disabled="!strokes.length || hasSignature" @click="armSignature">
              วางลายเซ็นบนเอกสาร
            </AppButton>
            <p v-if="hasSignature" class="text-xs text-gray-500">
              ลากลายเซ็นบนเอกสารเพื่อย้ายตำแหน่ง · กดกากบาทบนวัตถุเพื่อเอาออก
            </p>
          </div>

          <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5 space-y-3">
            <h2 class="font-semibold text-gray-800">วันที่และข้อความกำกับ</h2>
            <p class="text-xs text-gray-500">
              หนังสือราชการที่ลงนามประกอบด้วยลายเซ็น ชื่อ-ตำแหน่ง และวันที่ ซึ่งเป็นบล็อกเดียวกัน
            </p>
            <AppButton variant="secondary" :disabled="hasDate" @click="armDate">วางวันที่ที่ลงนาม</AppButton>
            <AppInput v-model="noteText" label="ข้อความกำกับ (เช่น ชื่อและตำแหน่ง)" />
            <AppButton variant="secondary" :disabled="!noteText.trim()" @click="armNote">วางข้อความ</AppButton>
          </div>

          <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5 space-y-3">
            <h2 class="font-semibold text-gray-800">ยืนยันการลงนาม</h2>
            <ul class="text-sm text-gray-600 list-disc ms-5 space-y-1">
              <li :class="hasSignature ? 'text-emerald-700' : ''">
                {{ hasSignature ? `วางลายเซ็นแล้วที่หน้า ${signature.page}` : 'ยังไม่ได้วางลายเซ็น' }}
              </li>
              <li>วางเพิ่มเติม {{ extras.length }} รายการ</li>
            </ul>
            <!--
              **ต้องเห็นตัวอย่างก่อนจึงกดลงนามได้** — การลงนามย้อนกลับไม่ได้
              ผู้ลงนามจึงต้องเห็นสิ่งที่ตนกำลังรับรองก่อนเสมอ
            -->
            <AppButton :disabled="!hasSignature" @click="previewing = true">ตรวจตัวอย่างและลงนาม</AppButton>
          </div>
        </section>

        <!-- เอกสารจริง — คลิกวางและลากปรับตำแหน่งได้ (ปิด RISK-30) -->
        <section class="lg:col-span-2 bg-white border border-gray-100 rounded-2xl shadow-sm p-4">
          <PdfWorkspace
            v-if="fileUrl"
            v-model="objects"
            :locked="existing"
            :source="fileUrl"
            :placing="placing"
            editable
            @place="drop"
            @cancel-placing="placing = null"
          />
          <EmptyState
            v-else
            title="หนังสือฉบับนี้ยังไม่มีไฟล์ให้ลงนาม"
            description="ต้องแนบไฟล์ต้นฉบับก่อนจึงจะลงนามได้"
          />
        </section>
      </div>

      <!--
        ตัวอย่างก่อนยืนยัน — แสดงสิ่งที่วางไว้เป็นรายการอ่านได้
        ไม่ใช่แค่ปุ่มยืนยันเปล่า ๆ
      -->
      <ConfirmOperation
        v-if="confirming"
        title="ลงนามในหนังสือ"
        :description="confirmationText"
        :action="submit"
        @close="confirming = false"
        @done="onSigned"
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
import AppButton from '../../components/ui/AppButton.vue'
import AppInput from '../../components/ui/AppInput.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import HandwritingCanvas from '../../components/ui/HandwritingCanvas.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import ConfirmOperation from '../../components/saraban/ConfirmOperation.vue'
import PdfWorkspace from '../../components/saraban/PdfWorkspace.vue'
import RenderedPreview from '../../components/saraban/RenderedPreview.vue'
import SigningContextPanel from '../../components/saraban/SigningContextPanel.vue'

const route = useRoute()
const router = useRouter()
const alertStore = useAlertStore()

const documentRecord = ref(null)
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
const error = ref('')
const previewing = ref(false)
const confirming = ref(false)

const signature = computed(() => objects.value.find((o) => o.type === 'signature'))
const hasSignature = computed(() => signature.value !== undefined)
const hasDate = computed(() => objects.value.some((o) => o.type === 'date'))
const extras = computed(() => objects.value.filter((o) => o.type !== 'signature'))

const confirmationText = computed(() => {
  const parts = [
    `ลายเซ็นที่หน้า ${signature.value?.page} ตำแหน่ง (${Math.round(signature.value?.x)}, ${Math.round(signature.value?.y)})`,
    ...extras.value.map((o) => `${o.type === 'date' ? 'วันที่' : 'ข้อความ'} "${o.payload?.text}" ที่หน้า ${o.page}`),
  ]

  return `${parts.join(' · ')} — หลังลงนามแล้วแก้ไขไม่ได้ และระบบจะบันทึกหลักฐานผู้ลงนาม เวลา และไฟล์ฉบับที่ลงนาม`
})

/*
 * เตรียมเครื่องมือแล้วรอให้ผู้ลงนามคลิกตำแหน่งเอง — ไม่วางที่พิกัดตายตัว
 *
 * แบบเดิมวางไว้ที่มุมขวาล่างของ **หน้า 1** เสมอ ซึ่งใช้ไม่ได้กับหนังสือที่ที่
 * ลงนามอยู่หน้าอื่น หรือแบบฟอร์มที่มีช่องลงนามอยู่ตำแหน่งอื่น · ผู้ลงนามคลิก
 * ตรงช่องลงนามจริงบนหน้าจริงได้เลย ซึ่งตรงกับวิธีลงนามบนกระดาษ
 */
const armSignature = () => {
  placing.value = { type: 'signature', label: 'ลายเซ็น', width: 150, height: 50 }
}

const armDate = () => {
  placing.value = { type: 'date', label: 'วันที่', width: 150, height: 20 }
}

const armNote = () => {
  placing.value = { type: 'note', label: 'ข้อความกำกับ', width: 200, height: 20 }
}

const payloadFor = (type) => {
  if (type === 'signature') return { strokes: strokes.value, line_width: 1.2 }
  if (type === 'date') {
    return { text: new Date().toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' }) }
  }

  return { text: noteText.value.trim() }
}

/** วางวัตถุที่ตำแหน่งที่คลิก แล้วปลดเครื่องมือเพื่อไม่ให้วางซ้ำโดยไม่ตั้งใจ */
const drop = ({ page, x, y }) => {
  const tool = placing.value
  if (!tool) return

  objects.value = [...objects.value, {
    page, x, y, width: tool.width, height: tool.height, type: tool.type, payload: payloadFor(tool.type),
  }]

  if (tool.type === 'note') noteText.value = ''
  placing.value = null
}

/**
 * ดึง **เอกสารตามที่เป็นอยู่จริง** ไม่ใช่ไฟล์ดิบ
 *
 * ใช้ endpoint เดียวกับหน้าประทับตรา ซึ่งคืนเวอร์ชันปัจจุบัน **พร้อมวัตถุที่วางไว้
 * แล้ว** (ตราประทับรับ เกษียนที่ยังไม่ถูกเรนเดอร์)
 *
 * สำคัญเพราะตอนลงนาม `SarabanPdfRenderer` วาด **วัตถุทั้งหมด** บนเวอร์ชันนั้น
 * ลงไปในฉบับที่ลงนาม — ถ้าหน้านี้แสดงแต่ไฟล์ดิบ ผู้ลงนามจะรับรองเอกสารที่หน้าตา
 * ไม่ตรงกับที่จะออกมาจริง ซึ่งเป็นการรับรองสิ่งที่ตนไม่ได้เห็น
 *
 * ดึงบริบทของกระบวนการมาพร้อมกัน แต่ **ไม่ให้ล้มทั้งหน้า** ถ้าดึงไม่ได้ —
 * บริบทช่วยตัดสินใจ ส่วนการลงนามเป็นสิทธิ์ที่ policy ฝั่ง server ตัดสินอยู่แล้ว
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

    documentRecord.value = annotations.data.version
    existing.value = annotations.data.annotations || []

    const [workflowResult, evidenceResult] = context
    workflow.value = workflowResult.status === 'fulfilled' ? workflowResult.value.data : null
    evidences.value = evidenceResult.status === 'fulfilled' ? evidenceResult.value.data.evidences || [] : []

    if (!annotations.data.version?.file_path) return

    fileUrl.value = await fetchObjectUrl(`/storage/${annotations.data.version.file_path}`)
  } catch (e) {
    loadError.value = getErrorMessage(e, 'เปิดหนังสือเพื่อลงนามไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

/*
 * ส่งลายเซ็นเป็นวัตถุหลัก และวันที่/ข้อความเป็น `objects` ในคำขอเดียว —
 * ทั้งหมดถูกวางลงเวอร์ชันเดียวกันแล้วเรนเดอร์ครั้งเดียวที่ฝั่ง server
 */
const submit = () =>
  api.post(`/saraban/documents/${route.params.id}/sign`, {
    page: signature.value.page,
    x: signature.value.x,
    y: signature.value.y,
    width: signature.value.width,
    height: signature.value.height,
    strokes: signature.value.payload.strokes,
    objects: extras.value.map((o) => ({
      page: o.page, type: o.type, x: o.x, y: o.y, width: o.width, height: o.height, payload: o.payload,
    })),
  })

const onSigned = () => {
  confirming.value = false
  alertStore.success('ลงนามเรียบร้อยแล้ว')
  router.push({ name: 'saraban-document-detail', params: { id: route.params.id } })
}

const back = async () => {
  try {
    await router.push({ name: 'saraban-document-detail', params: { id: route.params.id } })
  } catch {
    await router.push(`/saraban/documents/${route.params.id}`)
  }
}

onBeforeUnmount(() => {
  try {
    if (fileUrl.value) {
      URL.revokeObjectURL(fileUrl.value)
      fileUrl.value = ''
    }
  } catch {}
})
onMounted(boot)
</script>
