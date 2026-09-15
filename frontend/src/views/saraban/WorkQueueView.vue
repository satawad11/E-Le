<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">รอพิจารณา / รอลงนาม</h1>
        <p class="text-sm text-gray-500 mt-1">
          หนังสือที่เดินมาถึงท่านตามสายงาน และท่านลงมือทำได้ในขณะนี้
        </p>
      </div>

      <div class="flex items-center gap-2">
        <button
          type="button"
          class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors border"
          :class="urgencyFilter === 'all' ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'"
          @click="urgencyFilter = 'all'"
        >
          ทั้งหมด ({{ items.length }})
        </button>
        <button
          type="button"
          class="px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors border flex items-center gap-1.5"
          :class="urgencyFilter === 'urgent' ? 'bg-red-600 text-white border-red-600 shadow-xs' : 'bg-white text-red-600 border-red-200 hover:bg-red-50'"
          @click="urgencyFilter = 'urgent'"
        >
          <span v-if="urgentCount > 0" class="w-2 h-2 rounded-full bg-current animate-ping" />
          เฉพาะด่วนที่สุด / ด่วนมาก ({{ urgentCount }})
        </button>
      </div>
    </div>

    <!--
      เตือนเมื่อไล่ตรวจไม่ครบ — คิวที่ตัดท้ายเงียบ ๆ ทำให้ผู้ใช้เชื่อว่าไม่มีงานเหลือ
      ทั้งที่ยังมี ซึ่งอันตรายกว่าการไม่มีคิวเลย
    -->
    <p v-if="scanLimited" class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
      มีเรื่องที่กำลังเดินอยู่จำนวนมาก รายการนี้อาจไม่ครบทั้งหมด — ค้นเพิ่มได้ที่ทะเบียนหนังสือ
    </p>

    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
      <LoadingState v-if="loading" type="skeleton-table" :rows="5" :columns="5" />
      <ErrorState v-else-if="error" :message="error" @retry="load" />
      <EmptyState
        v-else-if="!filteredItems.length"
        title="ไม่มีหนังสือที่รอท่านดำเนินการ"
        :description="urgencyFilter === 'urgent' ? 'ไม่มีหนังสือด่วนที่สุดหรือด่วนมากในคิวขณะนี้' : 'เมื่อมีเรื่องเดินมาถึงท่านตามสายงาน รายการจะปรากฏที่นี่'"
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm min-w-[820px]">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left px-5 py-3 font-semibold">เลขที่หนังสือ</th>
              <th class="text-left px-5 py-3 font-semibold">เรื่อง</th>
              <th class="text-left px-5 py-3 font-semibold">หน่วยงานเจ้าของเรื่อง</th>
              <th class="text-left px-5 py-3 font-semibold">ขั้นตอน</th>
              <th class="text-left px-5 py-3 font-semibold">สิ่งที่ทำได้</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr
              v-for="item in filteredItems"
              :key="item.document_id"
              :class="item.urgency === 'most_urgent' ? 'bg-red-50/40 border-l-4 border-l-red-500 hover:bg-red-50/70' : 'hover:bg-gray-50'"
            >
              <td class="px-5 py-3 whitespace-nowrap font-medium text-gray-900">{{ item.document_number || '—' }}</td>
              <td class="px-5 py-3">
                <span>{{ item.subject }}</span>
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

                <span
                  v-if="item.confidentiality && item.confidentiality !== 'normal'"
                  class="ms-2 text-xs font-semibold text-amber-800"
                >{{ confidentialityLabel(item.confidentiality) }}</span>
              </td>
              <td class="px-5 py-3">{{ item.owner_org_unit || '—' }}</td>
              <td class="px-5 py-3">{{ item.current_step || '—' }}</td>
              <!--
                ปุ่มที่แสดงมาจากตัวตัดสินเดียวกับที่ endpoint การลงมือทำใช้ —
                หน้าจอไม่ตัดสินเองว่าใครทำอะไรได้
              -->
              <td class="px-5 py-3">{{ item.actions.map(actionLabel).join(' · ') }}</td>
              <td class="px-5 py-3 text-end">
                <AppButton variant="secondary" @click="open(item)">เปิดเรื่อง</AppButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import AppButton from '../../components/ui/AppButton.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'

const router = useRouter()
const items = ref([])
const loading = ref(true)
const error = ref('')
const scanLimited = ref(false)
const urgencyFilter = ref('all')

const urgentCount = computed(() =>
  items.value.filter((i) => ['most_urgent', 'very_urgent'].includes(i.urgency)).length
)

const filteredItems = computed(() => {
  if (urgencyFilter.value === 'urgent') {
    return items.value.filter((i) => ['most_urgent', 'very_urgent'].includes(i.urgency))
  }
  return items.value
})

// ป้ายชั้นความเร็วถูกวาดเป็น badge แยกสีในตารางแล้ว (v-if ตาม item.urgency) จึงไม่มี helper ซ้ำอีก
const confidentialityLabel = (v) => ({ confidential: 'ลับ', secret: 'ลับมาก', top_secret: 'ลับที่สุด' }[v] || v)
const actionLabel = (v) => ({
  approve: 'อนุมัติ',
  reject: 'ไม่อนุมัติ',
  forward: 'ส่งต่อ',
  return: 'ส่งกลับแก้ไข',
  review: 'ตรวจทาน',
  sign: 'ลงนาม',
}[v] || v)

const open = (item) => router.push({ name: 'saraban-document-detail', params: { id: item.document_id } })

async function load() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await api.get('/saraban/documents/queue')
    items.value = data.pending || []
    scanLimited.value = Boolean(data.scan_limited)
  } catch (e) {
    error.value = getErrorMessage(e, 'โหลดคิวงานไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
