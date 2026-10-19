<template>
  <div class="otp-group" role="group" aria-label="รหัสยืนยัน 6 หลัก">
    <input
      v-for="(digit, i) in digits"
      :key="i"
      :ref="(el) => (boxes[i] = el)"
      class="otp"
      :value="digit"
      :aria-label="`หลักที่ ${i + 1}`"
      :autocomplete="i === 0 ? 'one-time-code' : 'off'"
      inputmode="numeric"
      maxlength="1"
      :disabled="disabled"
      @input="onInput(i, $event)"
      @keydown="onKeydown(i, $event)"
      @paste.prevent="onPaste($event)"
      @focus="$event.target.select()"
    >
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'

/** ช่องรหัส 6 หลัก (ต้นแบบหน้า 1) — วางรหัสทั้งชุดได้ · กรอกครบแล้วส่ง `complete` */
const props = defineProps({
  modelValue: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'complete'])

const boxes = ref([])
const digits = computed(() => Array.from({ length: 6 }, (_, i) => props.modelValue[i] || ''))

const update = (value) => {
  const clean = value.replace(/\D/g, '').slice(0, 6)
  emit('update:modelValue', clean)
  if (clean.length === 6) nextTick(() => emit('complete', clean))
}

const onInput = (i, event) => {
  const typed = event.target.value.replace(/\D/g, '')
  if (typed.length > 1) {
    update(props.modelValue.slice(0, i) + typed)
    return
  }
  const next = digits.value.slice()
  next[i] = typed
  update(next.join(''))
  event.target.value = typed
  if (typed && i < 5) boxes.value[i + 1]?.focus()
}

const onKeydown = (i, event) => {
  if (event.key === 'Backspace' && !digits.value[i] && i > 0) {
    boxes.value[i - 1]?.focus()
  } else if (event.key === 'ArrowLeft' && i > 0) {
    boxes.value[i - 1]?.focus()
  } else if (event.key === 'ArrowRight' && i < 5) {
    boxes.value[i + 1]?.focus()
  }
}

const onPaste = (event) => {
  const text = (event.clipboardData?.getData('text') || '').replace(/\D/g, '').slice(0, 6)
  if (text) {
    update(text)
    boxes.value[Math.min(text.length, 5)]?.focus()
  }
}

onMounted(() => boxes.value[0]?.focus())
</script>

<style scoped>
.otp-group { display: flex; gap: 8px; justify-content: space-between; }
.otp { width: 52px; height: 60px; border: 1px solid #C9CEC7; border-radius: 10px; text-align: center; font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 24px; color: #1B2430; background: #fff; box-sizing: border-box; min-width: 0; flex: 1 1 0; max-width: 52px; }
.otp:focus-visible { outline: 2px solid #1F5F8B; outline-offset: 2px; }
.otp:disabled { background: #F4F5F2; }
</style>
