<template>
  <div class="space-y-3">
    <!-- แถบเครื่องมือ — ใช้ปุ่มและ badge ของระบบเดิมทั้งหมด -->
    <div class="flex flex-wrap items-center gap-2">
      <AppButton size="sm" variant="outline" :disabled="page <= 1" @click="page--">หน้าก่อน</AppButton>
      <AppBadge variant="gray">หน้า {{ page }} / {{ pageCount || '—' }}</AppBadge>
      <AppButton size="sm" variant="outline" :disabled="page >= pageCount" @click="page++">หน้าถัดไป</AppButton>

      <span class="w-px h-6 bg-gray-200 mx-1" aria-hidden="true"></span>

      <AppButton size="sm" variant="ghost" :disabled="zoom <= 0.5" @click="zoom = round(zoom - 0.25)">
        ย่อ
      </AppButton>
      <AppBadge variant="gray">{{ Math.round(zoom * 100) }}%</AppBadge>
      <AppButton size="sm" variant="ghost" :disabled="zoom >= 3" @click="zoom = round(zoom + 0.25)">
        ขยาย
      </AppButton>
    </div>

    <p v-if="editable" class="text-xs text-gray-500">
      ลากวัตถุเพื่อย้ายตำแหน่ง · ตำแหน่งที่บันทึกเป็นพิกัดบนหน้ากระดาษจริง
      จึงออกมาตรงกันทุกเครื่องไม่ว่าจะย่อขยายเท่าใด
    </p>

    <div class="overflow-auto border border-gray-200 rounded-xl bg-gray-100 p-4">
      <div
        ref="stage"
        class="relative mx-auto bg-white shadow"
        :style="{ width: `${displayWidth}px`, height: `${displayHeight}px` }"
      >
        <canvas ref="canvas" class="block w-full h-full" />

        <!-- วัตถุที่วางไว้ — ตำแหน่งบนจอคำนวณจากพิกัด PDF point คูณด้วยระดับซูม -->
        <div
          v-for="(item, index) in pageAnnotations"
          :key="item.key"
          class="absolute border-2 rounded"
          :class="[
            item.type === 'stamp' ? 'border-[#800000] bg-[#800000]/5' : 'border-gray-400 bg-white/70',
            editable ? 'cursor-move' : 'cursor-default',
          ]"
          :style="styleFor(item)"
          :aria-label="`${labelFor(item)} หน้า ${item.page}`"
          role="group"
          @pointerdown="startDrag($event, index)"
        >
          <div class="text-[10px] leading-tight p-1 text-gray-700 select-none">
            <strong v-if="item.type === 'stamp'" class="text-[#800000] block">
              {{ item.payload?.title || 'รับหนังสือ' }}
            </strong>
            <span v-if="item.type === 'stamp'" class="block">
              เลขรับ {{ item.payload?.register_number || '—' }}
            </span>
            <span v-else>{{ item.payload?.text || labelFor(item) }}</span>
          </div>

          <button
            v-if="editable"
            type="button"
            class="absolute -top-2 -right-2 w-5 h-5 rounded-full bg-white border border-gray-300 text-xs text-gray-600 leading-none"
            :aria-label="`ลบ${labelFor(item)}`"
            @pointerdown.stop
            @click.stop="remove(item)"
          >
            ×
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import AppBadge from '../ui/AppBadge.vue'
import AppButton from '../ui/AppButton.vue'

/**
 * PDF Workspace (SoT §14 · INT-10)
 *
 * **พิกัดที่ส่งออกเป็น PDF point วัดจากมุมซ้ายบนของหน้า ไม่ใช่พิกัดบนจอ**
 * เพราะพิกัดบนจอขึ้นกับระดับซูมและขนาดจอของผู้วาง — วางที่เครื่องหนึ่งแล้ว
 * เรนเดอร์ออกมาเพี้ยนที่อีกเครื่องหนึ่ง · หน่วยเดียวกับที่ `CoordinateConverter`
 * และ `SarabanPdfRenderer` ฝั่ง server ใช้ จึงไม่ต้องแปลงอีกตอนเรนเดอร์
 *
 * component นี้ **ไม่ส่งภาพใด ๆ กลับไปที่ server** — ส่งเฉพาะพิกัดและเนื้อหา
 * ภาพที่เห็นบนจอเป็นเพียงตัวช่วยวาง ไม่ใช่ผลลัพธ์ (SoT §14 ห้ามใช้ภาพจาก
 * เบราว์เซอร์เป็น Final PDF)
 */
const props = defineProps({
  source: { type: [String, Object], default: null },
  modelValue: { type: Array, default: () => [] },
  editable: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const canvas = ref(null)
const stage = ref(null)
const page = ref(1)
const zoom = ref(1)
const pageCount = ref(0)
const pageWidth = ref(595) // A4 เป็นค่าเริ่มต้นจนกว่าจะอ่านหน้าจริงได้
const pageHeight = ref(842)

let pdfDocument = null
let renderTask = null
let dragging = null

const round = (value) => Math.round(value * 100) / 100
const displayWidth = computed(() => pageWidth.value * zoom.value)
const displayHeight = computed(() => pageHeight.value * zoom.value)

const pageAnnotations = computed(() =>
  props.modelValue
    .map((item, index) => ({ ...item, key: item.id ?? `new-${index}`, index }))
    .filter((item) => Number(item.page) === page.value),
)

const labelFor = (item) =>
  ({
    stamp: 'ตราประทับรับ',
    text: 'ข้อความ',
    date: 'วันที่',
    qr: 'QR',
    // ใช้ในหน้าลงนาม — ผู้ลงนามต้องอ่านออกว่าวัตถุที่ลากอยู่คืออะไร
    signature: 'ลายเซ็น',
    note: 'ข้อความกำกับ',
  })[item.type] || item.type

const styleFor = (item) => ({
  left: `${item.x * zoom.value}px`,
  top: `${item.y * zoom.value}px`,
  width: `${(item.width || 150) * zoom.value}px`,
  height: `${(item.height || 60) * zoom.value}px`,
})

const remove = (item) => {
  const next = props.modelValue.filter((_, index) => index !== item.index)
  emit('update:modelValue', next)
}

// ── ลากย้ายตำแหน่ง — แปลงระยะที่ลากบนจอกลับเป็น PDF point ทันที ──
const startDrag = (event, indexInPage) => {
  if (!props.editable) return

  const item = pageAnnotations.value[indexInPage]
  dragging = { index: item.index, startX: event.clientX, startY: event.clientY, originX: item.x, originY: item.y }

  window.addEventListener('pointermove', onDrag)
  window.addEventListener('pointerup', endDrag)
}

const onDrag = (event) => {
  if (!dragging) return

  const deltaX = (event.clientX - dragging.startX) / zoom.value
  const deltaY = (event.clientY - dragging.startY) / zoom.value

  const next = props.modelValue.map((item, index) =>
    index === dragging.index
      ? {
          ...item,
          x: Math.max(0, round(dragging.originX + deltaX)),
          y: Math.max(0, round(dragging.originY + deltaY)),
        }
      : item,
  )

  emit('update:modelValue', next)
}

const endDrag = () => {
  dragging = null
  window.removeEventListener('pointermove', onDrag)
  window.removeEventListener('pointerup', endDrag)
}

// ── การแสดงผลหน้า PDF ──
const renderPage = async () => {
  if (!pdfDocument || !canvas.value) return

  if (renderTask) {
    renderTask.cancel()
    renderTask = null
  }

  const pdfPage = await pdfDocument.getPage(page.value)
  const base = pdfPage.getViewport({ scale: 1 })

  pageWidth.value = base.width
  pageHeight.value = base.height

  const viewport = pdfPage.getViewport({ scale: zoom.value })
  const context = canvas.value.getContext('2d')

  canvas.value.width = viewport.width
  canvas.value.height = viewport.height

  renderTask = pdfPage.render({ canvasContext: context, viewport })

  try {
    await renderTask.promise
  } catch {
    // ยกเลิกการวาดเมื่อเปลี่ยนหน้า/ซูมระหว่างทาง — ไม่ใช่ข้อผิดพลาด
  } finally {
    renderTask = null
  }
}

const load = async () => {
  if (!props.source) return

  const pdfjs = await import('pdfjs-dist')
  pdfjs.GlobalWorkerOptions.workerSrc = (
    await import('pdfjs-dist/build/pdf.worker.min.mjs?url')
  ).default

  pdfDocument = await pdfjs.getDocument(props.source).promise
  pageCount.value = pdfDocument.numPages
  page.value = 1

  await renderPage()
}

watch(() => props.source, load, { immediate: true })
watch([page, zoom], renderPage)

onBeforeUnmount(() => {
  endDrag()
  renderTask?.cancel()
  pdfDocument?.destroy()
})

defineExpose({ pageCount, page })
</script>
