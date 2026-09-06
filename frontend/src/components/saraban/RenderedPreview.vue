<template>
  <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
    <header class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center gap-3">
      <div class="flex-1 min-w-0">
        <h2 class="font-semibold text-gray-800">ตัวอย่างฉบับจริง</h2>
        <p class="text-sm text-gray-500 mt-0.5">
          ไฟล์นี้สร้างที่เครื่องแม่ข่ายด้วยตัวเดียวกับฉบับจริง — สิ่งที่เห็นคือสิ่งที่จะออกมา
        </p>
      </div>
      <AppBadge variant="gray">ยังไม่บันทึก</AppBadge>
    </header>

    <div class="p-5 space-y-4">
      <LoadingState v-if="loading" type="skeleton-card" />
      <ErrorState v-else-if="error" :message="error" @retry="load" />
      <PdfWorkspace v-else-if="url" :source="url" />

      <div class="flex flex-wrap justify-end gap-3">
        <AppButton variant="ghost" @click="$emit('back')">กลับไปแก้ตำแหน่ง</AppButton>
        <AppButton :disabled="loading || !!error" @click="$emit('confirm')">{{ confirmLabel }}</AppButton>
      </div>
    </div>
  </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import AppBadge from '../ui/AppBadge.vue'
import AppButton from '../ui/AppButton.vue'
import ErrorState from '../ui/ErrorState.vue'
import LoadingState from '../ui/LoadingState.vue'
import PdfWorkspace from './PdfWorkspace.vue'

/**
 * ตัวอย่างฉบับจริงก่อนยืนยัน (SoT §14)
 *
 * **ทำไมไม่ใช้ภาพจากพื้นที่วาง:** พื้นที่วางแสดงเพียงกล่องทับหน้า PDF ตำแหน่งตรง
 * แต่ลายเส้น ฟอนต์ไทย และการตัดบรรทัดเกิดที่ฝั่ง server เท่านั้น · ผู้ลงนามรับรอง
 * สิ่งที่ตนเห็น การให้ดูภาพที่ "ใกล้เคียง" ผลจริงจึงไม่พอสำหรับการกระทำที่ย้อนกลับไม่ได้
 *
 * endpoint ที่เรียก **ไม่บันทึกอะไรเลย** — ไม่สร้างเวอร์ชัน ไม่บันทึกวัตถุ
 * การกดดูตัวอย่างจึงไม่ทิ้งร่องรอยบนหนังสือถ้าผู้ใช้เปลี่ยนใจ
 */
const props = defineProps({
  documentId: { type: [String, Number], required: true },
  objects: { type: Array, default: () => [] },
  confirmLabel: { type: String, default: 'ยืนยัน' },
})

defineEmits(['back', 'confirm'])

const url = ref('')
const loading = ref(true)
const error = ref('')

const release = () => {
  if (url.value) {
    URL.revokeObjectURL(url.value)
    url.value = ''
  }
}

async function load() {
  loading.value = true
  error.value = ''

  try {
    const response = await api.post(
      `/saraban/documents/${props.documentId}/annotations/preview`,
      {
        objects: props.objects.map((item) => ({
          page: item.page,
          type: item.type,
          x: item.x,
          y: item.y,
          width: item.width,
          height: item.height,
          payload: item.payload,
        })),
      },
      { responseType: 'blob' },
    )

    release()
    url.value = URL.createObjectURL(response.data)
  } catch (err) {
    /*
     * คำตอบที่ผิดพลาดกลับมาเป็น blob เพราะขอ responseType ไว้ ข้อความจาก server
     * จึงอ่านไม่ได้ตรง ๆ — แปลตามสถานะแทน ดีกว่าขึ้น "ลองใหม่" ลอย ๆ
     */
    const status = err?.response?.status
    const fallback = {
      403: 'ท่านไม่มีสิทธิ์วางวัตถุบนหนังสือฉบับนี้',
      422: 'หนังสือฉบับนี้ยังไม่มีไฟล์ให้ดูตัวอย่าง',
    }[status]

    error.value = fallback || getErrorMessage(err, 'สร้างตัวอย่างฉบับจริงไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

onMounted(load)
onBeforeUnmount(release)
</script>
