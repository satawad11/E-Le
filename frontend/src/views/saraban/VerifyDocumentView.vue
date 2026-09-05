<template>
  <div class="min-h-screen bg-gray-50 py-10 px-4">
    <div class="max-w-2xl mx-auto space-y-6">
      <header class="text-center">
        <h1 class="text-2xl font-bold text-gray-800">ตรวจสอบหนังสือราชการ</h1>
        <p class="text-sm text-gray-500 mt-1">
          ผลการตรวจสอบมาจากระบบสารบรรณอิเล็กทรอนิกส์โดยตรง
        </p>
      </header>

      <LoadingState v-if="loading" type="skeleton-card" />

      <ErrorState
        v-else-if="notFound"
        title="ไม่พบเอกสาร"
        message="รหัสตรวจสอบนี้ไม่ตรงกับหนังสือฉบับใดในระบบ กรุณาตรวจสอบ QR บนเอกสารอีกครั้ง"
      />

      <ErrorState v-else-if="error" :message="error" @retry="load" />

      <template v-else-if="result">
        <!-- สถานะรวม — เห็นได้ทันทีว่าเอกสารใช้อ้างอิงได้หรือไม่ -->
        <section
          class="rounded-2xl border shadow-sm p-5 text-center"
          :class="result.is_valid
            ? 'bg-emerald-50 border-emerald-200'
            : 'bg-red-50 border-red-200'"
          role="status"
        >
          <p class="text-lg font-bold" :class="result.is_valid ? 'text-emerald-800' : 'text-red-800'">
            {{ statusHeadline }}
          </p>
          <p class="text-sm mt-1" :class="result.is_valid ? 'text-emerald-700' : 'text-red-700'">
            {{ statusDetail }}
          </p>
        </section>

        <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
          <header class="px-5 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">รายละเอียดหนังสือ</h2>
          </header>

          <dl class="divide-y divide-gray-50">
            <div v-for="row in details" :key="row.label" class="px-5 py-3 flex flex-wrap gap-2">
              <dt class="w-40 text-sm text-gray-500">{{ row.label }}</dt>
              <dd class="flex-1 text-sm font-medium text-gray-900">{{ row.value || '—' }}</dd>
            </div>
          </dl>
        </section>

        <section
          v-if="result.signatures?.length"
          class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden"
        >
          <header class="px-5 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">ผู้ลงนาม</h2>
            <p class="text-sm text-gray-500 mt-0.5">
              ชื่อและตำแหน่ง ณ วันที่ลงนาม
            </p>
          </header>

          <ul class="divide-y divide-gray-50">
            <li v-for="(signature, index) in result.signatures" :key="index" class="px-5 py-3">
              <p class="text-sm font-medium text-gray-900">{{ signature.name }}</p>
              <p class="text-xs text-gray-500">
                {{ signature.position || '—' }}
                <span v-if="signature.org_unit"> · {{ signature.org_unit }}</span>
              </p>
              <p class="text-xs text-gray-400 mt-0.5">ลงนามเมื่อ {{ formatDateTime(signature.signed_at) }}</p>
            </li>
          </ul>
        </section>

        <p class="text-center text-xs text-gray-400">
          ตรวจสอบเมื่อ {{ formatDateTime(result.verified_at) }}
        </p>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'

/**
 * หน้าตรวจสอบหนังสือราชการจาก QR (SoT §12 · INT-12)
 *
 * **เป็นหน้าสาธารณะ** — ใช้ route meta `bare` + `public` เหมือนหน้าตรวจสอบใบลา
 * จึงแสดงนอก app shell และไม่ต้องเข้าสู่ระบบ · ผู้เปิดคือคนทั่วไปที่ถือกระดาษ
 * อยู่ในมือ ข้อมูลที่แสดงจึงเป็นเฉพาะสิ่งที่ปรากฏบนหน้าเอกสารอยู่แล้ว
 */
const route = useRoute()

const loading = ref(true)
const error = ref('')
const notFound = ref(false)
const result = ref(null)

const statusHeadline = computed(() => {
  if (!result.value) return ''
  if (result.value.content_altered) return 'เอกสารนี้ถูกแก้ไขหลังออกหนังสือ'
  if (result.value.cancelled) return 'หนังสือฉบับนี้ถูกยกเลิกแล้ว'
  if (!result.value.finalized_at) return 'หนังสือฉบับนี้ยังไม่ออกฉบับสมบูรณ์'

  return 'เอกสารถูกต้องตามระบบ'
})

const statusDetail = computed(() => {
  if (!result.value) return ''
  if (result.value.content_altered) {
    return 'ข้อมูลในทะเบียนไม่ตรงกับที่ผนึกไว้ตอนออกหนังสือ กรุณาตรวจสอบกับหน่วยงานเจ้าของเรื่อง'
  }
  if (result.value.cancelled) return 'ใช้อ้างอิงไม่ได้'
  if (!result.value.finalized_at) return 'ยังไม่ถือเป็นหนังสือฉบับสมบูรณ์'

  return 'หนังสือฉบับนี้ออกจากระบบจริงและข้อมูลไม่ถูกแก้ไขภายหลัง'
})

const formatDateTime = (value) => {
  if (!value) return '—'

  const date = new Date(value)
  const buddhistYear = date.getFullYear() + 543

  return `${String(date.getDate()).padStart(2, '0')}/${String(date.getMonth() + 1).padStart(2, '0')}/${buddhistYear} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')} น.`
}

const details = computed(() => [
  { label: 'เลขที่หนังสือ', value: result.value?.document_number },
  { label: 'เรื่อง', value: result.value?.subject },
  { label: 'ประเภทหนังสือ', value: result.value?.document_type },
  { label: 'หน่วยงานเจ้าของเรื่อง', value: result.value?.owner_org_unit },
  { label: 'ออกหนังสือเมื่อ', value: formatDateTime(result.value?.issued_at) },
])

const load = async () => {
  loading.value = true
  error.value = ''
  notFound.value = false

  try {
    const { data } = await api.get(`/saraban/verify/${route.params.hash}`)
    result.value = data
  } catch (err) {
    if (err.response?.status === 404) {
      notFound.value = true
    } else {
      error.value = getErrorMessage(err, 'ตรวจสอบเอกสารไม่สำเร็จ')
    }
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
