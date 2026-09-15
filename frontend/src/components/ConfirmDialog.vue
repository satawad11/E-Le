<template>
  <AppModal
    :model-value="Boolean(request)"
    :title="request?.title || ''"
    size="sm"
    @update:model-value="store.dismiss()"
  >
    <template v-if="request">
      <p class="text-sm text-gray-800 whitespace-pre-line">{{ request.message }}</p>
      <p v-if="request.detail" class="mt-2 text-sm text-gray-500 whitespace-pre-line">{{ request.detail }}</p>

      <div v-if="request.prompt" class="mt-4">
        <label :for="promptId" class="block text-sm font-medium text-gray-700 mb-1">
          {{ request.prompt.label }}
          <span v-if="request.prompt.required" class="text-red-600">*</span>
        </label>
        <textarea
          v-if="request.prompt.multiline"
          :id="promptId"
          ref="promptField"
          v-model="request.value"
          rows="3"
          :placeholder="request.prompt.placeholder || ''"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#800000] focus:ring-[#800000]"
          @keydown.ctrl.enter="submit"
        ></textarea>
        <input
          v-else
          :id="promptId"
          ref="promptField"
          v-model="request.value"
          type="text"
          :placeholder="request.prompt.placeholder || ''"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#800000] focus:ring-[#800000]"
          @keydown.enter="submit"
        >
        <p v-if="showRequiredWarning" role="alert" class="mt-1 text-xs text-red-600">
          กรุณากรอกข้อมูลก่อนกดยืนยัน
        </p>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-3">
        <button
          type="button"
          class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors"
          @click="store.dismiss()"
        >
          {{ request?.cancelText || 'ยกเลิก' }}
        </button>
        <button
          ref="confirmButton"
          type="button"
          class="rounded-lg px-4 py-2 text-sm font-semibold text-white transition-colors"
          :class="request?.tone === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-[#800000] hover:bg-[#600000]'"
          @click="submit"
        >
          {{ request?.confirmText || 'ยืนยัน' }}
        </button>
      </div>
    </template>
  </AppModal>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useConfirmStore } from '../stores/confirm'
import AppModal from './ui/AppModal.vue'

/**
 * กล่องยืนยันกลาง — วางไว้ครั้งเดียวที่ `App.vue` เหมือน `AlertList`
 *
 * ทุกหน้าจอเรียกผ่าน `useConfirm()` จึงไม่ต้องประกาศ modal ของตัวเองซ้ำ
 * และได้หน้าตา ปุ่มภาษาไทย และพฤติกรรม Esc/คลิกนอกกล่อง เหมือนกันทั้งระบบ
 */
const store = useConfirmStore()
const request = computed(() => store.request)

const promptField = ref(null)
const confirmButton = ref(null)
const showRequiredWarning = ref(false)
const promptId = 'confirm-dialog-prompt'

const submit = () => {
  const pending = store.request

  if (!pending) {
    return
  }

  const value = String(pending.value ?? '').trim()

  // บังคับกรอกก่อนยืนยัน — `window.prompt` ทำไม่ได้ ผู้ใช้จึงส่งค่าว่างไปได้ตลอด
  if (pending.prompt?.required && value === '') {
    showRequiredWarning.value = true
    promptField.value?.focus()

    return
  }

  store.confirm(value)
}

// โฟกัสให้ถูกที่ทันทีที่กล่องเปิด — ช่องกรอกถ้ามี ไม่งั้นเป็นปุ่มยืนยัน
watch(request, async (value) => {
  showRequiredWarning.value = false

  if (!value) {
    return
  }

  await nextTick()
  ;(promptField.value || confirmButton.value)?.focus()
})
</script>
