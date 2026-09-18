<template>
  <div class="space-y-2">
    <!-- Standard in-line canvas box -->
    <div class="border-2 border-dashed border-gray-300 rounded-2xl bg-white relative group overflow-hidden shadow-xs">
      <canvas
        ref="canvas"
        class="block w-full touch-none rounded-2xl cursor-crosshair bg-slate-50/40"
        :style="{ height: `${height}px` }"
        :aria-label="label"
        role="img"
      />

      <!-- Full-screen / Expand trigger button -->
      <button
        type="button"
        @click="openFullScreen"
        class="absolute top-2.5 right-2.5 p-2 rounded-xl bg-white/90 hover:bg-white text-gray-600 hover:text-[#800000] border border-gray-200 shadow-sm transition-all text-xs font-semibold flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#800000]"
        title="ขยายเต็มหน้าจอ / หมุนเซ็นแนวนอน"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1v4m0-4h-4m4 4l-5-5m-7 11v4m0 0H4m4 0l-5-5m16 5v-4m0 4h-4m4-4l-5 5"/>
        </svg>
        <span class="hidden sm:inline">เต็มหน้าจอ</span>
      </button>
    </div>

    <!-- Controls toolbar -->
    <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
      <div class="flex items-center gap-2">
        <AppButton size="sm" variant="ghost" :disabled="isEmpty" @click="undo">
          <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a5 5 0 015 5v2a5 5 0 01-5 5H6m0 0l4-4m-4 4l4 4"/>
          </svg>
          ย้อนกลับเส้น
        </AppButton>
        <AppButton size="sm" variant="ghost" :disabled="isEmpty" @click="clear">
          <svg class="w-3.5 h-3.5 mr-1 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
          </svg>
          ล้างทั้งหมด
        </AppButton>
      </div>

      <p class="text-xs text-gray-500 flex items-center gap-1">
        <svg class="w-3.5 h-3.5 text-[#800000]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
        </svg>
        เขียนด้วยสไตลัส นิ้ว หรือเมาส์ได้ · บันทึกเป็นเวกเตอร์คมชัด 100%
      </p>
    </div>

    <!-- Full-screen Canvas Modal Overlay -->
    <teleport to="body">
      <transition name="fade">
        <div
          v-if="isFullScreen"
          class="fixed inset-0 z-50 bg-gray-900/80 backdrop-blur-sm flex flex-col p-4 sm:p-6 select-none"
          role="dialog"
          aria-modal="true"
          aria-label="แคนวาสเขียนลายมือแบบเต็มหน้าจอ"
        >
          <!-- Fullscreen Top Bar -->
          <div class="flex items-center justify-between text-white mb-3">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 rounded-xl bg-[#800000] flex items-center justify-center font-bold">
                ✍️
              </div>
              <div>
                <h3 class="font-bold text-base sm:text-lg">{{ label }} (โหมดเต็มหน้าจอ)</h3>
                <p class="text-xs text-gray-300">ท่านสามารถหมุนหน้าจอโทรศัพท์เป็นแนวนอนเพื่อให้เซ็นได้กว้างขึ้น</p>
              </div>
            </div>
            <button
              type="button"
              @click="closeFullScreen"
              class="p-2 rounded-xl bg-white/10 hover:bg-white/20 text-white transition-colors focus:outline-none"
              title="ปิดโหมดเต็มหน้าจอ"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <!-- Large Canvas Container -->
          <div class="flex-1 bg-white rounded-3xl border-2 border-gray-200 shadow-2xl relative overflow-hidden flex flex-col">
            <canvas
              ref="fullscreenCanvas"
              class="flex-1 w-full touch-none cursor-crosshair bg-slate-50/50"
              aria-label="พื้นที่วาดลายเซ็นเต็มหน้าจอ"
            />
          </div>

          <!-- Fullscreen Bottom Action Bar -->
          <div class="mt-4 flex flex-wrap items-center justify-between gap-3 bg-white/10 backdrop-blur-md p-3 rounded-2xl">
            <div class="flex items-center gap-2">
              <button
                type="button"
                @click="undoFs"
                :disabled="isEmptyFs"
                class="px-4 py-2 bg-white/20 hover:bg-white/30 disabled:opacity-40 text-white text-sm font-semibold rounded-xl transition-colors flex items-center gap-1.5"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a5 5 0 015 5v2a5 5 0 01-5 5H6m0 0l4-4m-4 4l4 4"/>
                </svg>
                ย้อนกลับเส้น
              </button>
              <button
                type="button"
                @click="clearFs"
                :disabled="isEmptyFs"
                class="px-4 py-2 bg-red-600/80 hover:bg-red-600 disabled:opacity-40 text-white text-sm font-semibold rounded-xl transition-colors flex items-center gap-1.5"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
                ล้างทั้งหมด
              </button>
            </div>

            <div class="flex items-center gap-2">
              <button
                type="button"
                @click="confirmFullScreen"
                class="px-6 py-2.5 bg-[#800000] hover:bg-[#600000] text-white text-sm font-bold rounded-xl shadow-lg transition-all flex items-center gap-2"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                ยืนยันลายมือชื่อ
              </button>
            </div>
          </div>
        </div>
      </transition>
    </teleport>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, nextTick } from 'vue'
import SignaturePad from 'signature_pad'
import AppButton from './AppButton.vue'

/**
 * แผ่นเขียนลายมือและลายเซ็น (SoT §14, §15 · INT-11)
 *
 * **ส่งออกเป็นชุดพิกัดของเส้น ไม่ใช่ภาพ**
 * บันทึกเป็นเวกเตอร์ลงบน PDF จึงคมชัดทุกระดับการซูมและไม่ขึ้นกับความละเอียดจอ
 */
const props = defineProps({
  height: { type: Number, default: 160 },
  label: { type: String, default: 'พื้นที่เขียนลายมือ' },
  targetWidth: { type: Number, default: 150 },
  targetHeight: { type: Number, default: 50 },
})

const emit = defineEmits(['change'])

const canvas = ref(null)
const fullscreenCanvas = ref(null)
const isEmpty = ref(true)
const isEmptyFs = ref(true)
const isFullScreen = ref(false)

let pad = null
let fsPad = null

const toStrokesFromPad = (targetPad, canvasEl) => {
  if (!targetPad || targetPad.isEmpty() || !canvasEl) return []

  const scaleX = props.targetWidth / canvasEl.offsetWidth
  const scaleY = props.targetHeight / (canvasEl.offsetHeight || props.height)

  return targetPad
    .toData()
    .map((group) =>
      (group.points || []).map((point) => ({
        x: Math.round(point.x * scaleX * 100) / 100,
        y: Math.round(point.y * scaleY * 100) / 100,
      })),
    )
    .filter((stroke) => stroke.length >= 2)
}

const toStrokes = () => {
  return toStrokesFromPad(pad, canvas.value)
}

const publish = () => {
  isEmpty.value = pad?.isEmpty() ?? true
  emit('change', toStrokes())
}

const clear = () => {
  pad?.clear()
  publish()
}

const undo = () => {
  if (!pad) return
  const data = pad.toData()
  if (data.length > 0) {
    data.pop()
    pad.fromData(data)
    publish()
  }
}

const resize = () => {
  if (!canvas.value) return
  const ratio = Math.max(window.devicePixelRatio || 1, 1)
  const currentData = pad ? pad.toData() : null

  canvas.value.width = canvas.value.offsetWidth * ratio
  canvas.value.height = props.height * ratio
  canvas.value.getContext('2d').scale(ratio, ratio)

  if (pad) {
    pad.clear()
    if (currentData && currentData.length) {
      pad.fromData(currentData)
    }
  }
  publish()
}

const openFullScreen = async () => {
  isFullScreen.value = true
  await nextTick()
  if (!fullscreenCanvas.value) return

  const ratio = Math.max(window.devicePixelRatio || 1, 1)
  fullscreenCanvas.value.width = fullscreenCanvas.value.offsetWidth * ratio
  fullscreenCanvas.value.height = fullscreenCanvas.value.offsetHeight * ratio
  fullscreenCanvas.value.getContext('2d').scale(ratio, ratio)

  fsPad = new SignaturePad(fullscreenCanvas.value, {
    minWidth: 1.2,
    maxWidth: 3.0,
    penColor: '#00005a',
  })

  // Copy existing strokes from normal pad if any
  if (pad && !pad.isEmpty()) {
    const data = pad.toData()
    fsPad.fromData(data)
  }

  isEmptyFs.value = fsPad.isEmpty()
  fsPad.addEventListener('endStroke', () => {
    isEmptyFs.value = fsPad.isEmpty()
  })
}

const closeFullScreen = () => {
  if (fsPad) {
    fsPad.off()
    fsPad = null
  }
  isFullScreen.value = false
}

const undoFs = () => {
  if (!fsPad) return
  const data = fsPad.toData()
  if (data.length > 0) {
    data.pop()
    fsPad.fromData(data)
    isEmptyFs.value = fsPad.isEmpty()
  }
}

const clearFs = () => {
  fsPad?.clear()
  isEmptyFs.value = true
}

const confirmFullScreen = () => {
  if (fsPad && pad) {
    const data = fsPad.toData()
    pad.clear()
    pad.fromData(data)
    publish()
  }
  closeFullScreen()
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
  if (fsPad) fsPad.off()
})

defineExpose({ clear, toStrokes, undo })
</script>
