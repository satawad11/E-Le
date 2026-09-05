<template>
  <div class="max-w-7xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">ทะเบียนหนังสือ</h1>
        <p class="text-sm text-gray-500 mt-1">
          หนังสือรับ หนังสือส่ง และหนังสือภายในที่อยู่ในขอบเขตของท่าน
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
        <div class="flex gap-2">
          <AppSelect v-model="filters.direction" aria-label="กรองตามทิศทางของหนังสือ" @update:modelValue="load">
            <option value="">ทุกทิศทาง</option>
            <option v-for="d in DIRECTIONS" :key="d.value" :value="d.value">{{ d.label }}</option>
          </AppSelect>
          <AppSelect v-model="filters.status" aria-label="กรองตามสถานะ" @update:modelValue="load">
            <option value="">ทุกสถานะ</option>
            <option value="draft">ฉบับร่าง</option>
            <option value="submitted">เสนอแล้ว</option>
          </AppSelect>
        </div>
      </header>

      <LoadingState v-if="loading" type="skeleton-table" :rows="5" :columns="6" />
      <ErrorState v-else-if="error" :message="error" @retry="load" />
      <EmptyState
        v-else-if="!documents.length"
        title="ยังไม่มีหนังสือในทะเบียน"
        description="เริ่มต้นด้วยการสร้างหนังสือฉบับแรก"
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm min-w-[720px]">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left px-5 py-3 font-semibold">เรื่อง</th>
              <th class="text-left px-5 py-3 font-semibold">ประเภท</th>
              <th class="text-left px-5 py-3 font-semibold">ทิศทาง</th>
              <th class="text-center px-5 py-3 font-semibold">ชั้นความเร็ว</th>
              <th class="text-center px-5 py-3 font-semibold">สถานะ</th>
              <th class="text-right px-5 py-3 font-semibold">จัดการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="doc in documents" :key="doc.id" class="hover:bg-gray-50/60">
              <td class="px-5 py-3 font-medium text-gray-900">{{ doc.subject }}</td>
              <td class="px-5 py-3 text-gray-600">{{ doc.document_type?.name || '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ directionLabel(doc.direction) }}</td>
              <td class="px-5 py-3 text-center">
                <AppBadge :variant="urgencyVariant(doc.urgency)">{{ urgencyLabel(doc.urgency) }}</AppBadge>
              </td>
              <td class="px-5 py-3 text-center">
                <AppBadge :variant="doc.status === 'draft' ? 'gray' : 'info'">
                  {{ doc.status === 'draft' ? 'ฉบับร่าง' : 'เสนอแล้ว' }}
                </AppBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <AppButton size="sm" variant="ghost" @click="open(doc)">เปิดดู</AppButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="lastPage > 1" class="px-5 py-4 border-t border-gray-100">
        <AppPagination :page="page" :last-page="lastPage" @update:page="changePage" />
      </div>
    </section>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../axios'
import SavedViews from '../../components/saraban/SavedViews.vue'
import { getErrorMessage } from '../../utils/errorHandler'
import AppBadge from '../../components/ui/AppBadge.vue'
import AppButton from '../../components/ui/AppButton.vue'
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
const documents = ref([])
const loading = ref(true)
const error = ref('')
const page = ref(1)
const lastPage = ref(1)
const filters = reactive({ direction: '', status: '' })
const applySavedView = (saved) => { filters.direction = saved.direction || ''; filters.status = saved.status || ''; page.value = 1; load() }

const directionLabel = (value) => DIRECTIONS.find((d) => d.value === value)?.label || value
const urgencyLabel = (value) => URGENCY[value]?.label || value
const urgencyVariant = (value) => URGENCY[value]?.variant || 'gray'

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/saraban/documents', {
      params: { page: page.value, direction: filters.direction || undefined, status: filters.status || undefined },
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

onMounted(load)
</script>
