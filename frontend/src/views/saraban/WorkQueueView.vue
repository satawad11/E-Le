<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">รอฉันดำเนินการ</h1>
        <p class="text-sm text-gray-500 mt-1">
          หนังสือที่เดินมาถึงท่านตามสายงาน และท่านลงมือทำได้ในขณะนี้
        </p>
      </div>

      <span
        v-if="items.length"
        class="inline-flex items-center justify-center min-w-6 h-6 px-2 rounded-full text-xs font-semibold text-white"
        style="background-color: #800000"
      >{{ items.length }}</span>
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
        v-else-if="!items.length"
        title="ไม่มีหนังสือที่รอท่านดำเนินการ"
        description="เมื่อมีเรื่องเดินมาถึงท่านตามสายงาน รายการจะปรากฏที่นี่"
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
            <tr v-for="item in items" :key="item.document_id" class="hover:bg-gray-50">
              <td class="px-5 py-3 whitespace-nowrap">{{ item.document_number || '—' }}</td>
              <td class="px-5 py-3">
                {{ item.subject }}
                <span
                  v-if="item.urgency && item.urgency !== 'normal'"
                  class="ms-2 text-xs font-semibold text-red-700"
                >{{ urgencyLabel(item.urgency) }}</span>
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
import { onMounted, ref } from 'vue'
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

const urgencyLabel = (v) => ({ urgent: 'ด่วน', very_urgent: 'ด่วนมาก', most_urgent: 'ด่วนที่สุด' }[v] || v)
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
