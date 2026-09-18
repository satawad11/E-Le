<template>
  <AppModal
    :model-value="true"
    title="เวียนหนังสือและส่งต่อตามสายงาน"
    size="lg"
    @update:model-value="$emit('close')"
  >
    <div class="space-y-4">
      <div
        v-if="confidentiality === 'top_secret'"
        class="p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm flex items-start gap-3"
      >
        <svg class="w-5 h-5 shrink-0 mt-0.5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <div>
          <strong class="font-semibold block text-red-800">ชั้นความลับ: ลับที่สุด</strong>
          ตามระเบียบสำนักนายกรัฐมนตรีว่าด้วยงานสารบรรณ (ฉบับที่ ๔) พ.ศ. ๒๕๖๔ ข้อ ๒๙ หนังสือชั้นลับที่สุดไม่อาจจัดส่งหรือเวียนด้วยระบบสารบรรณอิเล็กทรอนิกส์ได้ ให้ดำเนินการจัดส่งเป็นเอกสารกระดาษตามระเบียบรักษาความปลอดภัยแห่งชาติ
        </div>
      </div>

      <div v-if="confidentiality !== 'top_secret'" class="space-y-3">
        <p class="text-sm text-gray-500">
          ผู้รับจะเห็นหนังสือฉบับนี้ในกล่องหนังสือเข้า และต้องกดรับทราบเอง
        </p>

        <!-- ปุ่มเวียนด่วนตามโครงสร้าง ๔ ฝ่ายหลัก สอศ. -->
        <div v-if="divisionUnits.length" class="bg-gray-50 p-3 rounded-xl border border-gray-200/80 space-y-2">
          <div class="flex items-center gap-1.5 text-xs font-semibold text-gray-700">
            <span>🏛️</span>
            <span>เวียนด่วนตาม ๔ ฝ่ายหลักอาชีวศึกษา (ทั้งสายงาน):</span>
          </div>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="div in divisionUnits"
              :key="div.id"
              type="button"
              class="px-2.5 py-1 rounded-lg text-xs font-medium border border-primary-200 bg-white hover:bg-primary-50 text-primary-800 transition-colors shadow-2xs"
              @click="addDivisionSubtreeTarget(div)"
            >
              + {{ div.name }}
            </button>
          </div>
        </div>

        <div class="space-y-3">
          <div
            v-for="(target, index) in targets"
            :key="index"
            class="grid sm:grid-cols-12 gap-2 items-end border border-gray-100 rounded-xl p-3 bg-white"
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
                :label="target.type === 'org_subtree' ? 'หน่วยงานและทุกงานย่อย' : 'หน่วยงาน'"
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

        <!-- ชุดคำสั่งการ / หมายเหตุด่วน -->
        <div class="space-y-1.5 pt-2">
          <div class="flex flex-wrap items-center justify-between gap-1">
            <label class="block text-sm font-semibold text-gray-700">คำสั่งการ / หมายเหตุถึงผู้รับ</label>
            <div class="flex flex-wrap gap-1 text-[11px]">
              <button
                v-for="preset in NOTE_PRESETS"
                :key="preset"
                type="button"
                class="px-2 py-0.5 rounded border border-gray-200 bg-gray-50 hover:bg-gray-100 text-gray-600 transition-colors"
                @click="note = preset"
              >
                {{ preset }}
              </button>
            </div>
          </div>
          <AppTextarea v-model="note" :rows="2" placeholder="ระบุข้อสั่งการ ความเห็น หรือคำแนะนำเพิ่มเติม..." />
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      </div>
    </div>

    <template #footer>
      <AppButton variant="secondary" @click="$emit('close')">ยกเลิก</AppButton>
      <AppButton
        :loading="busy"
        :disabled="busy || confidentiality === 'top_secret'"
        @click="submit"
      >
        ส่งหนังสือ
      </AppButton>
    </template>
  </AppModal>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
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
  confidentiality: { type: String, default: 'normal' },
})

const emit = defineEmits(['close', 'sent'])

const NOTE_PRESETS = [
  'เพื่อโปรดพิจารณา',
  'เพื่อโปรดทราบและดำเนินการ',
  'เพื่อแจ้งผู้เกี่ยวข้องทราบ',
  'เพื่อประสานงานตามสายงาน',
]

const targetTypeOptions = [
  { value: 'user', label: 'รายบุคคล' },
  { value: 'org_unit', label: 'หน่วยงาน' },
  { value: 'org_subtree', label: 'หน่วยงานและหน่วยงานย่อย' },
  { value: 'all_authorized', label: 'ผู้มีสิทธิ์ทั้งหมด' },
]

const roleOptions = [
  { value: 'to', label: 'เรียน (ผู้รับปฏิบัติ)' },
  { value: 'cc', label: 'สำเนา (ผู้รับทราบ)' },
]

const targets = ref([{ type: 'user', id: null, role: 'to' }])
const note = ref('')
const error = ref('')
const busy = ref(false)
const users = ref([])
const orgUnits = ref([])

const divisionUnits = computed(() => {
  return orgUnits.value.filter((u) => {
    const name = u.name || ''
    return (
      name.includes('ฝ่ายบริหารทรัพยากร') ||
      name.includes('ฝ่ายวิชาการ') ||
      name.includes('ฝ่ายพัฒนากิจการนักเรียน') ||
      name.includes('ฝ่ายแผนงานและความร่วมมือ') ||
      name.startsWith('ฝ่าย')
    )
  })
})

const addTarget = () => targets.value.push({ type: 'user', id: null, role: 'to' })

const addDivisionSubtreeTarget = (division) => {
  // หากเป้าหมายแรกยังว่าง ให้แทนที่ ไม่เช่นนั้นเพิ่มแถวใหม่
  if (targets.value.length === 1 && !targets.value[0].id) {
    targets.value[0] = { type: 'org_subtree', id: division.id, role: 'to' }
  } else {
    targets.value.push({ type: 'org_subtree', id: division.id, role: 'to' })
  }
}

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
