<template>
  <div class="max-w-7xl mx-auto space-y-6">
    <AppBreadcrumb />
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">{{ preset.title || 'ทะเบียนหนังสือ' }}</h1>
        <p class="text-sm text-gray-500 mt-1">
          {{ preset.description || 'หนังสือรับ หนังสือส่ง และหนังสือภายในที่อยู่ในขอบเขตของท่าน' }}
        </p>
      </div>
      <AppButton v-can="'saraban.document.create'" @click="goToCreate">+ สร้างหนังสือ</AppButton>
    </div>

    <SavedViews :filters="filters" @apply="applySavedView" />
    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
      <header class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="flex-1">
          <h2 class="font-semibold text-gray-800">รายการหนังสือ</h2>
          <p class="text-sm text-gray-500 mt-0.5">หนังสือที่เสนอเข้าสู่กระบวนการแล้วจะแก้ไขไม่ได้</p>
        </div>
        <div class="flex flex-wrap gap-2 items-end">
          <div class="w-full sm:w-56">
            <AppInput
              v-model="filters.q"
              label="ค้นหา"
              placeholder="เรื่อง หรือเลขที่หนังสือ"
              @keyup.enter="applyFilters"
            />
          </div>
          <AppSelect v-model="filters.direction" aria-label="กรองตามทิศทางของหนังสือ" @update:modelValue="applyFilters">
            <option value="">ทุกทิศทาง</option>
            <option v-for="d in DIRECTIONS" :key="d.value" :value="d.value">{{ d.label }}</option>
          </AppSelect>
          <AppSelect v-model="filters.status" aria-label="กรองตามสถานะ" @update:modelValue="applyFilters">
            <option value="">ทุกสถานะ</option>
            <option value="draft">ฉบับร่าง</option>
            <option value="submitted">เสนอแล้ว</option>
            <option value="registered">ลงทะเบียนรับแล้ว</option>
            <option value="signed">ลงนามแล้ว</option>
          </AppSelect>
          <AppButton variant="secondary" size="sm" @click="advanced = !advanced">
            {{ advanced ? 'ซ่อนค้นหาขั้นสูง' : 'ค้นหาขั้นสูง' }}
          </AppButton>
        </div>
      </header>

      <!--
        ค้นหาขั้นสูง (NAV §4.10) — ซ่อนไว้เป็นค่าเริ่มต้น เพราะผู้ใช้ส่วนใหญ่
        ค้นด้วยคำเดียว · เปิดออกเมื่อต้องการจำกัดขอบเขตจริง ๆ
      -->
      <div v-if="advanced" class="px-5 py-4 border-b border-gray-100 grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <AppSelect v-model="filters.document_type_id" label="ประเภทหนังสือ">
          <option value="">ทุกประเภท</option>
          <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
        </AppSelect>
        <AppSelect v-model="filters.confidentiality" label="ชั้นความลับ">
          <option value="">ทุกชั้น</option>
          <option value="normal">ไม่ลับ</option>
          <option value="confidential">ลับ</option>
          <option value="secret">ลับมาก</option>
          <option value="top_secret">ลับที่สุด</option>
        </AppSelect>
        <AppSelect v-model="filters.retention_status" label="สถานะการเก็บรักษา/อายัด">
          <option value="">ทุกสถานะการเก็บรักษา</option>
          <option value="scheduled">อยู่ระหว่างเก็บรักษา</option>
          <option value="due">ครบกำหนดทำลาย</option>
          <option value="on_hold">ถูกอายัด (Legal Hold)</option>
          <option value="disposed">ทำลายไฟล์แล้ว (คงทะเบียน)</option>
        </AppSelect>
        <AppInput v-model="filters.from" type="date" label="ตั้งแต่วันที่" />
        <AppInput v-model="filters.to" type="date" label="ถึงวันที่" />
        <div class="sm:col-span-2 lg:col-span-4 flex gap-2">
          <AppButton @click="applyFilters">ค้นหา</AppButton>
          <AppButton variant="secondary" @click="resetFilters">ล้างเงื่อนไข</AppButton>
        </div>
      </div>

      <LoadingState v-if="loading" type="skeleton-table" :rows="5" :columns="6" />
      <ErrorState v-else-if="error" :message="error" @retry="load" />
      <EmptyState
        v-else-if="!documents.length"
        title="ยังไม่มีหนังสือในทะเบียน"
        description="เริ่มต้นด้วยการสร้างหนังสือฉบับแรก"
      />

      <div v-else>
        <!-- Desktop Table -->
        <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm min-w-[720px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3 font-semibold">เรื่อง</th>
                <th class="text-left px-5 py-3 font-semibold">ประเภท</th>
                <th class="text-left px-5 py-3 font-semibold">ทิศทาง</th>
                <th class="text-center px-5 py-3 font-semibold">ชั้นความเร็ว</th>
                <th class="text-center px-5 py-3 font-semibold">สถานะ/การเก็บรักษา</th>
                <th class="text-right px-5 py-3 font-semibold">จัดการ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="doc in documents" :key="doc.id" class="hover:bg-gray-50/60">
                <td class="px-5 py-3 font-medium text-gray-900">
                  <div>{{ doc.subject }}</div>
                  <div v-if="doc.document_number?.formatted" class="text-xs text-gray-500 font-mono mt-0.5">
                    {{ doc.document_number.formatted }}
                  </div>
                </td>
                <td class="px-5 py-3 text-gray-600">{{ doc.document_type?.name || '—' }}</td>
                <td class="px-5 py-3 text-gray-600">{{ directionLabel(doc.direction) }}</td>
                <td class="px-5 py-3 text-center">
                  <AppBadge :variant="urgencyVariant(doc.urgency)">{{ urgencyLabel(doc.urgency) }}</AppBadge>
                </td>
                <td class="px-5 py-3 text-center">
                  <div class="flex flex-col items-center gap-1">
                    <AppBadge :variant="statusBadgeVariant(doc.status)">
                      {{ statusDisplayLabel(doc.status) }}
                    </AppBadge>
                    <AppBadge v-if="doc.legal_holds && doc.legal_holds.length" variant="warning" size="sm">
                      🔒 ถูกอายัด
                    </AppBadge>
                    <AppBadge v-else-if="doc.retention_schedule?.status === 'disposed'" variant="danger" size="sm">
                      🗑️ ทำลายไฟล์แล้ว
                    </AppBadge>
                    <AppBadge v-else-if="doc.retention_schedule?.status === 'approved'" variant="warning" size="sm">
                      ⚠️ อนุมัติทำลาย
                    </AppBadge>
                  </div>
                </td>
                <td class="px-5 py-3 text-right">
                  <AppButton size="sm" variant="ghost" @click="open(doc)">เปิดดู</AppButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile Cards -->
        <div class="block md:hidden divide-y divide-gray-100">
          <div v-for="doc in documents" :key="doc.id" class="p-4 space-y-2 hover:bg-gray-50">
            <div class="flex items-center justify-between gap-2">
              <span class="font-mono text-xs font-semibold text-primary bg-primary/5 px-2 py-0.5 rounded">
                {{ doc.document_number?.formatted || 'ยังไม่มีเลขที่' }}
              </span>
              <div class="flex items-center gap-1">
                <AppBadge :variant="urgencyVariant(doc.urgency)">{{ urgencyLabel(doc.urgency) }}</AppBadge>
                <AppBadge :variant="statusBadgeVariant(doc.status)">{{ statusDisplayLabel(doc.status) }}</AppBadge>
              </div>
            </div>

            <p class="font-medium text-gray-900 text-sm leading-snug">{{ doc.subject }}</p>

            <div class="flex items-center justify-between pt-2 border-t border-gray-100 text-xs text-gray-500">
              <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span>{{ doc.document_type?.name || '—' }}</span>
                <span>{{ directionLabel(doc.direction) }}</span>
              </div>
              <AppButton size="sm" variant="ghost" @click="open(doc)">เปิดดู</AppButton>
            </div>
          </div>
        </div>
      </div>

      <div v-if="lastPage > 1" class="px-5 py-4 border-t border-gray-100">
        <AppPagination :page="page" :last-page="lastPage" @update:page="changePage" />
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../axios'
import SavedViews from '../../components/saraban/SavedViews.vue'
import { getErrorMessage } from '../../utils/errorHandler'
import AppBadge from '../../components/ui/AppBadge.vue'
import AppBreadcrumb from '../../components/ui/AppBreadcrumb.vue'
import AppButton from '../../components/ui/AppButton.vue'
import AppInput from '../../components/ui/AppInput.vue'
import AppPagination from '../../components/ui/AppPagination.vue'
import AppSelect from '../../components/ui/AppSelect.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'

const DIRECTIONS = [
  { value: 'incoming', label: 'หนังสือรับ' },
  { value: 'outgoing', label: 'หนังสือส่ง' },
  { value: 'internal', label: 'หนังสือภายใน' },
]

const URGENCY = {
  normal: { label: 'ปกติ', variant: 'gray' },
  urgent: { label: 'ด่วน', variant: 'info' },
  very_urgent: { label: 'ด่วนมาก', variant: 'warning' },
  most_urgent: { label: 'ด่วนที่สุด', variant: 'danger' },
}

const router = useRouter()
const route = useRoute()

/**
 * ค่าตั้งต้นของทะเบียนที่กำลังเปิดอยู่ (NAV §2)
 *
 * งานสารบรรณไทยแยกทะเบียนรับ ทะเบียนส่ง และทะเบียนภายในตามระเบียบ · ทั้งสาม
 * ใช้หน้าเดียวกัน ต่างกันแค่ค่าตั้งต้น จึงประกาศไว้ที่ `meta` ของเส้นทางแทนที่
 * จะทำ component ซ้ำสามตัวที่ต้องแก้พร้อมกันทุกครั้ง
 */
const preset = computed(() => route.meta?.saraban || {})

const STATUS_MAP = {
  draft: { label: 'ฉบับร่าง', variant: 'gray' },
  submitted: { label: 'เสนอแล้ว', variant: 'info' },
  registered: { label: 'ลงทะเบียนแล้ว', variant: 'info' },
  signed: { label: 'ลงนามแล้ว', variant: 'success' },
  rejected: { label: 'ส่งกลับ/ปฏิเสธ', variant: 'warning' },
  cancelled: { label: 'ยกเลิก', variant: 'danger' },
}

const statusDisplayLabel = (status) => STATUS_MAP[status]?.label || status || '—'
const statusBadgeVariant = (status) => STATUS_MAP[status]?.variant || 'gray'

const documents = ref([])
const loading = ref(true)
const error = ref('')
const page = ref(1)
const lastPage = ref(1)
const filters = reactive({
  direction: '',
  status: '',
  q: '',
  document_type_id: '',
  confidentiality: '',
  retention_status: '',
  from: '',
  to: '',
})
const advanced = ref(false)
const documentTypes = ref([])
const applySavedView = (saved) => { filters.direction = saved.direction || ''; filters.status = saved.status || ''; page.value = 1; load() }

const applyFilters = () => { page.value = 1; load() }

/*
 * ล้างทุกช่อง ไม่ใช่เฉพาะที่เห็นอยู่ — เงื่อนไขที่ซ่อนอยู่แล้วยังมีผล คือกับดักที่ผู้ใช้หาไม่เจอ
 *
 * **ยกเว้นทิศทางที่มากับหน้า** — ถ้าล้างทิ้งด้วย หน้า "ทะเบียนหนังสือส่ง"
 * จะกลายเป็นทะเบียนรวมทั้งที่หัวข้อยังบอกว่าเป็นทะเบียนหนังสือส่ง
 */
const resetFilters = () => {
  Object.keys(filters).forEach((key) => { filters[key] = '' })
  filters.direction = preset.value.direction || ''
  applyFilters()
}

const directionLabel = (value) => DIRECTIONS.find((d) => d.value === value)?.label || value
const urgencyLabel = (value) => URGENCY[value]?.label || value
const urgencyVariant = (value) => URGENCY[value]?.variant || 'gray'

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/saraban/documents', {
      params: {
        page: page.value,
        // ส่งเฉพาะช่องที่กรอกจริง — พารามิเตอร์ว่างทำให้ query ฝั่ง server ซับซ้อนโดยเปล่าประโยชน์
        ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')),
        // กล่องรอลงทะเบียน — ตัวกรองอยู่ฝั่ง server เพราะต้องดูว่ามีเลขรับแล้วหรือยัง
        ...(preset.value.pendingRegistration ? { pending_registration: 1 } : {}),
      },
    })
    documents.value = data.data
    lastPage.value = data.last_page
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดทะเบียนหนังสือไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const changePage = (next) => {
  page.value = next
  load()
}

const goToCreate = () => router.push({ name: 'saraban-document-create' })
const open = (doc) => router.push({ name: 'saraban-document-detail', params: { id: doc.id } })

onMounted(async () => {
  filters.direction = preset.value.direction || ''

  await load()

  /*
   * โหลดประเภทหนังสือสำหรับตัวกรองขั้นสูง — ล้มเหลวได้โดยไม่พังทั้งหน้า
   * เพราะตัวกรองเป็นของเสริม ส่วนทะเบียนคือสิ่งที่ผู้ใช้มาดู
   */
  try {
    documentTypes.value = (await api.get('/saraban/documents/options')).data.document_types || []
  } catch {
    documentTypes.value = []
  }
})
</script>
