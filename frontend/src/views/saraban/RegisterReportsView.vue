<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-800">รายงาน</h1>
      <p class="text-sm text-gray-500 mt-1">
        รายงานแสดงเฉพาะหนังสือในขอบเขตหน่วยงานที่ท่านมีสิทธิ์เข้าถึง
      </p>
    </div>

    <nav class="flex flex-wrap gap-2" role="tablist">
      <AppButton
        v-for="tab in tabs"
        :key="tab.key"
        size="sm"
        :variant="active === tab.key ? 'primary' : 'secondary'"
        role="tab"
        :aria-selected="active === tab.key"
        @click="select(tab.key)"
      >
        {{ tab.label }}
      </AppButton>
    </nav>

    <LoadingState v-if="loading" type="skeleton-table" :rows="5" :columns="5" />
    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <template v-else>
      <!--
        สรุปภาพรวม (NAV §4.11)

        กราฟใช้ตัวเลขที่ **จำกัดขอบเขตเดียวกับทะเบียน** — กราฟที่นับหนังสือของ
        หน่วยงานที่ผู้ดูไม่มีสิทธิ์เห็น คือการรั่วข้อมูลแบบที่มองไม่ออกจากตัวเลข
        แต่บอกได้ว่าหน่วยงานอื่นมีงานมากน้อยเพียงใด
      -->
      <template v-if="active === 'summary'">
        <EmptyState
          v-if="!data.total"
          title="ยังไม่มีหนังสือในช่วงสิบสองเดือนที่ผ่านมา"
          description="เมื่อมีหนังสือเข้าสู่ระบบ กราฟสรุปจะปรากฏที่นี่"
        />

        <div v-else class="grid lg:grid-cols-3 gap-4">
          <section class="lg:col-span-2 bg-white border border-gray-100 rounded-2xl shadow-sm p-5">
            <h2 class="font-semibold mb-4">หนังสือเข้า-ออก รายเดือน</h2>
            <div class="h-72">
              <Bar :data="monthlyChart" :options="barOptions" />
            </div>
          </section>

          <section class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5">
            <h2 class="font-semibold mb-4">สถานะการดำเนินการ</h2>
            <div class="h-72">
              <Doughnut :data="statusChart" :options="doughnutOptions" />
            </div>
            <p class="text-sm text-gray-500 mt-3">ทั้งหมด {{ data.total }} ฉบับ</p>
          </section>
        </div>
      </template>

      <!-- ทะเบียนหนังสือ -->
      <section v-if="active === 'register'" class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-end gap-3">
          <div class="flex-1">
            <h2 class="font-semibold text-gray-800">ทะเบียนหนังสือ</h2>
            <p class="text-sm text-gray-500 mt-0.5">พบ {{ data.total || 0 }} ฉบับ</p>
          </div>
          <div class="w-full sm:w-48">
            <AppSelect v-model="filters.direction" label="ทิศทาง" @change="load">
              <option value="">ทั้งหมด</option>
              <option value="incoming">หนังสือรับ</option>
              <option value="outgoing">หนังสือส่ง</option>
              <option value="internal">หนังสือภายใน</option>
            </AppSelect>
          </div>
        </header>

        <EmptyState v-if="!data.rows?.length" title="ไม่พบหนังสือ" description="ลองปรับเงื่อนไขการค้นหา" />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm min-w-[860px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3 font-semibold">เลขที่หนังสือ</th>
                <th class="text-left px-5 py-3 font-semibold">เรื่อง</th>
                <th class="text-left px-5 py-3 font-semibold">ประเภท</th>
                <th class="text-left px-5 py-3 font-semibold">หน่วยงาน</th>
                <th class="text-left px-5 py-3 font-semibold">สถานะ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="row in data.rows" :key="row.id" class="hover:bg-gray-50/60">
                <td class="px-5 py-3 font-mono text-xs text-gray-900">{{ row.document_number || '—' }}</td>
                <td class="px-5 py-3 font-medium text-gray-900">{{ row.subject }}</td>
                <td class="px-5 py-3 text-gray-600">{{ row.document_type }}</td>
                <td class="px-5 py-3 text-gray-600">{{ row.owner_org_unit }}</td>
                <td class="px-5 py-3">
                  <AppBadge variant="gray">{{ statusLabel(row.status) }}</AppBadge>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ทะเบียนเลขหนังสือ -->
      <section v-if="active === 'numbers'" class="space-y-4">
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div v-for="(total, status) in data.summary || {}" :key="status" class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5">
            <p class="text-sm text-gray-500">{{ numberStatusLabel(status) }}</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ total }}</p>
          </div>
        </div>

        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
          <EmptyState v-if="!data.rows?.length" title="ยังไม่มีเลขหนังสือ" description="เลขจะปรากฏเมื่อมีการจองหรือออกเลข" />

          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm min-w-[640px]">
              <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                <tr>
                  <th class="text-left px-5 py-3 font-semibold">เลขที่</th>
                  <th class="text-left px-5 py-3 font-semibold">ปี พ.ศ.</th>
                  <th class="text-left px-5 py-3 font-semibold">ชุดเลข</th>
                  <th class="text-left px-5 py-3 font-semibold">สถานะ</th>
                  <th class="text-left px-5 py-3 font-semibold">เหตุผล</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-50">
                <tr v-for="row in data.rows" :key="row.id" class="hover:bg-gray-50/60">
                  <td class="px-5 py-3 font-mono text-xs text-gray-900">{{ row.formatted }}</td>
                  <td class="px-5 py-3 text-gray-600">{{ row.buddhist_year }}</td>
                  <td class="px-5 py-3 text-gray-600">{{ row.series }}</td>
                  <td class="px-5 py-3">
                    <AppBadge :variant="numberStatusVariant(row.status)">{{ numberStatusLabel(row.status) }}</AppBadge>
                  </td>
                  <td class="px-5 py-3 text-gray-500">{{ row.reason || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- สรุปการเวียนและการรับทราบ -->
      <section v-if="active === 'distribution'" class="space-y-4">
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div v-for="tile in distributionTiles" :key="tile.label" class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5">
            <p class="text-sm text-gray-500">{{ tile.label }}</p>
            <p class="text-2xl font-bold mt-1" :class="tile.alert ? 'text-red-700' : 'text-gray-900'">{{ tile.value }}</p>
            <p v-if="tile.hint" class="text-xs text-gray-400 mt-1">{{ tile.hint }}</p>
          </div>
        </div>

        <!--
          SoT §15 — "ยังไม่เปิดอ่าน" กับ "ยังไม่รับทราบ" ต่างกัน และผู้บริหาร
          ต้องเห็นความต่างนั้น ไม่ใช่ตัวเลขรวมตัวเดียว
        -->
        <p class="text-sm text-gray-500">
          "ยังไม่รับทราบ" รวมผู้ที่เปิดอ่านแล้วแต่ยังไม่กดรับทราบด้วย —
          การเปิดอ่านไม่นับเป็นการรับทราบ
        </p>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import AppBadge from '../../components/ui/AppBadge.vue'
import AppButton from '../../components/ui/AppButton.vue'
import AppSelect from '../../components/ui/AppSelect.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import {
  ArcElement,
  BarElement,
  CategoryScale,
  Chart as ChartJS,
  Legend,
  LinearScale,
  Title,
  Tooltip,
} from 'chart.js'
import { Bar, Doughnut } from 'vue-chartjs'

/*
 * ลงทะเบียนเฉพาะส่วนที่หน้านี้ใช้ — ไลบรารีตัวเดียวกับที่ `DashboardView` ใช้อยู่
 * ไม่เพิ่ม dependency ใหม่
 */
ChartJS.register(CategoryScale, LinearScale, BarElement, ArcElement, Title, Tooltip, Legend)

/**
 * รายงานทะเบียนงานสารบรรณ (SoT §28 · INT-19)
 *
 * บอกตรง ๆ ว่ารายงานถูกจำกัดด้วยขอบเขต — ผู้ใช้ที่เห็นตัวเลขน้อยกว่าที่คาด
 * ต้องรู้ว่าเป็นเพราะขอบเขตของตน ไม่ใช่เพราะระบบนับผิด
 */
const STATUS_LABELS = {
  draft: 'ฉบับร่าง',
  submitted: 'เสนอแล้ว',
  registered: 'ลงทะเบียนรับแล้ว',
  signed: 'ลงนามแล้ว',
  rejected: 'ตีกลับ',
  cancelled: 'ยกเลิก',
}

const NUMBER_STATUS_LABELS = {
  available: 'ว่าง',
  reserved: 'จองไว้',
  allocated: 'จัดสรรแล้ว',
  issued: 'ออกแล้ว',
  released: 'คืนเลข',
  cancelled: 'ยกเลิก',
  void: 'เพิกถอน',
}

const tabs = [
  { key: 'summary', label: 'สรุปภาพรวม' },
  { key: 'register', label: 'ทะเบียนหนังสือ' },
  { key: 'numbers', label: 'ทะเบียนเลขหนังสือ' },
  { key: 'distribution', label: 'การเวียนและการรับทราบ' },
]

const active = ref('summary')
const data = ref({})
const loading = ref(true)
const error = ref('')
const filters = reactive({ direction: '' })

/*
 * สีของกราฟ — **เฉดที่ไล่จากสีแบรนด์ของระบบ ไม่ใช่ชุดสีใหม่**
 *
 * กราฟต้องการหลายสีเพื่อแยกชุดข้อมูล ซึ่งชุดสีเดิมสองสีไม่พอ · แทนที่จะหยิบ
 * สีจากที่อื่นมาใช้ จึงไล่เฉดจาก `#800000` ลงมา — ได้ทั้งความเป็นระบบเดียวกัน
 * และ **อ่านได้แม้พิมพ์ขาวดำ** เพราะความสว่างต่างกันชัด ซึ่งเป็นวิธีที่รายงาน
 * ราชการถูกใช้จริงบ่อยกว่าการดูบนจอ
 *
 * เทสต์ `sarabanDocumentsView` ตรึงรายการนี้ไว้ไม่ให้มีสีนอกชุดหลุดเข้ามา
 */
const CHART_COLORS = ['#800000', '#a34a4a', '#c08787', '#d9b3b3', '#600000', '#4d0000']

const monthlyChart = computed(() => ({
  labels: (data.value.monthly || []).map((m) => m.label),
  datasets: [
    { label: 'หนังสือรับ', data: (data.value.monthly || []).map((m) => m.incoming), backgroundColor: CHART_COLORS[0] },
    { label: 'หนังสือส่ง', data: (data.value.monthly || []).map((m) => m.outgoing), backgroundColor: CHART_COLORS[1] },
    { label: 'หนังสือภายใน', data: (data.value.monthly || []).map((m) => m.internal), backgroundColor: CHART_COLORS[2] },
  ],
}))

const statusChart = computed(() => ({
  labels: (data.value.by_status || []).map((s) => statusLabel(s.status)),
  datasets: [{
    data: (data.value.by_status || []).map((s) => s.count),
    backgroundColor: (data.value.by_status || []).map((_, i) => CHART_COLORS[i % CHART_COLORS.length]),
  }],
}))

const barOptions = {
  responsive: true,
  maintainAspectRatio: false,
  scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
}

const doughnutOptions = { responsive: true, maintainAspectRatio: false }

const statusLabel = (status) => STATUS_LABELS[status] || status
const numberStatusLabel = (status) => NUMBER_STATUS_LABELS[status] || status
const numberStatusVariant = (status) =>
  status === 'issued' ? 'success' : ['released', 'cancelled', 'void'].includes(status) ? 'danger' : 'warning'

const distributionTiles = computed(() => [
  { label: 'ผู้รับทั้งหมด', value: data.value.total_recipients ?? 0 },
  { label: 'รับทราบแล้ว', value: data.value.acknowledged ?? 0 },
  {
    label: 'ยังไม่รับทราบ',
    value: data.value.not_acknowledged ?? 0,
    alert: (data.value.not_acknowledged ?? 0) > 0,
    hint: 'รวมผู้ที่เปิดอ่านแล้ว',
  },
  {
    label: 'ยังไม่เปิดอ่านเลย',
    value: data.value.never_opened ?? 0,
    alert: (data.value.never_opened ?? 0) > 0,
  },
])

const load = async () => {
  loading.value = true
  error.value = ''

  try {
    const { data: body } = await api.get(`/saraban/reports/${active.value}`, {
      params: active.value === 'register' ? { direction: filters.direction || undefined } : {},
    })
    data.value = body
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดรายงานไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const select = (key) => {
  active.value = key
  data.value = {}
  load()
}

onMounted(load)
</script>
