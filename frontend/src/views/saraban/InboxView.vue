<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">กล่องหนังสือของฉัน</h1>
        <p class="text-sm text-gray-500 mt-1">
          การเปิดอ่านไม่นับเป็นการรับทราบ ต้องกดรับทราบทุกฉบับ
        </p>
      </div>

      <label class="inline-flex items-center gap-2 text-sm text-gray-600">
        <input
          v-model="pendingOnly"
          type="checkbox"
          class="rounded border-gray-300"
          @change="applyFilter"
        >
        เฉพาะที่ยังไม่รับทราบ
        <span
          v-if="unacknowledged > 0"
          class="ml-1 inline-flex items-center justify-center min-w-6 h-6 px-2 rounded-full text-xs font-semibold text-white"
          style="background-color: #800000"
        >{{ unacknowledged }}</span>
      </label>
    </div>

    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
      <LoadingState v-if="loading" type="skeleton-table" :rows="5" :columns="5" />
      <ErrorState v-else-if="error" :message="error" @retry="load" />
      <EmptyState
        v-else-if="!items.length"
        title="ยังไม่มีหนังสือเวียนถึงท่าน"
        description="เมื่อมีหนังสือเวียนมา รายการจะปรากฏที่นี่"
      />

      <div v-else class="overflow-x-auto">
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
                {{ item.document?.subject }}
                <span
                  v-if="item.recipient_role === 'cc'"
                  class="ml-2 text-xs font-normal text-gray-500"
                >(สำเนาเรียน)</span>
                <span v-if="item.note" class="block text-xs font-normal text-gray-500 mt-0.5">
                  {{ item.note }}
                </span>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ item.sent_by || '—' }}</td>
              <td class="px-5 py-3 text-gray-500">{{ formatDateTime(item.delivered_at) }}</td>
              <td class="px-5 py-3">
                <AppBadge :variant="statusVariant(item.status)">{{ statusLabel(item.status) }}</AppBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <AppButton size="sm" variant="secondary" @click="open(item)">เปิดอ่าน</AppButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="lastPage > 1" class="px-5 py-4 border-t border-gray-100">
        <AppPagination :page="page" :last-page="lastPage" @update:page="changePage" />
      </div>
    </section>

    <AppModal
      v-if="selected"
      :model-value="true"
      :title="selected.document?.subject || 'หนังสือเวียน'"
      size="lg"
      @update:model-value="selected = null"
    >
      <div class="space-y-4">
        <dl class="grid sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
          <div v-for="row in details" :key="row.label">
            <dt class="text-gray-500">{{ row.label }}</dt>
            <dd class="font-medium text-gray-900">{{ row.value || '—' }}</dd>
          </div>
        </dl>

        <!-- เปิดอ่านแล้วแต่ยังไม่รับทราบ — ต้องเห็นชัดว่ายังไม่จบ (SoT §15) -->
        <div
          v-if="!selected.acknowledged_at"
          class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
        >
          ท่านเปิดอ่านหนังสือฉบับนี้แล้ว แต่ยังไม่ได้กดรับทราบ
        </div>

        <AppTextarea
          v-if="!selected.acknowledged_at"
          v-model="responseNote"
          label="ความเห็น (ถ้ามี)"
          :rows="2"
        />

        <div v-if="selected.acknowledged_at" class="space-y-2">
          <p class="text-sm text-gray-600">รายงานความคืบหน้าการดำเนินการ</p>
          <div class="flex flex-wrap gap-2">
            <AppButton
              v-for="option in progressOptions"
              :key="option.value"
              size="sm"
              variant="secondary"
              :disabled="busy || !canAdvanceTo(option.value)"
              @click="reportProgress(option.value)"
            >
              {{ option.label }}
            </AppButton>
          </div>
        </div>

        <p v-if="modalError" class="text-sm text-red-600">{{ modalError }}</p>
      </div>

      <template #footer>
        <AppButton variant="secondary" @click="selected = null">ปิด</AppButton>
        <AppButton v-if="!selected.acknowledged_at" :disabled="busy" @click="acknowledge">
          รับทราบ
        </AppButton>
      </template>
    </AppModal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import AppBadge from '../../components/ui/AppBadge.vue'
import AppButton from '../../components/ui/AppButton.vue'
import AppModal from '../../components/ui/AppModal.vue'
import AppPagination from '../../components/ui/AppPagination.vue'
import AppTextarea from '../../components/ui/AppTextarea.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'

/**
 * กล่องหนังสือเข้า (SoT §15 · INT-13)
 *
 * **หน้าจอนี้ต้องไม่ทำให้ "เปิดอ่าน" กลายเป็น "รับทราบ"** — การกดเปิดอ่านเรียก
 * `GET /inbox/{id}` ซึ่งบันทึกเวลาเปิดเท่านั้น ส่วนการรับทราบเป็นปุ่มแยกที่ผู้ใช้
 * ต้องกดเอง และยังเห็นคำเตือนสีเหลืองอยู่ตราบใดที่ยังไม่ได้กด
 */
const STATUS_LABELS = {
  delivered: 'ได้รับแล้ว',
  opened: 'เปิดอ่านแล้ว',
  acknowledged: 'รับทราบแล้ว',
  accepted_for_action: 'รับไปดำเนินการ',
  in_progress: 'กำลังดำเนินการ',
  completed: 'ดำเนินการแล้วเสร็จ',
}

const PROGRESSION = Object.keys(STATUS_LABELS)

const progressOptions = [
  { value: 'accepted_for_action', label: 'รับไปดำเนินการ' },
  { value: 'in_progress', label: 'กำลังดำเนินการ' },
  { value: 'completed', label: 'ดำเนินการแล้วเสร็จ' },
]

const items = ref([])
const loading = ref(true)
const error = ref('')
const modalError = ref('')
const busy = ref(false)
const page = ref(1)
const lastPage = ref(1)
const unacknowledged = ref(0)
const pendingOnly = ref(false)
const selected = ref(null)
const responseNote = ref('')

const statusLabel = (status) => STATUS_LABELS[status] || status

const statusVariant = (status) => {
  if (status === 'completed') return 'success'
  if (status === 'delivered' || status === 'opened') return 'warning'

  return 'info'
}

const formatDateTime = (value) => {
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

// สถานะเดินหน้าได้อย่างเดียว — ปุ่มที่ย้อนหลังถูกปิดไว้ตั้งแต่หน้าจอ
const canAdvanceTo = (status) =>
  PROGRESSION.indexOf(status) > PROGRESSION.indexOf(selected.value?.status)

const load = async () => {
  loading.value = true
  error.value = ''

  try {
    const { data } = await api.get('/saraban/inbox', {
      params: { page: page.value, pending: pendingOnly.value ? 1 : undefined },
    })
    items.value = data.data
    lastPage.value = data.meta.last_page
    unacknowledged.value = data.unacknowledged
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดกล่องหนังสือเข้าไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const applyFilter = () => {
  page.value = 1
  load()
}

const changePage = (next) => {
  page.value = next
  load()
}

const open = async (item) => {
  modalError.value = ''
  responseNote.value = ''

  try {
    const { data } = await api.get(`/saraban/inbox/${item.id}`)
    selected.value = data.item
    await load()
  } catch (err) {
    error.value = getErrorMessage(err, 'เปิดหนังสือไม่สำเร็จ')
  }
}

const acknowledge = async () => {
  busy.value = true
  modalError.value = ''

  try {
    const { data } = await api.post(`/saraban/inbox/${selected.value.id}/acknowledge`, {
      note: responseNote.value || undefined,
    })
    selected.value = data.item
    await load()
  } catch (err) {
    modalError.value = getErrorMessage(err, 'บันทึกการรับทราบไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

const reportProgress = async (status) => {
  busy.value = true
  modalError.value = ''

  try {
    const { data } = await api.post(`/saraban/inbox/${selected.value.id}/progress`, { status })
    selected.value = data.item
    await load()
  } catch (err) {
    modalError.value = getErrorMessage(err, 'รายงานความคืบหน้าไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>
