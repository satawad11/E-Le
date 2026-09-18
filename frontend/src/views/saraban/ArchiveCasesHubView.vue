<template>
  <div class="max-w-7xl mx-auto space-y-6">
    <AppBreadcrumb />

    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">คลังเอกสารและสารบบเรื่อง</h1>
        <p class="text-sm text-gray-500 mt-1">
          สืบค้นหนังสือราชการย้อนหลังทั้งหมด และจัดกลุ่มเอกสารที่เกี่ยวข้องกันไว้ในสารบบเรื่อง
        </p>
      </div>

      <div class="flex items-center gap-2">
        <AppButton
          v-if="activeTab === 'archive'"
          v-can="'saraban.document.create'"
          @click="openCreateDocument"
        >
          + สร้างหนังสือใหม่
        </AppButton>
        <AppButton
          v-else-if="activeTab === 'cases'"
          v-can="'saraban.document.create'"
          @click="openCreateCaseModal"
        >
          + เปิดเรื่องใหม่
        </AppButton>
      </div>
    </div>

    <!-- แท็บสลับ คลังหนังสือ / สารบบเรื่อง -->
    <div class="border-b border-gray-200">
      <nav class="flex space-x-6" aria-label="Tabs">
        <button
          type="button"
          class="pb-3 text-sm font-medium border-b-2 flex items-center gap-2 transition-colors"
          :class="activeTab === 'archive'
            ? 'border-[#800000] text-[#800000] font-semibold'
            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
          @click="switchTab('archive')"
        >
          <span>📁</span>
          <span>คลังหนังสือราชการทั้งหมด</span>
        </button>
        <button
          type="button"
          class="pb-3 text-sm font-medium border-b-2 flex items-center gap-2 transition-colors"
          :class="activeTab === 'cases'
            ? 'border-[#800000] text-[#800000] font-semibold'
            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
          @click="switchTab('cases')"
        >
          <span>📂</span>
          <span>สารบบเรื่อง (Cases / Dockets)</span>
        </button>
      </nav>
    </div>

    <!-- ==================== TAB 1: คลังหนังสือราชการทั้งหมด ==================== -->
    <div v-if="activeTab === 'archive'" class="space-y-4">
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center gap-3">
          <div class="flex-1">
            <h2 class="font-semibold text-gray-800">ค้นหาและสืบค้นเอกสาร</h2>
            <p class="text-sm text-gray-500 mt-0.5">ค้นหาตามเลขที่ เรื่อง ทิศทาง หรือชั้นความลับ</p>
          </div>
          <div class="flex flex-wrap gap-2 items-end">
            <div class="w-full sm:w-60">
              <AppInput
                v-model="archiveFilters.q"
                label="ค้นหา"
                placeholder="เรื่อง หรือเลขที่หนังสือ"
                @keyup.enter="fetchArchive"
              />
            </div>
            <AppSelect v-model="archiveFilters.direction" label="ทิศทาง" @update:modelValue="fetchArchive">
              <option value="">ทุกทิศทาง</option>
              <option value="incoming">หนังสือรับ</option>
              <option value="outgoing">หนังสือส่ง</option>
              <option value="internal">หนังสือภายใน</option>
            </AppSelect>
            <AppSelect v-model="archiveFilters.status" label="สถานะ" @update:modelValue="fetchArchive">
              <option value="">ทุกสถานะ</option>
              <option value="draft">ฉบับร่าง</option>
              <option value="submitted">เสนอแล้ว</option>
              <option value="signed">ลงนามแล้ว</option>
            </AppSelect>
          </div>
        </header>

        <LoadingState v-if="archiveLoading" type="skeleton-table" :rows="5" :columns="6" />
        <ErrorState v-else-if="archiveError" :message="archiveError" @retry="fetchArchive" />
        <EmptyState
          v-else-if="!archiveDocuments.length"
          title="ไม่พบหนังสือในคลัง"
          description="ลองเปลี่ยนคำค้นหาหรือตัวกรอง"
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm min-w-[760px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3 font-semibold">เลขที่หนังสือ</th>
                <th class="text-left px-5 py-3 font-semibold">เรื่อง</th>
                <th class="text-left px-5 py-3 font-semibold">ประเภท</th>
                <th class="text-center px-5 py-3 font-semibold">ทิศทาง</th>
                <th class="text-center px-5 py-3 font-semibold">ชั้นความลับ</th>
                <th class="text-center px-5 py-3 font-semibold">สถานะ</th>
                <th class="text-right px-5 py-3 font-semibold">จัดการ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="doc in archiveDocuments" :key="doc.id" class="hover:bg-gray-50/60">
                <td class="px-5 py-3 font-mono text-xs font-semibold text-gray-900">
                  {{ doc.document_number?.formatted || '—' }}
                </td>
                <td class="px-5 py-3 font-medium text-gray-900">{{ doc.subject }}</td>
                <td class="px-5 py-3 text-gray-600 text-xs">{{ doc.document_type?.name || '—' }}</td>
                <td class="px-5 py-3 text-center text-xs text-gray-600">{{ directionLabel(doc.direction) }}</td>
                <td class="px-5 py-3 text-center text-xs">
                  <span v-if="doc.confidentiality === 'top_secret'" class="text-red-700 font-bold">ลับที่สุด</span>
                  <span v-else-if="doc.confidentiality === 'secret'" class="text-orange-700 font-semibold">ลับมาก</span>
                  <span v-else-if="doc.confidentiality === 'confidential'" class="text-amber-700">ลับ</span>
                  <span v-else class="text-gray-400">ปกติ</span>
                </td>
                <td class="px-5 py-3 text-center">
                  <AppBadge :variant="statusBadgeVariant(doc.status)">{{ statusDisplayLabel(doc.status) }}</AppBadge>
                </td>
                <td class="px-5 py-3 text-right">
                  <RouterLink :to="`/saraban/documents/${doc.id}`" class="text-xs text-[#800000] hover:underline font-medium">
                    ดูรายละเอียด
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <!-- ==================== TAB 2: สารบบเรื่อง (CASES) ==================== -->
    <div v-else-if="activeTab === 'cases'" class="space-y-4">
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="flex-1">
            <h2 class="font-semibold text-gray-800">รายการสารบบเรื่อง</h2>
            <p class="text-sm text-gray-500 mt-0.5">รวมหนังสือหลายฉบับที่เกี่ยวข้องกับภารกิจหรือโครงการเดียวกัน</p>
          </div>
          <div class="w-full sm:w-72">
            <AppInput v-model="caseSearch" label="ค้นหาเรื่อง" placeholder="ชื่อเรื่องหรือรหัส" @keyup.enter="fetchCases" />
          </div>
        </header>

        <LoadingState v-if="casesLoading" type="skeleton-table" :rows="4" :columns="5" />
        <ErrorState v-else-if="casesError" :message="casesError" @retry="fetchCases" />
        <EmptyState
          v-else-if="!casesList.length"
          title="ยังไม่มีสารบบเรื่อง"
          description="เปิดเรื่องใหม่เพื่อรวมหนังสือที่เกี่ยวข้องกันไว้ด้วยกัน"
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm min-w-[680px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3 font-semibold">รหัสเรื่อง</th>
                <th class="text-left px-5 py-3 font-semibold">ชื่อเรื่อง</th>
                <th class="text-center px-5 py-3 font-semibold">จำนวนหนังสือ</th>
                <th class="text-center px-5 py-3 font-semibold">สถานะ</th>
                <th class="text-right px-5 py-3 font-semibold">จัดการ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="c in casesList" :key="c.id" class="hover:bg-gray-50/60">
                <td class="px-5 py-3 font-mono text-xs font-semibold text-gray-900">{{ c.code }}</td>
                <td class="px-5 py-3 font-medium text-gray-900">{{ c.title }}</td>
                <td class="px-5 py-3 text-center text-gray-600 font-semibold">{{ c.documents_count || 0 }} ฉบับ</td>
                <td class="px-5 py-3 text-center">
                  <AppBadge :variant="c.status === 'open' ? 'success' : 'gray'">
                    {{ c.status === 'open' ? 'เปิดอยู่' : 'ปิดแล้ว' }}
                  </AppBadge>
                </td>
                <td class="px-5 py-3 text-right">
                  <AppButton size="sm" variant="secondary" @click="openCaseDetail(c)">เปิดดูสารบบ</AppButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <!-- Modal เปิดเรื่องใหม่ -->
    <AppModal v-if="showNewCaseModal" :model-value="true" title="เปิดสารบบเรื่องใหม่" @update:model-value="showNewCaseModal = false">
      <form class="space-y-4" @submit.prevent="submitNewCase">
        <AppInput v-model="caseForm.title" label="ชื่อเรื่อง / โครงการ" placeholder="เช่น โครงการพัฒนาทักษะวิชาชีพ 2569" required />
        <AppInput v-model="caseForm.description" label="คำอธิบาย / รายละเอียดสังเขป" placeholder="วัตถุประสงค์และขอบเขตของเรื่อง" />
        <div class="flex justify-end gap-2 pt-2">
          <AppButton type="button" variant="secondary" @click="showNewCaseModal = false">ยกเลิก</AppButton>
          <AppButton type="submit" :loading="caseSubmitting">สร้างสารบบเรื่อง</AppButton>
        </div>
      </form>
    </AppModal>

    <!-- Modal รายละเอียดสารบบเรื่อง -->
    <AppModal v-if="selectedCase" :model-value="true" :title="`สารบบ: ${selectedCase.title}`" @update:model-value="selectedCase = null">
      <div class="space-y-4">
        <div class="text-sm text-gray-600 space-y-1">
          <p><span class="font-semibold text-gray-800">รหัสเรื่อง:</span> {{ selectedCase.code }}</p>
          <p><span class="font-semibold text-gray-800">คำอธิบาย:</span> {{ selectedCase.description || '—' }}</p>
        </div>
        <h4 class="font-semibold text-sm text-gray-800 pt-2 border-t">หนังสือในสารบบนี้ ({{ selectedCase.documents?.length || 0 }} ฉบับ)</h4>
        <div v-if="!selectedCase.documents?.length" class="text-sm text-gray-400 py-3 text-center">
          ยังไม่มีหนังสือผูกกับสารบบเรื่องนี้
        </div>
        <ul v-else class="divide-y divide-gray-100 max-h-60 overflow-y-auto">
          <li v-for="d in selectedCase.documents" :key="d.id" class="py-2 flex items-center justify-between text-sm">
            <div>
              <p class="font-medium text-gray-900">{{ d.subject }}</p>
              <p class="text-xs text-gray-500 font-mono">{{ d.document_number?.formatted || '—' }}</p>
            </div>
            <RouterLink :to="`/saraban/documents/${d.id}`" class="text-xs text-[#800000] hover:underline">
              เปิดหนังสือ →
            </RouterLink>
          </li>
        </ul>
      </div>
    </AppModal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../axios'
import AppBadge from '../../components/ui/AppBadge.vue'
import AppBreadcrumb from '../../components/ui/AppBreadcrumb.vue'
import AppButton from '../../components/ui/AppButton.vue'
import AppInput from '../../components/ui/AppInput.vue'
import AppModal from '../../components/ui/AppModal.vue'
import AppSelect from '../../components/ui/AppSelect.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import { getErrorMessage } from '../../utils/errorHandler'

const route = useRoute()
const router = useRouter()

const activeTab = ref(route.query.tab || 'archive')

// Archive state
const archiveDocuments = ref([])
const archiveLoading = ref(false)
const archiveError = ref(null)
const archiveFilters = reactive({
  q: '',
  direction: '',
  status: '',
})

// Cases state
const casesList = ref([])
const casesLoading = ref(false)
const casesError = ref(null)
const caseSearch = ref('')
const showNewCaseModal = ref(false)
const caseSubmitting = ref(false)
const selectedCase = ref(null)
const caseForm = reactive({
  title: '',
  description: '',
})

function switchTab(tabKey) {
  activeTab.value = tabKey
  router.replace({ query: { ...route.query, tab: tabKey } })
  if (tabKey === 'archive') fetchArchive()
  else if (tabKey === 'cases') fetchCases()
}

function openCreateDocument() {
  router.push('/saraban/documents/new')
}

function openCreateCaseModal() {
  caseForm.title = ''
  caseForm.description = ''
  showNewCaseModal.value = true
}

function directionLabel(dir) {
  switch (dir) {
    case 'incoming': return 'หนังสือรับ'
    case 'outgoing': return 'หนังสือส่ง'
    case 'internal': return 'หนังสือภายใน'
    default: return dir || '—'
  }
}

function statusBadgeVariant(status) {
  switch (status) {
    case 'signed': return 'success'
    case 'submitted': return 'info'
    case 'draft': return 'gray'
    default: return 'gray'
  }
}

function statusDisplayLabel(status) {
  switch (status) {
    case 'draft': return 'ฉบับร่าง'
    case 'submitted': return 'เสนอแล้ว'
    case 'registered': return 'ลงทะเบียนแล้ว'
    case 'signed': return 'ลงนามแล้ว'
    case 'cancelled': return 'ยกเลิก'
    default: return status || '—'
  }
}

async function fetchArchive() {
  archiveLoading.value = true
  archiveError.value = null
  try {
    const params = {
      q: archiveFilters.q || undefined,
      direction: archiveFilters.direction || undefined,
      status: archiveFilters.status || undefined,
    }
    const res = await api.get('/saraban/documents', { params })
    archiveDocuments.value = res.data.data || []
  } catch (err) {
    archiveError.value = getErrorMessage(err)
  } finally {
    archiveLoading.value = false
  }
}

async function fetchCases() {
  casesLoading.value = true
  casesError.value = null
  try {
    const params = {
      search: caseSearch.value || undefined,
    }
    const res = await api.get('/saraban/cases', { params })
    casesList.value = res.data.data || []
  } catch (err) {
    casesError.value = getErrorMessage(err)
  } finally {
    casesLoading.value = false
  }
}

async function submitNewCase() {
  caseSubmitting.value = true
  try {
    await api.post('/saraban/cases', caseForm)
    showNewCaseModal.value = false
    fetchCases()
  } catch (err) {
    alert(getErrorMessage(err))
  } finally {
    caseSubmitting.value = false
  }
}

async function openCaseDetail(c) {
  try {
    const res = await api.get(`/saraban/cases/${c.id}`)
    selectedCase.value = res.data.data || c
  } catch {
    selectedCase.value = c
  }
}

watch(() => route.query.tab, (newTab) => {
  if (newTab && newTab !== activeTab.value) {
    activeTab.value = newTab
    if (newTab === 'archive') fetchArchive()
    else if (newTab === 'cases') fetchCases()
  }
})

onMounted(() => {
  if (activeTab.value === 'archive') fetchArchive()
  else if (activeTab.value === 'cases') fetchCases()
})
</script>
