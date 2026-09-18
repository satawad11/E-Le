<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <AppBreadcrumb />

    <!-- หัวข้อหน้าจอและแท็บสลับ -->
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">กล่องงานและหนังสือของฉัน</h1>
        <p class="text-sm text-gray-500 mt-1">
          การเปิดอ่านไม่นับเป็นการรับทราบ ต้องกดรับทราบทุกฉบับ
        </p>
      </div>

      <div class="flex items-center gap-2">
        <RouterLink
          to="/saraban/documents/new"
          class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-xl shadow-xs text-white bg-[#800000] hover:bg-[#600000] transition-colors"
        >
          + สร้างหนังสือใหม่
        </RouterLink>
      </div>
    </div>

    <!-- แถบเลือกแท็บงาน -->
    <div class="border-b border-gray-200">
      <nav class="flex space-x-6 overflow-x-auto" aria-label="Tabs">
        <button
          v-for="tab in INBOX_TABS"
          :key="tab.key"
          type="button"
          class="pb-3 text-sm font-medium border-b-2 flex items-center gap-2 whitespace-nowrap transition-colors"
          :class="activeTab === tab.key
            ? 'border-[#800000] text-[#800000] font-semibold'
            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
          @click="switchTab(tab.key)"
        >
          <span>{{ tab.icon }}</span>
          <span>{{ tab.label }}</span>
          <span
            v-if="tab.badge > 0"
            class="px-2 py-0.5 rounded-full text-xs font-bold text-white bg-[#800000]"
          >
            {{ tab.badge }}
          </span>
        </button>
      </nav>
    </div>

    <!-- ==================== TAB 1: หนังสือเวียน / เพื่อทราบ (INBOX - DEFAULT) ==================== -->
    <div v-if="activeTab === 'inbox'" class="space-y-4">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white p-4 border border-gray-100 rounded-2xl shadow-xs">
        <span class="text-sm text-gray-600">หนังสือที่ส่งเวียนมาถึงท่าน — การเปิดอ่านไม่นับเป็นการรับทราบ ต้องกดรับทราบ</span>
        <label class="inline-flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
          <input
            v-model="pendingOnly"
            type="checkbox"
            class="rounded border-gray-300 text-[#800000] focus:ring-[#800000]"
            @change="applyInboxFilter"
          >
          เฉพาะที่ยังไม่รับทราบ
          <span
            v-if="unacknowledged > 0"
            class="ml-1 inline-flex items-center justify-center min-w-6 h-6 px-2 rounded-full text-xs font-semibold text-white bg-[#800000]"
          >{{ unacknowledged }}</span>
        </label>
      </div>

      <!-- แถบแจ้งเตือนการมอบอำนาจ -->
      <div
        v-if="delegators.length > 0"
        class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5 bg-amber-50/70 border border-amber-200/80 rounded-xl text-xs sm:text-sm text-amber-900"
      >
        <div class="flex items-center gap-2">
          <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-200 text-amber-900">
            การมอบอำนาจ
          </span>
          <span>ท่านได้รับมอบอำนาจปฏิบัติราชการแทน: <strong class="font-semibold">{{ delegatorNames }}</strong></span>
        </div>
      </div>

      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <LoadingState v-if="loading" type="skeleton-table" :rows="5" :columns="5" />
        <ErrorState v-else-if="error" :message="error" @retry="loadInbox" />
        <EmptyState
          v-else-if="!items.length"
          title="ยังไม่มีหนังสือเวียนถึงท่าน"
          description="เมื่อมีหนังสือเวียนมา รายการจะปรากฏที่นี่"
        />

        <div v-else>
          <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[820px]">
              <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                <tr>
                  <th class="text-left px-5 py-3 font-semibold">เลขที่หนังสือ</th>
                  <th class="text-left px-5 py-3 font-semibold">เรื่อง</th>
                  <th class="text-left px-5 py-3 font-semibold">ผู้ส่ง</th>
                  <th class="text-left px-5 py-3 font-semibold">ได้รับเมื่อ</th>
                  <th class="text-left px-5 py-3 font-semibold">สถานะ</th>
                  <th class="px-5 py-3" />
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-50">
                <tr
                  v-for="item in items"
                  :key="item.id"
                  class="hover:bg-gray-50/60"
                  :class="{ 'font-semibold': !item.acknowledged_at }"
                >
                  <td class="px-5 py-3 font-mono text-xs text-gray-900">
                    {{ item.document?.document_number || '—' }}
                  </td>
                  <td class="px-5 py-3 text-gray-900">
                    <div class="flex items-center gap-1.5 flex-wrap">
                      <span>{{ item.document?.subject }}</span>
                    </div>
                  </td>
                  <td class="px-5 py-3 text-gray-600 text-xs">{{ item.sent_by }}</td>
                  <td class="px-5 py-3 text-gray-500 text-xs">{{ formatDateTime(item.delivered_at) }}</td>
                  <td class="px-5 py-3">
                    <AppBadge :variant="statusVariant(item.status)">{{ statusLabel(item.status) }}</AppBadge>
                  </td>
                  <td class="px-5 py-3 text-right">
                    <AppButton size="sm" variant="secondary" @click="openInboxModal(item)">เปิดอ่าน</AppButton>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>

    <!-- ==================== TAB 2: รอพิจารณา / รอลงนาม (QUEUE) ==================== -->
    <div v-else-if="activeTab === 'queue'" class="space-y-4">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white p-4 border border-gray-100 rounded-2xl shadow-xs">
        <span class="text-sm text-gray-600">หนังสือที่เดินมาถึงท่านตามสายงาน และท่านลงมือทำได้ในขณะนี้</span>
        <div class="flex items-center gap-2">
          <button
            type="button"
            class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors border"
            :class="queueUrgencyFilter === 'all' ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'"
            @click="queueUrgencyFilter = 'all'"
          >
            ทั้งหมด ({{ queueItems.length }})
          </button>
          <button
            type="button"
            class="px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors border flex items-center gap-1.5"
            :class="queueUrgencyFilter === 'urgent' ? 'bg-red-600 text-white border-red-600 shadow-xs' : 'bg-white text-red-600 border-red-200 hover:bg-red-50'"
            @click="queueUrgencyFilter = 'urgent'"
          >
            <span v-if="queueUrgentCount > 0" class="w-2 h-2 rounded-full bg-current animate-ping" />
            เฉพาะด่วนที่สุด / ด่วนมาก ({{ queueUrgentCount }})
          </button>
        </div>
      </div>

      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <LoadingState v-if="queueLoading" type="skeleton-table" :rows="4" :columns="5" />
        <ErrorState v-else-if="queueError" :message="queueError" @retry="loadQueue" />
        <EmptyState
          v-else-if="!filteredQueueItems.length"
          title="ไม่มีหนังสือที่รอท่านดำเนินการ"
          :description="queueUrgencyFilter === 'urgent' ? 'ไม่มีหนังสือด่วนที่สุดหรือด่วนมากในคิวขณะนี้' : 'เมื่อมีเรื่องเดินมาถึงท่านตามสายงาน รายการจะปรากฏที่นี่'"
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm min-w-[760px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3 font-semibold">เลขที่หนังสือ</th>
                <th class="text-left px-5 py-3 font-semibold">เรื่อง</th>
                <th class="text-left px-5 py-3 font-semibold">หน่วยงานเจ้าของเรื่อง</th>
                <th class="text-left px-5 py-3 font-semibold">ขั้นตอน</th>
                <th class="text-right px-5 py-3 font-semibold">จัดการ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr
                v-for="item in filteredQueueItems"
                :key="item.document_id"
                :class="item.urgency === 'most_urgent' ? 'bg-red-50/40 border-l-4 border-l-red-500 hover:bg-red-50/70' : 'hover:bg-gray-50'"
              >
                <td class="px-5 py-3 whitespace-nowrap font-medium font-mono text-xs text-gray-900">{{ item.document_number || '—' }}</td>
                <td class="px-5 py-3">
                  <span class="font-medium text-gray-900">{{ item.subject }}</span>
                  <span
                    v-if="item.urgency === 'most_urgent'"
                    class="ms-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-red-600 text-white animate-pulse shadow-xs"
                  >ด่วนที่สุด</span>
                  <span
                    v-else-if="item.urgency === 'very_urgent'"
                    class="ms-2 inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-orange-100 text-orange-800 border border-orange-200"
                  >ด่วนมาก</span>
                  <span
                    v-else-if="item.urgency === 'urgent'"
                    class="ms-2 inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-amber-100 text-amber-800 border border-amber-200"
                  >ด่วน</span>
                </td>
                <td class="px-5 py-3 text-gray-600 text-xs">{{ item.owner_org_unit || '—' }}</td>
                <td class="px-5 py-3 text-gray-700 text-xs">{{ item.current_step || '—' }}</td>
                <td class="px-5 py-3 text-right">
                  <AppButton size="sm" variant="secondary" @click="openQueueItem(item)">เปิดเรื่อง</AppButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <!-- ==================== TAB 3: เรื่องที่ฉันส่ง / ฉบับร่าง (MY DRAFTS) ==================== -->
    <div v-else-if="activeTab === 'my_drafts'" class="space-y-4">
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div>
            <h2 class="font-semibold text-gray-800">เรื่องที่ฉันเป็นเจ้าของ / ยกร่าง</h2>
            <p class="text-sm text-gray-500 mt-0.5">ติดตามสถานะหนังสือราชการและบันทึกข้อความที่ท่านสร้างขึ้น</p>
          </div>
        </header>

        <LoadingState v-if="myDraftsLoading" type="skeleton-table" :rows="4" :columns="5" />
        <ErrorState v-else-if="myDraftsError" :message="myDraftsError" @retry="loadMyDrafts" />
        <EmptyState
          v-else-if="!myDraftsList.length"
          title="ยังไม่มีหนังสือที่ท่านสร้าง"
          description="กดปุ่มสร้างหนังสือใหม่เพื่อเริ่มต้นร่างหนังสือราชการ"
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm min-w-[760px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3 font-semibold">เลขที่หนังสือ</th>
                <th class="text-left px-5 py-3 font-semibold">เรื่อง</th>
                <th class="text-left px-5 py-3 font-semibold">ประเภท</th>
                <th class="text-center px-5 py-3 font-semibold">สถานะ</th>
                <th class="text-right px-5 py-3 font-semibold">จัดการ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="doc in myDraftsList" :key="doc.id" class="hover:bg-gray-50/60">
                <td class="px-5 py-3 font-mono text-xs font-semibold text-gray-900">
                  {{ doc.document_number?.formatted || '— (ยังไม่ออกเลข)' }}
                </td>
                <td class="px-5 py-3 font-medium text-gray-900">{{ doc.subject }}</td>
                <td class="px-5 py-3 text-gray-600 text-xs">{{ doc.document_type?.name || '—' }}</td>
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

    <!-- Modal รายละเอียดหนังสือเวียน & รับทราบ -->
    <AppModal
      v-if="selected"
      :model-value="true"
      title="รายละเอียดหนังสือเวียน"
      @update:model-value="selected = null"
    >
      <div class="space-y-4">
        <div>
          <h3 class="text-base font-semibold text-gray-900">{{ selected.document?.subject }}</h3>
          <p class="text-xs text-gray-500 font-mono mt-0.5">เลขที่: {{ selected.document?.document_number || '—' }}</p>
        </div>

        <p v-if="!selected.acknowledged_at" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2 font-medium">
          ⚠️ ท่านยังไม่ได้กดรับทราบหนังสือฉบับนี้
        </p>

        <dl class="grid grid-cols-2 gap-3 text-xs bg-gray-50 p-3.5 rounded-xl border">
          <div v-for="d in details" :key="d.label">
            <dt class="text-gray-500">{{ d.label }}</dt>
            <dd class="font-medium text-gray-900 mt-0.5">{{ d.value || '—' }}</dd>
          </div>
        </dl>

        <div v-if="selected.document?.id" class="pt-1">
          <RouterLink
            :to="`/saraban/documents/${selected.document.id}`"
            class="text-xs text-[#800000] font-semibold hover:underline"
          >
            เปิดดูไฟล์หนังสือฉบับเต็ม →
          </RouterLink>
        </div>

        <div class="border-t pt-3 space-y-3">
          <AppInput
            v-if="!selected.acknowledged_at"
            v-model="responseNote"
            label="ข้อความตอบรับ / หมายเหตุ (ถ้ามี)"
            placeholder="เช่น รับทราบและจะดำเนินการตามข้อสั่งการ"
          />

          <!-- รายงานความคืบหน้า (เมื่อรับทราบแล้ว) -->
          <div v-if="selected.acknowledged_at && selected.status !== 'completed'" class="space-y-3 bg-gray-50/60 p-3 rounded-xl border border-gray-100">
            <div class="space-y-1">
              <label class="block text-xs font-medium text-gray-700">รายงานความคืบหน้าการดำเนินการ</label>
              <select
                v-model="progressStatus"
                class="w-full text-xs rounded-xl border-gray-300 shadow-xs focus:border-[#800000] focus:ring-[#800000]"
              >
                <option value="accepted_for_action">รับดำเนินการ</option>
                <option value="in_progress">กำลังดำเนินการ</option>
                <option value="completed">ดำเนินการเสร็จสิ้น</option>
              </select>
            </div>
            <AppInput
              v-model="progressNote"
              label="บันทึกความคืบหน้า (ถ้ามี)"
              placeholder="เช่น ดำเนินการตามข้อสั่งการเรียบร้อยแล้ว"
            />
            <div class="flex justify-end">
              <AppButton
                size="sm"
                :loading="progressBusy"
                @click="submitProgress"
              >
                บันทึกความคืบหน้า
              </AppButton>
            </div>
          </div>

          <div class="flex justify-end gap-2">
            <AppButton variant="secondary" @click="selected = null">ปิด</AppButton>
            <AppButton
              v-if="!selected.acknowledged_at"
              :loading="busy"
              @click="acknowledge"
            >
              รับทราบ
            </AppButton>
          </div>
        </div>
      </div>
    </AppModal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../axios'
import AppBadge from '../../components/ui/AppBadge.vue'
import AppBreadcrumb from '../../components/ui/AppBreadcrumb.vue'
import AppButton from '../../components/ui/AppButton.vue'
import AppInput from '../../components/ui/AppInput.vue'
import AppModal from '../../components/ui/AppModal.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import { getErrorMessage } from '../../utils/errorHandler'

const route = useRoute()
const router = useRouter()

const STATUS_LABELS = {
  delivered: 'ได้รับแล้ว',
  opened: 'เปิดอ่านแล้ว',
  acknowledged: 'รับทราบแล้ว',
  in_progress: 'กำลังดำเนินการ',
  completed: 'เสร็จสิ้น',
}

const activeTab = ref(route?.query?.tab || 'inbox')

// Tab 1: Inbox State
const items = ref([])
const loading = ref(false)
const error = ref('')
const page = ref(1)
const lastPage = ref(1)
const pendingOnly = ref(false)
const actingFor = ref('')
const unacknowledged = ref(0)
const delegators = ref([])
const selected = ref(null)
const responseNote = ref('')
const busy = ref(false)

// Tab 2: Queue State
const queueItems = ref([])
const queueLoading = ref(false)
const queueError = ref('')
const queueUrgencyFilter = ref('all')

const filteredQueueItems = computed(() => {
  if (queueUrgencyFilter.value === 'urgent') {
    return queueItems.value.filter((i) => i.urgency === 'most_urgent' || i.urgency === 'very_urgent')
  }
  return queueItems.value
})

const queueUrgentCount = computed(() =>
  queueItems.value.filter((i) => i.urgency === 'most_urgent' || i.urgency === 'very_urgent').length
)

// Tab 3: My Drafts State
const myDraftsList = ref([])
const myDraftsLoading = ref(false)
const myDraftsError = ref('')

const INBOX_TABS = computed(() => [
  { key: 'inbox', label: 'หนังสือเวียน / เพื่อทราบ', icon: '📬', badge: unacknowledged.value },
  { key: 'queue', label: 'รอพิจารณา / รอลงนาม', icon: '⏳', badge: queueItems.value.length },
  { key: 'my_drafts', label: 'เรื่องที่ฉันส่ง / ฉบับร่าง', icon: '📝', badge: 0 },
])

const delegatorNames = computed(() => delegators.value.map((d) => d.name).join(', '))

function switchTab(tabKey) {
  activeTab.value = tabKey
  if (router && route?.query) {
    router.replace({ query: { ...route.query, tab: tabKey } })
  }
  loadTabData(tabKey)
}

function statusLabel(status) {
  return STATUS_LABELS[status] || status
}

function statusVariant(status) {
  if (status === 'completed' || status === 'acknowledged') return 'success'
  if (status === 'delivered' || status === 'opened') return 'warning'
  return 'info'
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

function formatDateTime(value) {
  if (!value) return '—'
  const date = new Date(value)
  const buddhistYear = date.getFullYear() + 543
  return `${String(date.getDate()).padStart(2, '0')}/${String(date.getMonth() + 1).padStart(2, '0')}/${buddhistYear} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')} น.`
}

const details = computed(() => [
  { label: 'เลขที่หนังสือ', value: selected.value?.document?.document_number },
  { label: 'หน่วยงานเจ้าของเรื่อง', value: selected.value?.document?.owner_org_unit },
  { label: 'ผู้ส่ง', value: selected.value?.sent_by },
  { label: 'ได้รับเมื่อ', value: formatDateTime(selected.value?.delivered_at) },
  { label: 'เปิดอ่านเมื่อ', value: formatDateTime(selected.value?.opened_at) },
  { label: 'รับทราบเมื่อ', value: formatDateTime(selected.value?.acknowledged_at) },
])

async function loadInbox() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/saraban/inbox', {
      params: {
        page: page.value,
        pending: pendingOnly.value ? 1 : undefined,
        acting_for: actingFor.value || undefined,
      },
    })
    items.value = data.data || []
    lastPage.value = data.meta?.last_page || 1
    unacknowledged.value = data.unacknowledged || 0
    delegators.value = data.delegators || []
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดกล่องหนังสือเข้าไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

async function loadQueue() {
  queueLoading.value = true
  queueError.value = ''
  try {
    const { data } = await api.get('/saraban/documents/queue')
    queueItems.value = data.data || []
  } catch (err) {
    queueError.value = getErrorMessage(err, 'โหลดคิวงานไม่สำเร็จ')
  } finally {
    queueLoading.value = false
  }
}

async function loadMyDrafts() {
  myDraftsLoading.value = true
  myDraftsError.value = ''
  try {
    const { data } = await api.get('/saraban/documents')
    myDraftsList.value = data.data || []
  } catch (err) {
    myDraftsError.value = getErrorMessage(err, 'โหลดรายการหนังสือไม่สำเร็จ')
  } finally {
    myDraftsLoading.value = false
  }
}

function applyInboxFilter() {
  page.value = 1
  loadInbox()
}

function openQueueItem(item) {
  if (item.actions?.includes('sign')) {
    router.push(`/saraban/documents/${item.document_id}/sign`)
  } else if (item.actions?.includes('endorse')) {
    router.push(`/saraban/documents/${item.document_id}/endorse`)
  } else {
    router.push(`/saraban/documents/${item.document_id}`)
  }
}

async function openInboxModal(item) {
  try {
    const { data } = await api.get(`/saraban/inbox/${item.id}`)
    selected.value = data.item || data
    loadInbox()
  } catch {
    selected.value = item
  }
}

async function acknowledge() {
  if (!selected.value) return
  busy.value = true
  try {
    const { data } = await api.post(`/saraban/inbox/${selected.value.id}/acknowledge`, {
      note: responseNote.value || undefined,
    })
    selected.value = data.item || data
    loadInbox()
  } catch (err) {
    alert(getErrorMessage(err, 'บันทึกการรับทราบไม่สำเร็จ'))
  } finally {
    busy.value = false
  }
}

const progressStatus = ref('in_progress')
const progressNote = ref('')
const progressBusy = ref(false)

async function submitProgress() {
  if (!selected.value) return
  progressBusy.value = true
  try {
    const { data } = await api.post(`/saraban/inbox/${selected.value.id}/progress`, {
      status: progressStatus.value,
      note: progressNote.value || undefined,
    })
    selected.value = data.item || data
    progressNote.value = ''
    loadInbox()
  } catch (err) {
    alert(getErrorMessage(err, 'บันทึกความคืบหน้าไม่สำเร็จ'))
  } finally {
    progressBusy.value = false
  }
}

function loadTabData(tabKey) {
  if (tabKey === 'inbox') loadInbox()
  else if (tabKey === 'queue') loadQueue()
  else if (tabKey === 'my_drafts') loadMyDrafts()
}

watch(() => route?.query?.tab, (newTab) => {
  if (newTab && newTab !== activeTab.value) {
    activeTab.value = newTab
    loadTabData(newTab)
  }
})

onMounted(() => {
  loadInbox()
  loadQueue()
})
</script>
