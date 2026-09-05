<template>
  <div class="space-y-2">
    <div class="border-2 border-dashed border-gray-300 rounded-xl bg-white">
      <canvas
        ref="canvas"
        class="block w-full touch-none rounded-xl"
        :style="{ height: `${height}px` }"
        :aria-label="label"
        role="img"
      />
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <AppButton size="sm" variant="ghost" :disabled="isEmpty" @click="clear">ล้าง</AppButton>
      <p class="text-xs text-gray-500">
        เขียนด้วยปากกาสไตลัส นิ้ว หรือเมาส์ได้ · ระบบบันทึกเป็นเส้น ไม่ใช่ภาพ
      </p>
    </div>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import SignaturePad from 'signature_pad'
import AppButton from './AppButton.vue'

/**
 * แผ่นเขียนลายมือและลายเซ็น (SoT §14, §15 · INT-11)
 *
 * **ส่งออกเป็นชุดพิกัดของเส้น ไม่ใช่ภาพ** — ตรงกับข้อห้ามของ SoT §14 ที่ว่า
 * ห้ามใช้ภาพจากเบราว์เซอร์เป็นผลลัพธ์ · ฝั่ง server วาดเส้นเหล่านี้ลงบน PDF
 * เป็นเวกเตอร์ จึงคมทุกระดับการขยายและไม่ขึ้นกับความละเอียดจอของผู้เขียน
 *
 * ใช้ `signature_pad` ที่เป็น dependency ของระบบอยู่แล้ว (ไม่เพิ่มตัวใหม่)
 * และอ่านค่าออกมาด้วย `toData()` ซึ่งคืนพิกัดดิบ ไม่ใช่ `toDataURL()`
 */
const props = defineProps({
  height: { type: Number, default: 160 },
  label: { type: String, default: 'พื้นที่เขียนลายมือ' },
  /** ขนาดของกรอบปลายทางบนหน้ากระดาษ (PDF point) ใช้ย่อพิกัดให้พอดีกรอบ */
  targetWidth: { type: Number, default: 150 },
  targetHeight: { type: Number, default: 50 },
})

const emit = defineEmits(['change'])

const canvas = ref(null)
const isEmpty = ref(true)
let pad = null

/**
 * แปลงพิกัดบนผืนผ้าใบให้เป็นพิกัดภายในกรอบปลายทาง (PDF point)
 *
 * ผืนผ้าใบมีขนาดตามหน้าจอซึ่งต่างกันไปในแต่ละเครื่อง จึงต้องย่อตามอัตราส่วน
 * ก่อนส่ง มิฉะนั้นลายเซ็นที่เขียนบนจอใหญ่จะล้นกรอบตอนเรนเดอร์
 */
const toStrokes = () => {
  if (!pad || pad.isEmpty()) return []

  const scaleX = props.targetWidth / canvas.value.offsetWidth
  const scaleY = props.targetHeight / props.height

  return pad
    .toData()
    .map((group) =>
      (group.points || []).map((point) => ({
        x: Math.round(point.x * scaleX * 100) / 100,
        y: Math.round(point.y * scaleY * 100) / 100,
      })),
    )
    .filter((stroke) => stroke.length >= 2)
}

const publish = () => {
  isEmpty.value = pad?.isEmpty() ?? true
  emit('change', toStrokes())
}

const clear = () => {
  pad?.clear()
  publish()
}

/** ปรับความละเอียดผืนผ้าใบให้คมบนจอความหนาแน่นสูง โดยไม่เปลี่ยนพิกัดที่ส่งออก */
const resize = () => {
  if (!canvas.value) return

  const ratio = Math.max(window.devicePixelRatio || 1, 1)
  canvas.value.width = canvas.value.offsetWidth * ratio
  canvas.value.height = props.height * ratio
  canvas.value.getContext('2d').scale(ratio, ratio)
  pad?.clear()
  publish()
}

onMounted(() => {
  pad = new SignaturePad(canvas.value, { minWidth: 0.8, maxWidth: 2.2, penColor: '#00005a' })
  pad.addEventListener('endStroke', publish)
  resize()
  window.addEventListener('resize', resize)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', resize)
  pad?.off()
})

defineExpose({ clear, toStrokes })
</script>
