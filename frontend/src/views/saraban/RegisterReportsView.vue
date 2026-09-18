<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <AppBreadcrumb />
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">รายงานสารบรรณ</h1>
        <p class="text-sm text-gray-500 mt-1">
          รายงานแสดงเฉพาะหนังสือในขอบเขตหน่วยงานที่ท่านมีสิทธิ์เข้าถึง
        </p>
      </div>
      <div v-can="'saraban.report.export'">
        <AppButton variant="secondary" @click="openExportModal">
          <span class="flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            ส่งออกรายงาน (Export)
          </span>
        </AppButton>
      </div>
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

        <div v-else class="space-y-4">
          <div class="grid lg:grid-cols-3 gap-4">
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

          <!-- สถิติแยกตาม ๔ ฝ่ายหลัก สอศ. -->
          <section v-if="data.by_division?.length" class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5">
            <div class="flex items-center justify-between mb-4">
              <div>
                <h2 class="font-semibold text-gray-800">สัดส่วนหนังสือแยกตาม ๔ ฝ่ายหลัก (สอศ.)</h2>
                <p class="text-xs text-gray-500 mt-0.5">จำแนกตามฝ่ายต้นสังกัดของหน่วยงานเจ้าของเรื่อง</p>
              </div>
              <span class="text-xs px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 font-medium">โครงสร้าง ๔ ฝ่าย สอศ.</span>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <div
                v-for="div in data.by_division"
                :key="div.division_id"
                class="rounded-xl border border-gray-100 bg-gray-50/60 p-4 transition-all hover:bg-white hover:shadow-sm"
              >
                <div class="flex items-center justify-between">
                  <span class="text-xs font-semibold px-2 py-0.5 rounded bg-white text-gray-700 border border-gray-200">{{ div.code || 'ฝ่าย' }}</span>
                  <span class="text-xs font-semibold text-gray-500">{{ calculatePercent(div.count, data.total) }}%</span>
                </div>
                <p class="text-sm font-medium text-gray-800 mt-2 truncate" :title="div.name">{{ div.name }}</p>
                <p class="text-2xl font-bold text-gray-900 mt-1">{{ div.count }} <span class="text-xs font-normal text-gray-500">ฉบับ</span></p>

                <div class="w-full bg-gray-200 rounded-full h-1.5 mt-3 overflow-hidden">
                  <div class="bg-[#800000] h-1.5 rounded-full" :style="{ width: `${calculatePercent(div.count, data.total)}%` }"></div>
                </div>
              </div>
            </div>
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
          <div class="flex flex-wrap items-center gap-2">
            <div class="w-36">
              <AppSelect v-model="filters.direction" label="ทิศทาง" @change="load">
                <option value="">ทุกทิศทาง</option>
                <option value="incoming">หนังสือรับ</option>
                <option value="outgoing">หนังสือส่ง</option>
                <option value="internal">หนังสือภายใน</option>
              </AppSelect>
            </div>
            <div class="w-48">
              <AppSelect v-model="filters.division_id" label="ฝ่ายงาน (สอศ.)" @change="load">
                <option value="">ทุกฝ่ายงาน</option>
                <option v-for="d in divisionsList" :key="d.id" :value="d.id">{{ d.name }}</option>
              </AppSelect>
            </div>
          </div>
        </header>

        <EmptyState v-if="!data.rows?.length" title="ไม่พบหนังสือ" description="ลองปรับเงื่อนไขการค้นหา" />

        <div v-else>
          <!-- Desktop Table -->
          <div class="hidden md:block overflow-x-auto">
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

          <!-- Mobile Cards -->
          <div class="block md:hidden divide-y divide-gray-100">
            <div v-for="row in data.rows" :key="row.id" class="p-4 space-y-2 hover:bg-gray-50">
              <div class="flex items-center justify-between gap-2">
                <span class="font-mono text-xs font-semibold text-primary bg-primary/5 px-2 py-0.5 rounded">
                  {{ row.document_number || 'ยังไม่มีเลขที่' }}
                </span>
                <AppBadge variant="gray">{{ statusLabel(row.status) }}</AppBadge>
              </div>
              <p class="font-medium text-gray-900 text-sm leading-snug">{{ row.subject }}</p>
              <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                <span class="flex items-center gap-1">
                  <span class="text-gray-400">ประเภท:</span> {{ row.document_type }}
                </span>
                <span class="flex items-center gap-1">
                  <span class="text-gray-400">หน่วยงาน:</span> {{ row.owner_org_unit }}
                </span>
              </div>
            </div>
          </div>
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

          <div v-else>
            <!-- Desktop Table -->
            <div class="hidden md:block overflow-x-auto">
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

            <!-- Mobile Cards -->
            <div class="block md:hidden divide-y divide-gray-100">
              <div v-for="row in data.rows" :key="row.id" class="p-4 space-y-2 hover:bg-gray-50">
                <div class="flex items-center justify-between gap-2">
                  <span class="font-mono text-sm font-bold text-gray-900">{{ row.formatted }}</span>
                  <AppBadge :variant="numberStatusVariant(row.status)">{{ numberStatusLabel(row.status) }}</AppBadge>
                </div>
                <div class="grid grid-cols-2 gap-2 text-xs text-gray-600">
                  <div><span class="text-gray-400">ปี พ.ศ.:</span> {{ row.buddhist_year }}</div>
                  <div><span class="text-gray-400">ชุดเลข:</span> {{ row.series }}</div>
                </div>
                <div v-if="row.reason" class="text-xs text-gray-500 bg-gray-50 p-2 rounded">
                  <span class="text-gray-400">เหตุผล:</span> {{ row.reason }}
                </div>
              </div>
            </div>
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

    <!-- ส่งออกรายงานเป็นไฟล์ Excel/CSV (RISK-69 & ระเบียบฯ ข้อ ๒๙/๑) -->
    <AppModal
      v-if="exportModal"
      :model-value="true"
      title="ส่งออกรายงานสารบรรณ"
      size="md"
      @update:model-value="exportModal = false"
    >
      <div class="space-y-4">
        <p class="text-sm text-gray-600">
          ดาวน์โหลดข้อมูลรายงานตามแบบพิมพ์ระเบียบสารบรรณในรูปแบบไฟล์อิเล็กทรอนิกส์
        </p>

        <AppSelect v-model="exportForm.type" label="ประเภทรายงาน">
          <option value="register">ทะเบียนหนังสือ (รับ / ส่ง / ภายใน)</option>
          <option value="numbers">ทะเบียนเลขหนังสือ</option>
          <option value="distribution">การเวียนและการรับทราบ</option>
        </AppSelect>

        <div v-if="exportForm.type === 'register'" class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <AppSelect v-model="exportForm.direction" label="ทิศทางหนังสือ">
              <option value="">ทั้งหมด</option>
              <option value="incoming">หนังสือรับ (แบบที่ ๑๓)</option>
              <option value="outgoing">หนังสือส่ง (แบบที่ ๑๔)</option>
              <option value="internal">หนังสือภายใน (แบบที่ ๒)</option>
            </AppSelect>

            <AppSelect v-model="exportForm.division_id" label="ฝ่ายงาน (สอศ.)">
              <option value="">ทุกฝ่ายงาน</option>
              <option v-for="d in divisionsList" :key="d.id" :value="d.id">{{ d.name }}</option>
            </AppSelect>
          </div>

          <AppSelect v-model="exportForm.format" label="รูปแบบไฟล์">
            <option value="xlsx">Excel (.xlsx)</option>
            <option value="csv">CSV (.csv - UTF-8)</option>
          </AppSelect>
        </div>

        <div v-else>
          <AppSelect v-model="exportForm.format" label="รูปแบบไฟล์">
            <option value="xlsx">Excel (.xlsx)</option>
            <option value="csv">CSV (.csv - UTF-8)</option>
          </AppSelect>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <AppInput v-model="exportForm.from" type="date" label="ตั้งแต่วันที่" />
          <AppInput v-model="exportForm.to" type="date" label="ถึงวันที่" />
        </div>

        <p v-if="exportError" class="text-sm text-red-600">{{ exportError }}</p>
      </div>

      <template #footer>
        <AppButton variant="secondary" :disabled="exporting" @click="exportModal = false">ยกเลิก</AppButton>
        <AppButton :loading="exporting" :disabled="exporting" @click="downloadExport">
          ดาวน์โหลดไฟล์
        </AppButton>
      </template>
    </AppModal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import AppBadge from '../../components/ui/AppBadge.vue'
import AppBreadcrumb from '../../components/ui/AppBreadcrumb.vue'
import AppButton from '../../components/ui/AppButton.vue'
import AppInput from '../../components/ui/AppInput.vue'
import AppModal from '../../components/ui/AppModal.vue'
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
const filters = reactive({ direction: '', division_id: '' })
const divisionsList = ref([])

const calculatePercent = (count, total) => {
  if (!total || !count) return 0
  return Math.round((count / total) * 100)
}

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
    const params = {}
    if (active.value === 'register') {
      if (filters.direction) params.direction = filters.direction
      if (filters.division_id) params.division_id = filters.division_id
    }

    const { data: body } = await api.get(`/saraban/reports/${active.value}`, {
      params,
    })
    data.value = body

    if (body.by_division?.length && !divisionsList.value.length) {
      divisionsList.value = body.by_division.map((d) => ({ id: d.division_id, name: d.name }))
    }
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

const exportModal = ref(false)
const exporting = ref(false)
const exportError = ref('')
const exportForm = reactive({
  type: 'register',
  direction: '',
  division_id: '',
  format: 'xlsx',
  from: '',
  to: '',
})

const openExportModal = () => {
  exportForm.type = active.value === 'numbers' ? 'numbers' : (active.value === 'distribution' ? 'distribution' : 'register')
  exportForm.direction = filters.direction || ''
  exportForm.division_id = filters.division_id || ''
  exportError.value = ''
  exportModal.value = true
}

const downloadExport = async () => {
  exporting.value = true
  exportError.value = ''
  try {
    const params = {
      type: exportForm.type,
      format: exportForm.format,
    }
    if (exportForm.type === 'register') {
      if (exportForm.direction) params.direction = exportForm.direction
      if (exportForm.division_id) params.division_id = exportForm.division_id
    }
    if (exportForm.from) {
      params.from = exportForm.from
    }
    if (exportForm.to) {
      params.to = exportForm.to
    }

    const response = await api.get('/saraban/reports/export', {
      params,
      responseType: 'blob',
    })

    const blob = new Blob([response.data], {
      type: exportForm.format === 'xlsx'
        ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        : 'text/csv;charset=utf-8;',
    })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    const timestamp = new Date().toISOString().slice(0, 10)
    link.setAttribute('download', `saraban-${exportForm.type}-${timestamp}.${exportForm.format}`)
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(url)

    exportModal.value = false
  } catch (err) {
    /*
     * คำขอนี้เป็น `responseType: 'blob'` ตัว body ของ error จึงมาเป็น Blob
     * ซึ่งอ่าน `.message` ตรง ๆ ไม่ได้ — ต้องแปลงกลับเป็นข้อความก่อน ไม่งั้นผู้ใช้
     * จะเห็นแต่ข้อความกลาง ๆ และไม่รู้ว่าช่องไหนผิด
     */
    if (err?.response?.data instanceof Blob) {
      try {
        err.response.data = JSON.parse(await err.response.data.text())
      } catch {
        err.response.data = null
      }
    }
    exportError.value = getErrorMessage(err, 'ส่งออกรายงานไม่สำเร็จ กรุณาลองใหม่อีกครั้ง')
  } finally {
    exporting.value = false
  }
}

onMounted(load)
</script>
