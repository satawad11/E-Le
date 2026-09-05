<template>
  <AppModal :model-value="true" :title="title" @update:model-value="!busy && $emit('close')">
    <p class="text-sm text-gray-700">{{ description }}</p>
    <p v-if="error" role="alert" class="text-red-700 mt-3">{{ error }}</p>
    <AppInput v-if="needsPassword" v-model="password" type="password" autocomplete="current-password" label="ยืนยันรหัสผ่านของท่าน" />
    <template #footer>
      <AppButton variant="secondary" :disabled="busy" @click="$emit('close')">ยกเลิก</AppButton>
      <AppButton :loading="busy" :disabled="busy || (needsPassword && !password)" @click="execute">ยืนยัน{{ title }}</AppButton>
    </template>
  </AppModal>
</template>
<script setup>
import { ref } from 'vue'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import AppModal from '../ui/AppModal.vue'
import AppButton from '../ui/AppButton.vue'
import AppInput from '../ui/AppInput.vue'
const props = defineProps({ title: String, description: String, action: { type: Function, required: true } })
const emit = defineEmits(['close', 'done'])
const busy = ref(false)
const needsPassword = ref(false)
const password = ref('')
const error = ref('')
async function execute() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    if (needsPassword.value) {
      await api.post('/auth/confirm-password', { password: password.value })
      password.value = ''
      needsPassword.value = false
    }
    await props.action()
    emit('done')
  } catch (err) {
    if (err.response?.status === 423) needsPassword.value = true
    error.value = getErrorMessage(err, 'ดำเนินการไม่สำเร็จ')
  } finally {
    password.value = ''
    busy.value = false
  }
}
</script>
