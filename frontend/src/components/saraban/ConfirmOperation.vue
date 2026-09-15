<template>
  <AppModal :model-value="true" :title="title" @update:model-value="!busy && $emit('close')">
    <p class="text-sm text-gray-700">{{ description }}</p>
    <p v-if="error" role="alert" class="text-red-700 mt-3">{{ error }}</p>

    <div v-if="needsPassword" class="mt-3 space-y-3">
      <div class="flex items-center space-x-2 text-xs border-b border-gray-100 pb-2">
        <button
          type="button"
          class="px-2.5 py-1 rounded font-medium transition"
          :class="authMode === 'password' ? 'bg-[#800000] text-white' : 'text-gray-600 hover:text-gray-900'"
          @click="authMode = 'password'"
        >
          ยืนยันด้วยรหัสผ่าน
        </button>
        <button
          type="button"
          class="px-2.5 py-1 rounded font-medium transition"
          :class="authMode === 'totp' ? 'bg-[#800000] text-white' : 'text-gray-600 hover:text-gray-900'"
          @click="authMode = 'totp'"
        >
          ยืนยันด้วยแอป OTP (MFA)
        </button>
      </div>

      <AppInput
        v-if="authMode === 'password'"
        v-model="password"
        type="password"
        autocomplete="current-password"
        label="ยืนยันรหัสผ่านของท่าน"
      />

      <AppInput
        v-else
        v-model="totpCode"
        type="text"
        inputmode="numeric"
        maxlength="6"
        placeholder="123456"
        label="รหัส OTP 6 หลัก"
        hint="เปิดแอปพลิเคชัน Authenticator แล้วกรอกรหัส 6 หลัก"
      />
    </div>

    <template #footer>
      <AppButton variant="secondary" :disabled="busy" @click="$emit('close')">ยกเลิก</AppButton>
      <AppButton :loading="busy" :disabled="busy || (needsPassword && !canSubmitAuth)" @click="execute">ยืนยัน{{ title }}</AppButton>
    </template>
  </AppModal>
</template>
<script setup>
import { computed, ref } from 'vue'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import AppModal from '../ui/AppModal.vue'
import AppButton from '../ui/AppButton.vue'
import AppInput from '../ui/AppInput.vue'
const props = defineProps({ title: String, description: String, action: { type: Function, required: true } })
const emit = defineEmits(['close', 'done'])
const busy = ref(false)
const needsPassword = ref(false)
const authMode = ref('password')
const password = ref('')
const totpCode = ref('')
const error = ref('')

const canSubmitAuth = computed(() => {
  return authMode.value === 'totp'
    ? totpCode.value.trim().length >= 6
    : !!password.value
})

async function execute() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    if (needsPassword.value) {
      const payload = authMode.value === 'totp'
        ? { totp_code: totpCode.value.trim() }
        : { password: password.value }

      await api.post('/auth/confirm-password', payload)
      password.value = ''
      totpCode.value = ''
      needsPassword.value = false
    }
    await props.action()
    emit('done')
  } catch (err) {
    if (err.response?.status === 423) needsPassword.value = true
    error.value = getErrorMessage(err, 'ดำเนินการไม่สำเร็จ')
  } finally {
    password.value = ''
    totpCode.value = ''
    busy.value = false
  }
}
</script>
