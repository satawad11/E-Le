<template>
  <AppModal
    :model-value="true"
    title="เวียนหนังสือ"
    size="lg"
    @update:model-value="$emit('close')"
  >
    <div class="space-y-4">
      <p class="text-sm text-gray-500">
        ผู้รับจะเห็นหนังสือฉบับนี้ในกล่องหนังสือเข้า และต้องกดรับทราบเอง
      </p>

      <div class="space-y-3">
        <div
          v-for="(target, index) in targets"
          :key="index"
          class="grid sm:grid-cols-12 gap-2 items-end border border-gray-100 rounded-xl p-3"
        >
          <div class="sm:col-span-4">
            <AppSelect v-model="target.type" label="ส่งถึง">
              <option v-for="option in targetTypeOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </AppSelect>
          </div>

          <div class="sm:col-span-5">
            <AppSelect v-if="target.type === 'user'" v-model.number="target.id" label="บุคคล">
              <option value="">— เลือกบุคคล —</option>
              <option v-for="person in users" :key="person.id" :value="person.id">
                {{ person.name }}
              </option>
            </AppSelect>
            <AppSelect
              v-else-if="target.type !== 'all_authorized'"
              v-model.number="target.id"
              label="หน่วยงาน"
            >
              <option value="">— เลือกหน่วยงาน —</option>
              <option v-for="unit in orgUnits" :key="unit.id" :value="unit.id">
                {{ unit.name }}
              </option>
            </AppSelect>
            <p v-else class="text-sm text-gray-500 pb-2">ผู้มีสิทธิ์เข้าถึงหนังสือทุกคน</p>
          </div>

          <div class="sm:col-span-2">
            <AppSelect v-model="target.role" label="ฐานะ">
              <option v-for="option in roleOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </AppSelect>
          </div>

          <div class="sm:col-span-1 text-right">
            <AppButton
              v-if="targets.length > 1"
              size="sm"
              variant="secondary"
              @click="targets.splice(index, 1)"
            >
              ลบ
            </AppButton>
          </div>
        </div>
      </div>

      <AppButton size="sm" variant="secondary" @click="addTarget">+ เพิ่มผู้รับ</AppButton>

      <AppTextarea v-model="note" label="คำสั่งการ / หมายเหตุถึงผู้รับ" :rows="2" />

      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    </div>

    <template #footer>
      <AppButton variant="secondary" @click="$emit('close')">ยกเลิก</AppButton>
      <AppButton :loading="busy" :disabled="busy" @click="submit">ส่งหนังสือ</AppButton>
    </template>
  </AppModal>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import AppButton from '../ui/AppButton.vue'
import AppModal from '../ui/AppModal.vue'
import AppSelect from '../ui/AppSelect.vue'
import AppTextarea from '../ui/AppTextarea.vue'

/**
 * เวียนหนังสือถึงผู้รับ (SoT §15 · INT-13)
 *
 * รายชื่อบุคคลและหน่วยงานมาจาก `/saraban/distribution-targets` ซึ่งกรองด้วย
 * ขอบเขตของสิทธิ์ `saraban.distribution.send` ตัวเดียวกับที่ใช้ตอนส่งจริง
 * หน้าจอจึงไม่เสนอชื่อที่กดแล้วถูกปฏิเสธ · แต่ **หน้าจอไม่ใช่ด่านความปลอดภัย**
 * ฝั่ง server ตรวจขอบเขตซ้ำทุกครั้งใน `RecipientResolver`
 */
const props = defineProps({
  documentId: { type: [Number, String], required: true },
})

const emit = defineEmits(['close', 'sent'])

const targetTypeOptions = [
  { value: 'user', label: 'รายบุคคล' },
  { value: 'org_unit', label: 'หน่วยงาน' },
  { value: 'org_subtree', label: 'หน่วยงานและหน่วยงานย่อย' },
  { value: 'all_authorized', label: 'ผู้มีสิทธิ์ทั้งหมด' },
]

const roleOptions = [
  { value: 'to', label: 'เรียน' },
  { value: 'cc', label: 'สำเนา' },
]

const targets = ref([{ type: 'user', id: null, role: 'to' }])
const note = ref('')
const error = ref('')
const busy = ref(false)
const users = ref([])
const orgUnits = ref([])

const addTarget = () => targets.value.push({ type: 'user', id: null, role: 'to' })

const loadOptions = async () => {
  try {
    const { data } = await api.get('/saraban/distribution-targets')
    users.value = data.users || []
    orgUnits.value = data.org_units || []
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดรายชื่อผู้รับไม่สำเร็จ')
  }
}

const submit = async () => {
  busy.value = true
  error.value = ''

  try {
    await api.post(`/saraban/documents/${props.documentId}/distributions`, {
      // `all_authorized` ไม่มี id — ส่ง id ไปด้วยจะกลายเป็นข้อมูลที่ไม่มีความหมาย
      targets: targets.value.map((target) => ({
        type: target.type,
        id: target.type === 'all_authorized' ? undefined : target.id,
        role: target.role,
      })),
      note: note.value || undefined,
    })

    emit('sent')
  } catch (err) {
    error.value = getErrorMessage(err, 'เวียนหนังสือไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

onMounted(loadOptions)
</script>
