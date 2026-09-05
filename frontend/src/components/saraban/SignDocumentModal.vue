<template>
  <AppModal :model-value="modelValue" title="ลงนามในหนังสือ" size="lg" @update:modelValue="close">
    <div class="space-y-5">
      <p class="text-sm text-gray-600">
        ลายเซ็นจะถูกบันทึกเป็นเส้นและวาดลงบนหนังสือที่เครื่องแม่ข่าย
        พร้อมหลักฐานผู้ลงนาม เวลา และไฟล์ฉบับที่ลงนาม
      </p>

      <div>
        <h3 class="text-sm font-semibold text-gray-700 mb-1.5">ลายเซ็น</h3>
        <HandwritingCanvas
          :target-width="150"
          :target-height="50"
          label="พื้นที่เขียนลายเซ็น"
          @change="strokes = $event"
        />
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <AppInput v-model.number="placement.page" type="number" min="1" label="หน้าที่" />
        <AppInput v-model.number="placement.x" type="number" label="ตำแหน่งแนวนอน" hint="หน่วยจุดบนหน้ากระดาษ" />
        <AppInput v-model.number="placement.y" type="number" label="ตำแหน่งแนวตั้ง" />
      </div>

      <!--
        ยืนยันตัวตนซ้ำ (SoT §24) — แสดงเฉพาะเมื่อ server แจ้งว่าต้องยืนยัน
        ไม่ถามล่วงหน้าทุกครั้ง เพื่อไม่ให้ผู้ใช้ต้องกรอกรหัสผ่านโดยไม่จำเป็น
      -->
      <div v-if="needsReauth" class="p-4 bg-amber-50 border border-amber-200 rounded-xl space-y-3">
        <p class="text-sm font-semibold text-amber-900">
          การลงนามต้องยืนยันตัวตนอีกครั้ง
        </p>
        <AppInput
          v-model="password"
          type="password"
          label="รหัสผ่านของท่าน"
          autocomplete="current-password"
          :error="reauthError"
        />
        <AppButton size="sm" :loading="confirming" @click="confirmIdentity">ยืนยันตัวตน</AppButton>
      </div>
    </div>

    <template #footer>
      <div class="flex flex-wrap justify-end gap-3">
        <AppButton variant="ghost" @click="close">ยกเลิก</AppButton>
        <AppButton :loading="signing" :disabled="!strokes.length" @click="sign">ลงนาม</AppButton>
      </div>
    </template>
  </AppModal>
</template>

<script setup>
import { reactive, ref } from 'vue'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import { useAlertStore } from '../../stores/alert'
import AppButton from '../ui/AppButton.vue'
import AppInput from '../ui/AppInput.vue'
import AppModal from '../ui/AppModal.vue'
import HandwritingCanvas from '../ui/HandwritingCanvas.vue'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  documentId: { type: [String, Number], required: true },
})

const emit = defineEmits(['update:modelValue', 'signed'])

const alertStore = useAlertStore()
const strokes = ref([])
const signing = ref(false)
const confirming = ref(false)
const needsReauth = ref(false)
const password = ref('')
const reauthError = ref('')
const placement = reactive({ page: 1, x: 380, y: 700 })

const close = () => {
  needsReauth.value = false
  password.value = ''
  reauthError.value = ''
  emit('update:modelValue', false)
}

const confirmIdentity = async () => {
  confirming.value = true
  reauthError.value = ''
  try {
    await api.post('/auth/confirm-password', { password: password.value })
    needsReauth.value = false
    password.value = ''
    alertStore.success('ยืนยันตัวตนเรียบร้อยแล้ว')
  } catch (err) {
    reauthError.value = getErrorMessage(err, 'ยืนยันตัวตนไม่สำเร็จ')
  } finally {
    confirming.value = false
  }
}

const sign = async () => {
  signing.value = true
  try {
    const { data } = await api.post(`/saraban/documents/${props.documentId}/sign`, {
      page: placement.page,
      x: placement.x,
      y: placement.y,
      width: 150,
      height: 50,
      strokes: strokes.value,
    })

    alertStore.success('ลงนามเรียบร้อยแล้ว')
    emit('signed', data.evidence)
    close()
  } catch (err) {
    /*
     * 423 = ต้องยืนยันตัวตนซ้ำก่อน (SoT §24) — ไม่ใช่ข้อผิดพลาด
     * เปิดช่องกรอกรหัสผ่านให้แทนที่จะแสดงเป็น error
     */
    if (err.response?.status === 423) {
      needsReauth.value = true
      alertStore.info(err.response.data.message, 6000)

      return
    }

    alertStore.error(getErrorMessage(err, 'ลงนามไม่สำเร็จ'))
  } finally {
    signing.value = false
  }
}
</script>
