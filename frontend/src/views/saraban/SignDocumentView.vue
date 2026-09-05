<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">ลงนามในหนังสือ</h1>
        <p class="text-sm text-gray-500 mt-1">
          วางลายเซ็นบนหน้าเอกสารจริง · ตำแหน่งที่วางคือตำแหน่งที่จะออกมาบนหนังสือ
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

      <div class="grid lg:grid-cols-3 gap-4">
        <!-- แผงเครื่องมือ — เขียนลายเซ็นแล้วกดวางลงบนเอกสาร -->
        <section class="space-y-4">
          <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5 space-y-3">
            <h2 class="font-semibold text-gray-800">ลายเซ็นของท่าน</h2>
            <HandwritingCanvas
              :target-width="150"
              :target-height="50"
              label="พื้นที่เขียนลายเซ็น"
              @change="strokes = $event"
            />
            <AppButton :disabled="!strokes.length || hasSignature" @click="placeSignature">
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
            <AppButton variant="secondary" :disabled="hasDate" @click="placeDate">วางวันที่ที่ลงนาม</AppButton>
            <AppInput v-model="noteText" label="ข้อความกำกับ (เช่น ชื่อและตำแหน่ง)" />
            <AppButton variant="secondary" :disabled="!noteText.trim()" @click="placeNote">วางข้อความ</AppButton>
          </div>

          <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5 space-y-3">
            <h2 class="font-semibold text-gray-800">ยืนยันการลงนาม</h2>
            <ul class="text-sm text-gray-600 list-disc ms-5 space-y-1">
              <li :class="hasSignature ? 'text-emerald-700' : ''">
                {{ hasSignature ? 'วางลายเซ็นแล้ว' : 'ยังไม่ได้วางลายเซ็น' }}
              </li>
              <li>วางเพิ่มเติม {{ extras.length }} รายการ</li>
            </ul>
            <!--
              **ต้องเห็นตัวอย่างก่อนจึงกดลงนามได้** — การลงนามย้อนกลับไม่ได้
              ผู้ลงนามจึงต้องเห็นสิ่งที่ตนกำลังรับรองก่อนเสมอ
            -->
            <AppButton :disabled="!hasSignature" @click="confirming = true">ตรวจและลงนาม</AppButton>
          </div>
        </section>

        <!-- เอกสารจริง — ลากวางตำแหน่งได้ (ปิด RISK-30) -->
        <section class="lg:col-span-2 bg-white border border-gray-100 rounded-2xl shadow-sm p-4">
          <PdfWorkspace v-if="fileUrl" v-model="objects" :source="fileUrl" editable />
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

const route = useRoute()
const router = useRouter()
const alertStore = useAlertStore()

const documentRecord = ref(null)
const fileUrl = ref('')
const objects = ref([])
const strokes = ref([])
const noteText = ref('')
const loading = ref(true)
const loadError = ref('')
const error = ref('')
const confirming = ref(false)

const hasSignature = computed(() => objects.value.some((o) => o.type === 'signature'))
const hasDate = computed(() => objects.value.some((o) => o.type === 'date'))
const extras = computed(() => objects.value.filter((o) => o.type !== 'signature'))

const confirmationText = computed(() => {
  const signature = objects.value.find((o) => o.type === 'signature')
  const parts = [
    `ลายเซ็นที่หน้า ${signature?.page} ตำแหน่ง (${Math.round(signature?.x)}, ${Math.round(signature?.y)})`,
    ...extras.value.map((o) => `${o.type === 'date' ? 'วันที่' : 'ข้อความ'} "${o.payload?.text}" ที่หน้า ${o.page}`),
  ]

  return `${parts.join(' · ')} — หลังลงนามแล้วแก้ไขไม่ได้ และระบบจะบันทึกหลักฐานผู้ลงนาม เวลา และไฟล์ฉบับที่ลงนาม`
})

/*
 * วางวัตถุที่ตำแหน่งตั้งต้นมุมขวาล่างของหน้า ซึ่งเป็นที่ลงนามตามแบบหนังสือราชการ
 * แล้วให้ผู้ลงนามลากปรับเอง — เริ่มที่ตำแหน่งที่ถูกต้องส่วนใหญ่ ดีกว่าเริ่มกลางหน้า
 */
const placeSignature = () => {
  objects.value = [...objects.value, {
    page: 1, x: 380, y: 620, width: 150, height: 50,
    type: 'signature',
    payload: { strokes: strokes.value, line_width: 1.2 },
  }]
}

const placeDate = () => {
  const today = new Date().toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' })

  objects.value = [...objects.value, {
    page: 1, x: 380, y: 580, width: 150, height: 20,
    type: 'date',
    payload: { text: today },
  }]
}

const placeNote = () => {
  objects.value = [...objects.value, {
    page: 1, x: 380, y: 600, width: 200, height: 20,
    type: 'note',
    payload: { text: noteText.value.trim() },
  }]
  noteText.value = ''
}

async function boot() {
  loading.value = true
  loadError.value = ''

  try {
    const { data } = await api.get(`/saraban/documents/${route.params.id}`)
    documentRecord.value = data

    const current = data.current_version || (data.versions || []).at(-1)
    const path = current?.pdf_file_path || current?.original_file_path

    if (path) fileUrl.value = await fetchObjectUrl(`/storage/${path}`)
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
const submit = () => {
  const signature = objects.value.find((o) => o.type === 'signature')

  return api.post(`/saraban/documents/${route.params.id}/sign`, {
    page: signature.page,
    x: signature.x,
    y: signature.y,
    width: signature.width,
    height: signature.height,
    strokes: signature.payload.strokes,
    objects: extras.value.map((o) => ({
      page: o.page, type: o.type, x: o.x, y: o.y, width: o.width, height: o.height, payload: o.payload,
    })),
  })
}

const onSigned = () => {
  confirming.value = false
  alertStore.success('ลงนามเรียบร้อยแล้ว')
  router.push({ name: 'saraban-document-detail', params: { id: route.params.id } })
}

const back = () => router.push({ name: 'saraban-document-detail', params: { id: route.params.id } })

onBeforeUnmount(() => { if (fileUrl.value) URL.revokeObjectURL(fileUrl.value) })
onMounted(boot)
</script>
