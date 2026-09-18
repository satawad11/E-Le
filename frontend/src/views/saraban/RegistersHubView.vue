<template>
  <div class="max-w-7xl mx-auto space-y-6">
    <AppBreadcrumb />

    <!-- ส่วนหัวหน้าจอศูนย์ทะเบียนสารบรรณ -->
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">สมุดทะเบียนสารบรรณ</h1>
        <p class="text-sm text-gray-500 mt-1">
          ศูนย์รวมทะเบียนหนังสือรับ หนังสือส่ง หนังสือภายใน และบัญชีการจองเลขหนังสือราชการ
        </p>
      </div>

      <!-- ปุ่ม Action ด่วนตามสิทธิ์ -->
      <div class="flex flex-wrap items-center gap-2">
        <AppButton
          v-if="activeTab === 'incoming'"
          v-can="'saraban.incoming.register'"
          @click="openIncomingRegister"
        >
          + ลงทะเบียนรับใหม่
        </AppButton>
        <AppButton
          v-else-if="activeTab === 'outgoing'"
          v-can="'saraban.document.create'"
          @click="openNewDocument('outgoing')"
        >
          + สร้างหนังสือส่ง
        </AppButton>
        <AppButton
          v-else-if="activeTab === 'internal'"
          v-can="'saraban.document.create'"
          @click="openNewDocument('internal')"
        >
          + สร้างบันทึกข้อความ
        </AppButton>
        <AppButton
          v-else-if="activeTab === 'reservations'"
          v-can="'saraban.number.reserve'"
          @click="showReserveModal = true"
        >
          + จองเลขหนังสือใหม่
        </AppButton>
      </div>
    </div>

    <!-- แท็บสลับสมุดทะเบียน 4 เล่มหลัก -->
    <div class="border-b border-gray-200">
      <nav class="flex space-x-6" aria-label="Tabs">
        <button
          v-for="tab in TABS"
          :key="tab.key"
          type="button"
          class="pb-3 text-sm font-medium border-b-2 flex items-center gap-2 transition-colors"
          :class="activeTab === tab.key
            ? 'border-[#800000] text-[#800000] font-semibold'
            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
          @click="switchTab(tab.key)"
        >
          <span>{{ tab.icon }}</span>
          <span>{{ tab.label }}</span>
        </button>
      </nav>
    </div>

    <!-- ==================== TAB 1: ทะเบียนหนังสือรับ (INCOMING) ==================== -->
    <div v-if="activeTab === 'incoming'" class="space-y-4">
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-end gap-3">
          <div class="flex-1">
            <h2 class="font-semibold text-gray-800">รายการหนังสือรับเข้า</h2>
            <p class="text-sm text-gray-500 mt-0.5">เรียงจากที่ลงทะเบียนรับล่าสุด</p>
          </div>
          <div class="grid grid-cols-2 sm:flex sm:items-end gap-3 w-full sm:w-auto">
            <AppSelect v-model="incomingFilters.buddhist_year" label="ปี พ.ศ." @update:model-value="fetchIncoming">
              <option value="">ทั้งหมด</option>
              <option v-for="year in incomingYears" :key="year" :value="year">{{ year }}</option>
            </AppSelect>
            <AppSelect v-model="incomingFilters.document_type_id" label="ประเภทหนังสือ" @update:model-value="fetchIncoming">
              <option value="">ทั้งหมด</option>
              <option v-for="type in documentTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
            </AppSelect>
            <div class="col-span-2 sm:w-64">
              <AppInput
                v-model="incomingSearch"
                label="ค้นหา"
                placeholder="เรื่อง ผู้ส่ง หรือเลขที่ต้นทาง"
                @keyup.enter="fetchIncoming"
              />
            </div>
          </div>
        </header>

        <LoadingState v-if="incomingLoading" type="skeleton-table" :rows="5" :columns="5" />
        <ErrorState v-else-if="incomingError" :message="incomingError" @retry="fetchIncoming" />
        <EmptyState
          v-else-if="!incomingRegistrations.length"
          title="ยังไม่มีหนังสือในทะเบียนรับ"
          description="เริ่มต้นด้วยการลงทะเบียนรับหนังสือฉบับแรก"
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm min-w-[820px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3 font-semibold">เลขทะเบียนรับ</th>
                <th class="text-left px-5 py-3 font-semibold">เรื่อง</th>
                <th class="text-left px-5 py-3 font-semibold">จากหน่วยงาน</th>
                <th class="text-left px-5 py-3 font-semibold">เลขที่ต้นทาง</th>
                <th class="text-left px-5 py-3 font-semibold">วันเวลาที่รับ</th>
                <th class="text-right px-5 py-3 font-semibold">จัดการ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="row in incomingRegistrations" :key="row.id" class="hover:bg-gray-50/60">
                <td class="px-5 py-3 font-mono text-xs font-semibold text-[#800000]">
                  {{ row.register_number?.formatted || '—' }}
                </td>
                <td class="px-5 py-3 font-medium text-gray-900">
                  <button type="button" class="text-left hover:text-[#800000] hover:underline" @click="openSelectedIncoming(row)">
                    {{ row.document?.subject || '—' }}
                  </button>
                </td>
                <td class="px-5 py-3 text-gray-600">{{ row.sender_name }}</td>
                <td class="px-5 py-3 text-gray-500">
                  {{ row.source_number || '—' }}
                  <span v-if="row.source_date" class="text-xs text-gray-400 block">
                    ลงวันที่ {{ row.source_date }}
                  </span>
                </td>
                <td class="px-5 py-3 text-gray-500 text-xs">{{ row.received_at }}</td>
                <td class="px-5 py-3 text-right">
                  <RouterLink
                    v-if="row.document?.id"
                    :to="`/saraban/documents/${row.document.id}`"
                    class="text-xs text-[#800000] hover:underline font-medium"
                  >
                    เปิดเอกสาร
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="incomingLastPage > 1" class="px-5 py-4 border-t border-gray-100">
          <AppPagination :page="incomingPage" :last-page="incomingLastPage" @update:page="changeIncomingPage" />
        </div>
      </section>
    </div>

    <!-- ==================== TAB 2: ทะเบียนหนังสือส่ง (OUTGOING) ==================== -->
    <div v-else-if="activeTab === 'outgoing'" class="space-y-4">
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center gap-3">
          <div class="flex-1">
            <h2 class="font-semibold text-gray-800">ทะเบียนหนังสือส่งออกภายนอก</h2>
            <p class="text-sm text-gray-500 mt-0.5">หนังสือที่ออกเลขส่งไปยังหน่วยงานภายนอกสถานศึกษา</p>
          </div>
          <div class="flex flex-wrap gap-2 items-end">
            <div class="w-full sm:w-56">
              <AppInput
                v-model="outgoingSearch"
                label="ค้นหา"
                placeholder="เรื่อง หรือเลขที่หนังสือ"
                @keyup.enter="fetchOutgoing"
              />
            </div>
            <AppSelect v-model="outgoingStatus" aria-label="กรองตามสถานะ" @update:modelValue="fetchOutgoing">
              <option value="">ทุกสถานะ</option>
              <option value="draft">ฉบับร่าง</option>
              <option value="submitted">เสนอแล้ว</option>
              <option value="signed">ลงนามแล้ว</option>
            </AppSelect>
          </div>
        </header>

        <LoadingState v-if="outgoingLoading" type="skeleton-table" :rows="5" :columns="6" />
        <ErrorState v-else-if="outgoingError" :message="outgoingError" @retry="fetchOutgoing" />
        <EmptyState
          v-else-if="!outgoingDocuments.length"
          title="ยังไม่มีหนังสือในทะเบียนส่ง"
          description="กดปุ่มสร้างหนังสือส่งเพื่อออกเลขหนังสือส่งฉบับแรก"
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm min-w-[760px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3 font-semibold">เลขที่หนังสือ</th>
                <th class="text-left px-5 py-3 font-semibold">เรื่อง</th>
                <th class="text-left px-5 py-3 font-semibold">ผู้รับปลายทาง</th>
                <th class="text-center px-5 py-3 font-semibold">ชั้นความเร็ว</th>
                <th class="text-center px-5 py-3 font-semibold">สถานะ</th>
                <th class="text-right px-5 py-3 font-semibold">จัดการ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="doc in outgoingDocuments" :key="doc.id" class="hover:bg-gray-50/60">
                <td class="px-5 py-3 font-mono text-xs font-semibold text-gray-900">
                  {{ doc.document_number?.formatted || '— (ยังไม่ออกเลข)' }}
                </td>
                <td class="px-5 py-3 font-medium text-gray-900">{{ doc.subject }}</td>
                <td class="px-5 py-3 text-gray-600 text-xs">{{ doc.recipient_name || '—' }}</td>
                <td class="px-5 py-3 text-center">
                  <AppBadge :variant="urgencyVariant(doc.urgency)">{{ urgencyLabel(doc.urgency) }}</AppBadge>
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

    <!-- ==================== TAB 3: ทะเบียนหนังสือภายใน (INTERNAL) ==================== -->
    <div v-else-if="activeTab === 'internal'" class="space-y-4">
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center gap-3">
          <div class="flex-1">
            <h2 class="font-semibold text-gray-800">ทะเบียนหนังสือภายใน (บันทึกข้อความ)</h2>
            <p class="text-sm text-gray-500 mt-0.5">หนังสือติดต่อราชการ บันทึกข้อความ และคำสั่งภายในสถานศึกษา</p>
          </div>
          <div class="flex flex-wrap gap-2 items-end">
            <div class="w-full sm:w-56">
              <AppInput
                v-model="internalSearch"
                label="ค้นหา"
                placeholder="เรื่อง หรือเลขที่หนังสือ"
                @keyup.enter="fetchInternal"
              />
            </div>
            <AppSelect v-model="internalStatus" aria-label="กรองตามสถานะ" @update:modelValue="fetchInternal">
              <option value="">ทุกสถานะ</option>
              <option value="draft">ฉบับร่าง</option>
              <option value="submitted">เสนอแล้ว</option>
              <option value="signed">ลงนามแล้ว</option>
            </AppSelect>
          </div>
        </header>

        <LoadingState v-if="internalLoading" type="skeleton-table" :rows="5" :columns="6" />
        <ErrorState v-else-if="internalError" :message="internalError" @retry="fetchInternal" />
        <EmptyState
          v-else-if="!internalDocuments.length"
          title="ยังไม่มีหนังสือในทะเบียนภายใน"
          description="กดปุ่มสร้างบันทึกข้อความเพื่อเริ่มต้น"
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm min-w-[760px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3 font-semibold">เลขที่หนังสือ</th>
                <th class="text-left px-5 py-3 font-semibold">เรื่อง</th>
                <th class="text-left px-5 py-3 font-semibold">หน่วยงานเจ้าของเรื่อง</th>
                <th class="text-center px-5 py-3 font-semibold">ชั้นความเร็ว</th>
                <th class="text-center px-5 py-3 font-semibold">สถานะ</th>
                <th class="text-right px-5 py-3 font-semibold">จัดการ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="doc in internalDocuments" :key="doc.id" class="hover:bg-gray-50/60">
                <td class="px-5 py-3 font-mono text-xs font-semibold text-gray-900">
                  {{ doc.document_number?.formatted || '— (ยังไม่ออกเลข)' }}
                </td>
                <td class="px-5 py-3 font-medium text-gray-900">{{ doc.subject }}</td>
                <td class="px-5 py-3 text-gray-600 text-xs">{{ doc.creator_org_unit?.name || '—' }}</td>
                <td class="px-5 py-3 text-center">
                  <AppBadge :variant="urgencyVariant(doc.urgency)">{{ urgencyLabel(doc.urgency) }}</AppBadge>
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

    <!-- ==================== TAB 4: บัญชีจองเลขหนังสือ (RESERVATIONS) ==================== -->
    <div v-else-if="activeTab === 'reservations'" class="space-y-4">
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div>
            <h2 class="font-semibold text-gray-800">รายการจองเลขหนังสือราชการ</h2>
            <p class="text-sm text-gray-500 mt-0.5">
              เลขที่ถูกจองไว้ล่วงหน้าสำหรับโครงการ/ภารกิจที่เตรียมออกหนังสือ
            </p>
          </div>
          <AppButton v-can="'saraban.number.reserve'" size="sm" @click="showReserveModal = true">
            + จองเลขใหม่
          </AppButton>
        </header>

        <LoadingState v-if="reservationsLoading" type="skeleton-table" :rows="4" :columns="5" />
        <ErrorState v-else-if="reservationsError" :message="reservationsError" @retry="fetchReservations" />
        <EmptyState
          v-else-if="!reservations.length"
          title="ยังไม่มีรายการจองเลขที่ยังไม่ใช้งาน"
          description="เมื่อจองเลขไว้เพื่อออกหนังสือ รายการจะแสดงที่นี่"
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm min-w-[720px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3 font-semibold">เลขที่จอง</th>
                <th class="text-left px-5 py-3 font-semibold">เหตุผล/เรื่องที่จอง</th>
                <th class="text-left px-5 py-3 font-semibold">ผู้จอง</th>
                <th class="text-left px-5 py-3 font-semibold">จองเมื่อ</th>
                <th class="text-right px-5 py-3 font-semibold">จัดการ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="res in reservations" :key="res.id" class="hover:bg-gray-50/60">
                <td class="px-5 py-3 font-mono text-sm font-bold text-[#800000]">
                  {{ res.formatted_number || res.running_number }}
                </td>
                <td class="px-5 py-3 text-gray-900">{{ res.reason || '—' }}</td>
                <td class="px-5 py-3 text-gray-600 text-xs">{{ res.reserved_by?.name || '—' }}</td>
                <td class="px-5 py-3 text-gray-500 text-xs">{{ res.reserved_at }}</td>
                <td class="px-5 py-3 text-right">
                  <AppButton
                    v-can="'saraban.document.create'"
                    size="sm"
                    variant="secondary"
                    @click="useReservedNumber(res)"
                  >
                    นำไปสร้างหนังสือ
                  </AppButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <!-- Modal รายละเอียดหนังสือรับ -->
    <AppModal v-if="selectedIncoming" :model-value="true" title="รายละเอียดทะเบียนรับ" @update:model-value="selectedIncoming = null">
      <div class="space-y-3">
        <p class="font-medium text-gray-900">{{ selectedIncoming.document?.subject }}</p>
        <p class="text-sm text-gray-600">
          เลขทะเบียนรับ: <span class="font-mono font-bold text-[#800000]">{{ selectedIncoming.register_number?.formatted || '—' }}</span>
        </p>
        <p class="text-sm text-gray-600">จาก: {{ selectedIncoming.sender_name }} · เลขที่ต้นทาง {{ selectedIncoming.source_number || '—' }}</p>
        <p class="text-sm text-gray-600">รับเมื่อ: {{ selectedIncoming.received_at }} โดย {{ selectedIncoming.registrar?.name || '—' }}</p>
        <div class="pt-2">
          <RouterLink
            v-if="selectedIncoming.document?.id"
            :to="`/saraban/documents/${selectedIncoming.document.id}`"
            class="text-sm font-semibold text-[#800000] hover:underline"
          >
            เปิดดูรายละเอียดหนังสือฉบับเต็ม →
          </RouterLink>
        </div>
      </div>
    </AppModal>

    <!-- Modal จองเลขหนังสือใหม่ -->
    <AppModal v-if="showReserveModal" :model-value="true" title="จองเลขหนังสือราชการ" @update:model-value="showReserveModal = false">
      <form class="space-y-4" @submit.prevent="submitReserve">
        <AppSelect v-model="reserveForm.number_series_id" label="ชุดเลขหนังสือ" required>
          <option value="">เลือกชุดเลข</option>
          <option v-for="s in numberSeriesList" :key="s.id" :value="s.id">{{ s.name }} ({{ s.prefix }})</option>
        </AppSelect>
        <AppInput v-model="reserveForm.reason" label="เหตุผล / เรื่องที่ต้องการจอง" placeholder="เช่น ร่างคำสั่งแต่งตั้งกรรมการโครงการ" required />
        <div class="flex justify-end gap-2 pt-2">
          <AppButton type="button" variant="secondary" @click="showReserveModal = false">ยกเลิก</AppButton>
          <AppButton type="submit" :loading="reserveSubmitting">ยืนยันการจองเลข</AppButton>
        </div>
      </form>
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
import AppPagination from '../../components/ui/AppPagination.vue'
import AppSelect from '../../components/ui/AppSelect.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import { getErrorMessage } from '../../utils/errorHandler'

const route = useRoute()
const router = useRouter()

const TABS = [
  { key: 'incoming', label: 'ทะเบียนหนังสือรับ', icon: '📥' },
  { key: 'outgoing', label: 'ทะเบียนหนังสือส่ง', icon: '📤' },
  { key: 'internal', label: 'ทะเบียนหนังสือภายใน', icon: '📝' },
  { key: 'reservations', label: 'บัญชีจองเลขหนังสือ', icon: '🔢' },
]

const activeTab = ref(route.query.tab || 'incoming')

// Tab 1: Incoming state
const incomingRegistrations = ref([])
const incomingLoading = ref(false)
const incomingError = ref(null)
const incomingPage = ref(1)
const incomingLastPage = ref(1)
const incomingYears = ref([])
const incomingSearch = ref('')
const incomingFilters = reactive({
  buddhist_year: '',
  document_type_id: '',
})
const selectedIncoming = ref(null)

// Tab 2: Outgoing state
const outgoingDocuments = ref([])
const outgoingLoading = ref(false)
const outgoingError = ref(null)
const outgoingSearch = ref('')
const outgoingStatus = ref('')

// Tab 3: Internal state
const internalDocuments = ref([])
const internalLoading = ref(false)
const internalError = ref(null)
const internalSearch = ref('')
const internalStatus = ref('')

// Tab 4: Reservations state
const reservations = ref([])
const reservationsLoading = ref(false)
const reservationsError = ref(null)
const showReserveModal = ref(false)
const reserveSubmitting = ref(false)
const numberSeriesList = ref([])
const reserveForm = reactive({
  number_series_id: '',
  reason: '',
})

// Shared document types
const documentTypes = ref([])

function switchTab(tabKey) {
  activeTab.value = tabKey
  router.replace({ query: { ...route.query, tab: tabKey } })
  loadTabData(tabKey)
}

function openIncomingRegister() {
  router.push('/saraban/incoming/new')
}

function openNewDocument(direction) {
  router.push({ path: '/saraban/documents/new', query: { direction } })
}

function openSelectedIncoming(row) {
  selectedIncoming.value = row
}

function urgencyVariant(urgency) {
  switch (urgency) {
    case 'most_urgent': return 'danger'
    case 'very_urgent': return 'warning'
    case 'urgent': return 'warning'
    default: return 'gray'
  }
}

function urgencyLabel(urgency) {
  switch (urgency) {
    case 'most_urgent': return 'ด่วนที่สุด'
    case 'very_urgent': return 'ด่วนมาก'
    case 'urgent': return 'ด่วน'
    default: return 'ปกติ'
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

async function fetchIncoming() {
  incomingLoading.value = true
  incomingError.value = null
  try {
    const params = {
      page: incomingPage.value,
      search: incomingSearch.value || undefined,
      buddhist_year: incomingFilters.buddhist_year || undefined,
      document_type_id: incomingFilters.document_type_id || undefined,
    }
    const res = await api.get('/saraban/incoming', { params })
    incomingRegistrations.value = res.data.data || []
    incomingLastPage.value = res.data.last_page || 1
    if (res.data.years) {
      incomingYears.value = res.data.years
    }
  } catch (err) {
    incomingError.value = getErrorMessage(err)
  } finally {
    incomingLoading.value = false
  }
}

function changeIncomingPage(p) {
  incomingPage.value = p
  fetchIncoming()
}

async function fetchOutgoing() {
  outgoingLoading.value = true
  outgoingError.value = null
  try {
    const params = {
      direction: 'outgoing',
      q: outgoingSearch.value || undefined,
      status: outgoingStatus.value || undefined,
    }
    const res = await api.get('/saraban/documents', { params })
    outgoingDocuments.value = res.data.data || []
  } catch (err) {
    outgoingError.value = getErrorMessage(err)
  } finally {
    outgoingLoading.value = false
  }
}

async function fetchInternal() {
  internalLoading.value = true
  internalError.value = null
  try {
    const params = {
      direction: 'internal',
      q: internalSearch.value || undefined,
      status: internalStatus.value || undefined,
    }
    const res = await api.get('/saraban/documents', { params })
    internalDocuments.value = res.data.data || []
  } catch (err) {
    internalError.value = getErrorMessage(err)
  } finally {
    internalLoading.value = false
  }
}

async function fetchReservations() {
  reservationsLoading.value = true
  reservationsError.value = null
  try {
    const res = await api.get('/saraban/number-reservations')
    reservations.value = res.data.data || []
  } catch (err) {
    reservationsError.value = getErrorMessage(err)
  } finally {
    reservationsLoading.value = false
  }
}

async function fetchSharedData() {
  try {
    const [typesRes, seriesRes] = await Promise.allSettled([
      api.get('/saraban/documents/options'),
      api.get('/saraban/number-series'),
    ])
    if (typesRes.status === 'fulfilled') {
      documentTypes.value = typesRes.value.data.document_types || []
    }
    if (seriesRes.status === 'fulfilled') {
      numberSeriesList.value = seriesRes.value.data.data || []
    }
  } catch (e) {
    console.error('Error fetching options:', e)
  }
}

async function submitReserve() {
  reserveSubmitting.value = true
  try {
    await api.post('/saraban/number-reservations', reserveForm)
    showReserveModal.value = false
    reserveForm.reason = ''
    fetchReservations()
  } catch (err) {
    alert(getErrorMessage(err))
  } finally {
    reserveSubmitting.value = false
  }
}

function useReservedNumber(reservation) {
  router.push({
    path: '/saraban/documents/new',
    query: {
      reservation_id: reservation.id,
      number_series_id: reservation.number_series_id,
    },
  })
}

function loadTabData(tabKey) {
  if (tabKey === 'incoming') fetchIncoming()
  else if (tabKey === 'outgoing') fetchOutgoing()
  else if (tabKey === 'internal') fetchInternal()
  else if (tabKey === 'reservations') fetchReservations()
}

watch(() => route.query.tab, (newTab) => {
  if (newTab && newTab !== activeTab.value) {
    activeTab.value = newTab
    loadTabData(newTab)
  }
})

onMounted(() => {
  fetchSharedData()
  loadTabData(activeTab.value)
})
</script>
