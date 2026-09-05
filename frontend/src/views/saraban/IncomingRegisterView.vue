<template>
  <div class="max-w-4xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">ลงทะเบียนรับหนังสือ</h1>
        <p class="text-sm text-gray-500 mt-1">
          วันเวลาที่รับมาจากเวลาอ้างอิงของระบบ ไม่ใช่ค่าที่กรอกเอง
        </p>
      </div>
      <AppButton variant="ghost" @click="backToList">กลับสู่ทะเบียนรับ</AppButton>
    </div>

    <LoadingState v-if="loading" type="skeleton-form" />
    <ErrorState v-else-if="loadError" :message="loadError" @retry="boot" />

    <form v-else class="space-y-6" @submit.prevent="submitForm">
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100">
          <h2 class="font-semibold text-gray-800">หนังสือต้นทาง</h2>
          <p class="text-sm text-gray-500 mt-0.5">เลขที่และวันที่ที่ผู้ส่งเป็นผู้ออก</p>
        </header>

        <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2">
            <AppInput v-model="form.sender_name" label="จาก (ผู้ส่ง)" required :error="errors.sender_name" />
          </div>
          <AppInput v-model="form.source_number" label="เลขที่หนังสือต้นทาง" :error="errors.source_number" />
          <AppInput v-model="form.source_date" type="date" label="ลงวันที่" :error="errors.source_date" />
        </div>
      </section>

      <div
        v-if="duplicates.length"
        class="p-4 bg-amber-50 border border-amber-200 rounded-xl space-y-2"
        role="alert"
      >
        <p class="text-sm font-semibold text-amber-900">
          พบหนังสือที่อาจเป็นฉบับเดียวกันในทะเบียนรับแล้ว {{ duplicates.length }} รายการ
        </p>
        <ul class="text-sm text-amber-800 list-disc list-inside space-y-0.5">
          <li v-for="row in duplicates" :key="row.id">
            {{ row.document?.subject }} — รับเมื่อ {{ row.received_at }}
          </li>
        </ul>
        <label class="flex items-center gap-2 text-sm text-amber-900 pt-1">
          <input v-model="form.acknowledge_duplicate" type="checkbox" class="rounded border-amber-400" />
          ตรวจสอบแล้ว ยืนยันลงทะเบียนรับหนังสือฉบับนี้
        </label>
      </div>

      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100">
          <h2 class="font-semibold text-gray-800">รายละเอียดหนังสือ</h2>
        </header>

        <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <AppSelect
            v-model="form.document_type_id"
            label="ประเภทหนังสือ"
            required
            :error="errors.document_type_id"
          >
            <option value="">เลือกประเภท</option>
            <option v-for="type in options.document_types" :key="type.id" :value="type.id">
              {{ type.name }}
            </option>
          </AppSelect>

          <AppSelect
            v-model="form.owner_org_unit_id"
            label="หน่วยงานเจ้าของเรื่อง"
            required
            :error="errors.owner_org_unit_id"
          >
            <option value="">เลือกหน่วยงาน</option>
            <option v-for="unit in orgUnits" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
          </AppSelect>

          <div class="sm:col-span-2">
            <AppInput v-model="form.subject" label="เรื่อง" required :error="errors.subject" />
          </div>

          <div class="sm:col-span-2">
            <AppTextarea v-model="form.summary" label="สาระสำคัญ" :error="errors.summary" />
          </div>

          <AppSelect v-model="form.confidentiality" label="ชั้นความลับ" required>
            <option v-for="c in CONFIDENTIALITY" :key="c.value" :value="c.value">{{ c.label }}</option>
          </AppSelect>

          <AppSelect v-model="form.urgency" label="ชั้นความเร็ว" required>
            <option v-for="u in URGENCY" :key="u.value" :value="u.value">{{ u.label }}</option>
          </AppSelect>

          <div class="sm:col-span-2">
            <label for="incoming-file" class="block text-sm font-semibold text-gray-700 mb-1.5">
              ไฟล์หนังสือที่รับ
            </label>
            <input
              id="incoming-file"
              type="file"
              accept=".pdf,.jpg,.jpeg,.png"
              class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-xl file:border-0 file:bg-[#800000] file:px-4 file:py-2 file:text-white file:text-sm file:font-semibold hover:file:bg-[#600000]"
              @change="onFileChange"
            />
            <p v-if="errors.file" class="mt-1 text-xs text-red-600" role="alert">{{ errors.file }}</p>
          </div>
        </div>
      </section>

      <div class="flex justify-end">
        <AppButton type="submit" :loading="saving">ลงทะเบียนรับ</AppButton>
      </div>
    </form>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import { useAlertStore } from '../../stores/alert'
import AppButton from '../../components/ui/AppButton.vue'
import AppInput from '../../components/ui/AppInput.vue'
import AppSelect from '../../components/ui/AppSelect.vue'
import AppTextarea from '../../components/ui/AppTextarea.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'

const CONFIDENTIALITY = [
  { value: 'normal', label: 'ปกติ' },
  { value: 'confidential', label: 'ลับ' },
  { value: 'secret', label: 'ลับมาก' },
  { value: 'top_secret', label: 'ลับที่สุด' },
]
const URGENCY = [
  { value: 'normal', label: 'ปกติ' },
  { value: 'urgent', label: 'ด่วน' },
  { value: 'very_urgent', label: 'ด่วนมาก' },
  { value: 'most_urgent', label: 'ด่วนที่สุด' },
]

const router = useRouter()
const alertStore = useAlertStore()

const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const options = ref({ document_types: [] })
const orgUnits = ref([])
const duplicates = ref([])
const errors = reactive({})
const selectedFile = ref(null)

const form = reactive({
  sender_name: '',
  source_number: '',
  source_date: '',
  document_type_id: '',
  owner_org_unit_id: '',
  subject: '',
  summary: '',
  confidentiality: 'normal',
  urgency: 'normal',
  acknowledge_duplicate: false,
})

const onFileChange = (event) => {
  selectedFile.value = event.target.files?.[0] || null
}

const clearErrors = () => Object.keys(errors).forEach((key) => delete errors[key])

const boot = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/saraban/documents/options')
    options.value = data
    orgUnits.value = data.org_units || []
  } catch (err) {
    loadError.value = getErrorMessage(err, 'โหลดข้อมูลตั้งต้นไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const buildPayload = () => {
  const payload = new FormData()
  Object.entries(form).forEach(([key, value]) => {
    if (key === 'acknowledge_duplicate') {
      payload.append(key, value ? '1' : '0')

      return
    }
    payload.append(key, value ?? '')
  })
  if (selectedFile.value) payload.append('file', selectedFile.value)

  return payload
}

const submitForm = async () => {
  saving.value = true
  clearErrors()
  try {
    if (!form.acknowledge_duplicate) {
      const check = await api.get('/saraban/incoming/duplicates', { params: { sender_name: form.sender_name, source_number: form.source_number || undefined, source_date: form.source_date || undefined } })
      duplicates.value = check.data.duplicates || []
      if (duplicates.value.length) {
        alertStore.info('พบหนังสือที่อาจซ้ำ กรุณาตรวจสอบและยืนยันก่อนลงทะเบียน', 6000)
        return
      }
    }
    const { data } = await api.post('/saraban/incoming', buildPayload(), {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    alertStore.success(`ลงทะเบียนรับแล้ว เลขทะเบียนรับ ${data.register_number?.formatted || ''}`)
    router.push({ name: 'saraban-incoming' })
  } catch (err) {
    /*
     * 409 = พบหนังสือที่อาจซ้ำ — ไม่ใช่ข้อผิดพลาด แต่เป็นการขอให้คนตัดสิน
     * แสดงรายการที่ซ้ำแล้วให้ติ๊กยืนยันก่อนส่งอีกครั้ง (SoT §10)
     */
    if (err.response?.status === 409) {
      duplicates.value = err.response.data.duplicates || []
      alertStore.info(err.response.data.message, 6000)

      return
    }

    const bag = err.response?.data?.errors
    if (bag) {
      Object.entries(bag).forEach(([field, messages]) => {
        errors[field] = messages[0]
      })

      return
    }

    alertStore.error(getErrorMessage(err, 'ลงทะเบียนรับไม่สำเร็จ'))
  } finally {
    saving.value = false
  }
}

const backToList = () => router.push({ name: 'saraban-incoming' })

onMounted(boot)
</script>
