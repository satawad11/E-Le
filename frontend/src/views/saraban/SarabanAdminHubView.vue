<template>
  <div class="max-w-7xl mx-auto space-y-6">
    <AppBreadcrumb />

    <!-- ส่วนหัวหน้าจอศูนย์จัดการระบบสารบรรณ -->
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">ศูนย์จัดการและตั้งค่าระบบสารบรรณ</h1>
        <p class="text-sm text-gray-500 mt-1">
          บริหารจัดการชุดเลขหนังสือ สายการเดินหนังสือ ตารางอำนาจหน้าที่ และหลักฐานธรรมาภิบาล
        </p>
      </div>
    </div>

    <!-- แท็บสลับ 4 ด้านหลักของผู้ดูแลสารบรรณ -->
    <div class="border-b border-gray-200">
      <nav class="flex space-x-6 overflow-x-auto" aria-label="Tabs">
        <button
          v-for="tab in ADMIN_TABS"
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
        </button>
      </nav>
    </div>

    <!-- ==================== TAB 1: ชุดเลขหนังสือ (NUMBER SERIES) ==================== -->
    <div v-if="activeTab === 'number_series'" class="space-y-4">
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div>
            <h2 class="font-semibold text-gray-800">ชุดเลขหนังสือราชการ</h2>
            <p class="text-sm text-gray-500 mt-0.5">กำหนดคำนำหน้า รูปแบบรหัส และการรันเลขประจำปีงบประมาณ</p>
          </div>
          <AppButton v-can="'saraban.config.manage'" size="sm" @click="openSeriesModal(null)">
            + เพิ่มชุดเลขใหม่
          </AppButton>
        </header>

        <LoadingState v-if="seriesLoading" type="skeleton-table" :rows="4" :columns="5" />
        <ErrorState v-else-if="seriesError" :message="seriesError" @retry="fetchSeries" />
        <EmptyState
          v-else-if="!seriesList.length"
          title="ยังไม่มีชุดเลขหนังสือ"
          description="เริ่มต้นด้วยการสร้างชุดเลขหนังสือสำหรับหน่วยงานหรือวิทยาลัย"
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm min-w-[700px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3 font-semibold">ชื่อชุดเลข</th>
                <th class="text-left px-5 py-3 font-semibold">คำนำหน้า (Prefix)</th>
                <th class="text-left px-5 py-3 font-semibold">ตัวอย่างรูปแบบ</th>
                <th class="text-center px-5 py-3 font-semibold">เลขล่าสุด</th>
                <th class="text-center px-5 py-3 font-semibold">สถานะ</th>
                <th class="text-right px-5 py-3 font-semibold">จัดการ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="s in seriesList" :key="s.id" class="hover:bg-gray-50/60">
                <td class="px-5 py-3 font-medium text-gray-900">{{ s.name }}</td>
                <td class="px-5 py-3 font-mono text-xs text-[#800000] font-bold">{{ s.prefix }}</td>
                <td class="px-5 py-3 font-mono text-xs text-gray-600">{{ s.format || '{prefix}{running}/{year}' }}</td>
                <td class="px-5 py-3 text-center font-mono text-xs text-gray-700">{{ s.current_number || 0 }}</td>
                <td class="px-5 py-3 text-center">
                  <AppBadge :variant="s.is_active ? 'success' : 'gray'">
                    {{ s.is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}
                  </AppBadge>
                </td>
                <td class="px-5 py-3 text-right">
                  <AppButton v-can="'saraban.config.manage'" size="sm" variant="ghost" @click="openSeriesModal(s)">
                    แก้ไข
                  </AppButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <!-- ==================== TAB 2: สายการเดินหนังสือและทดสอบ (WORKFLOW STUDIO) ==================== -->
    <div v-else-if="activeTab === 'workflow'" class="space-y-4">
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b pb-4">
          <div>
            <h2 class="font-semibold text-gray-800 text-lg">สายการเดินหนังสือราชการ (Workflow Studio)</h2>
            <p class="text-sm text-gray-500 mt-0.5">
              กำหนดขั้นตอนการเกษียน การตรวจสอบ และการลงนามของสถานศึกษา
            </p>
          </div>
          <div class="flex items-center gap-2">
            <AppButton size="sm" variant="secondary" @click="openSimulationModal">
              🧪 จำลองเส้นทางเดินหนังสือ
            </AppButton>
            <RouterLink to="/saraban/workflow" class="text-xs text-[#800000] font-medium hover:underline">
              เปิดเครื่องมือออกแบบสายงานแบบเต็ม →
            </RouterLink>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="border rounded-xl p-4 bg-gray-50 space-y-2">
            <span class="text-xs font-bold text-[#800000] uppercase block">ขั้นตอนที่ 1</span>
            <h3 class="font-semibold text-gray-800 text-sm">ผู้เสนอ / เจ้าของเรื่อง</h3>
            <p class="text-xs text-gray-500">ยกร่างหนังสือ แนบเอกสารหลักฐาน และส่งเข้าสายการพิจารณา</p>
          </div>
          <div class="border rounded-xl p-4 bg-gray-50 space-y-2">
            <span class="text-xs font-bold text-[#800000] uppercase block">ขั้นตอนที่ 2</span>
            <h3 class="font-semibold text-gray-800 text-sm">หัวหน้างาน / รองผู้อำนวยการ</h3>
            <p class="text-xs text-gray-500">พิจารณาความเห็น เกษียนหนังสือ และส่งต่อตามลำดับชั้นบังคับบัญชา</p>
          </div>
          <div class="border rounded-xl p-4 bg-gray-50 space-y-2">
            <span class="text-xs font-bold text-[#800000] uppercase block">ขั้นตอนที่ 3</span>
            <h3 class="font-semibold text-gray-800 text-sm">ผู้อำนวยการสถานศึกษา</h3>
            <p class="text-xs text-gray-500">ลงนามคำสั่ง อนุมัติ หรือสั่งการ พร้อมประทับตรายางดิจิทัล</p>
          </div>
        </div>
      </section>
    </div>

    <!-- ==================== TAB 3: อำนาจหน้าที่และนโยบาย (AUTHORITY & POLICIES) ==================== -->
    <div v-else-if="activeTab === 'authority_policies'" class="space-y-4">
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b pb-4">
          <div>
            <h2 class="font-semibold text-gray-800 text-lg">ตารางอำนาจหน้าที่และนโยบายงานสารบรรณ</h2>
            <p class="text-sm text-gray-500 mt-0.5">
              กำหนดขอบเขตอำนาจการลงนาม การมอบอำนาจปฏิบัติราชการแทน และนโยบายความปลอดภัย
            </p>
          </div>
          <div class="flex items-center gap-2">
            <RouterLink to="/saraban/authority" class="text-xs text-[#800000] font-medium hover:underline">
              ดูตารางอำนาจเต็ม →
            </RouterLink>
            <span class="text-gray-300">|</span>
            <RouterLink to="/saraban/policies" class="text-xs text-[#800000] font-medium hover:underline">
              ศูนย์นโยบาย →
            </RouterLink>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="border border-amber-100 bg-amber-50/50 rounded-xl p-4 space-y-2">
            <h3 class="font-semibold text-amber-900 text-sm flex items-center gap-1.5">
              <span>🛡️</span> การมอบอำนาจปฏิบัติราชการแทน
            </h3>
            <p class="text-xs text-amber-800">
              ควบคุมการลงนามแทนผู้บริหารตามคำสั่งมอบอำนาจ พร้อมระบบบันทึก Audit Log ทุกรายการ
            </p>
          </div>
          <div class="border border-blue-100 bg-blue-50/50 rounded-xl p-4 space-y-2">
            <h3 class="font-semibold text-blue-900 text-sm flex items-center gap-1.5">
              <span>🔒</span> การควบคุมชั้นความลับของหนังสือ
            </h3>
            <p class="text-xs text-blue-800">
              จำกัดการเข้าถึงเอกสารชั้น ลับ, ลับมาก, ลับที่สุด เฉพาะผู้มีสิทธิ์และผู้ปฏิบัติการที่เกี่ยวข้อง
            </p>
          </div>
        </div>
      </section>
    </div>

    <!-- ==================== TAB 4: การกำกับและหลักฐาน (OPERATIONS & COMPLIANCE) ==================== -->
    <div v-else-if="activeTab === 'operations_compliance'" class="space-y-4">
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b pb-4">
          <div>
            <h2 class="font-semibold text-gray-800 text-lg">การตรวจกำกับและหลักฐานตามระเบียบ</h2>
            <p class="text-sm text-gray-500 mt-0.5">
              รายการที่ต้องติดตามแก้ไข (Operations Center) และหลักฐานการปฏิบัติตามระเบียบสำนักนายกฯ
            </p>
          </div>
          <div class="flex items-center gap-2">
            <RouterLink to="/admin/operations" class="text-xs text-[#800000] font-medium hover:underline">
              ศูนย์เฝ้าระวัง →
            </RouterLink>
            <span class="text-gray-300">|</span>
            <RouterLink to="/admin/operations/compliance" class="text-xs text-[#800000] font-medium hover:underline">
              ทะเบียนหลักฐาน →
            </RouterLink>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="border rounded-xl p-4 bg-gray-50 space-y-2">
            <h3 class="font-semibold text-gray-800 text-sm">การจัดเก็บและการทำลายเอกสาร</h3>
            <p class="text-xs text-gray-500">
              ควบคุมอายุการเก็บรักษาเอกสารราชการ (Retention Lifecycle) และการอายัดหนังสือ (Legal Hold)
            </p>
          </div>
          <div class="border rounded-xl p-4 bg-gray-50 space-y-2">
            <h3 class="font-semibold text-gray-800 text-sm">บันทึกพยานหลักฐานการลงนาม (Signature Evidence)</h3>
            <p class="text-xs text-gray-500">
              จัดเก็บข้อมูลผู้ลงนาม IP Address เวลา และ Checksum เพื่อการตรวจสอบทางนิติวิทยาศาสตร์
            </p>
          </div>
        </div>
      </section>
    </div>

    <!-- Modal ชุดเลขหนังสือ -->
    <AppModal v-if="showSeriesModal" :model-value="true" :title="editingSeries ? 'แก้ไขชุดเลข' : 'เพิ่มชุดเลขใหม่'" @update:model-value="showSeriesModal = false">
      <form class="space-y-4" @submit.prevent="saveSeries">
        <AppInput v-model="seriesForm.name" label="ชื่อชุดเลข" placeholder="เช่น หนังสือส่งออกวิทยาลัย 2569" required />
        <AppInput v-model="seriesForm.prefix" label="คำนำหน้า (Prefix)" placeholder="เช่น ศธ 0601/" required />
        <AppInput v-model="seriesForm.format" label="รูปแบบรหัส" placeholder="{prefix}{running}/{year}" />
        <div class="flex justify-end gap-2 pt-2">
          <AppButton type="button" variant="secondary" @click="showSeriesModal = false">ยกเลิก</AppButton>
          <AppButton type="submit" :loading="seriesSaving">บันทึกชุดเลข</AppButton>
        </div>
      </form>
    </AppModal>

    <!-- Modal จำลองสายการเดินหนังสือ -->
    <AppModal v-if="showSimModal" :model-value="true" title="จำลองเส้นทางเดินหนังสือราชการ" @update:model-value="showSimModal = false">
      <div class="space-y-4">
        <p class="text-xs text-gray-600">
          ทดสอบจำลองว่าหากผู้เสนอส่งหนังสือประเภทนี้ จะวิ่งผ่านใครตามลำดับชั้นใดบ้าง
        </p>
        <AppSelect v-model="simForm.document_type_id" label="ประเภทหนังสือ">
          <option value="">เลือกประเภทหนังสือ</option>
          <option v-for="t in simDocTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
        </AppSelect>
        <AppButton :loading="simulating" @click="runSimulation">เริ่มการจำลอง</AppButton>

        <div v-if="simResult" class="p-3 bg-gray-50 rounded-xl border text-xs space-y-2">
          <h4 class="font-semibold text-gray-800">ผลการจำลองเส้นทาง:</h4>
          <ol class="list-decimal list-inside space-y-1 text-gray-700">
            <li v-for="(step, idx) in simResult.steps" :key="idx">
              {{ step.step_name || `ขั้นตอนที่ ${idx + 1}` }} — {{ step.assignee_name || step.role_name }}
            </li>
          </ol>
        </div>
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

const ADMIN_TABS = [
  { key: 'number_series', label: 'ชุดเลขหนังสือ', icon: '🔢' },
  { key: 'workflow', label: 'สายการเดินหนังสือ', icon: '🔄' },
  { key: 'authority_policies', label: 'อำนาจหน้าที่และนโยบาย', icon: '⚖️' },
  { key: 'operations_compliance', label: 'การกำกับและหลักฐาน', icon: '🛡️' },
]

const activeTab = ref(route.query.tab || 'number_series')

// Tab 1: Number Series State
const seriesList = ref([])
const seriesLoading = ref(false)
const seriesError = ref(null)
const showSeriesModal = ref(false)
const editingSeries = ref(null)
const seriesSaving = ref(false)
const seriesForm = reactive({
  name: '',
  prefix: '',
  format: '{prefix}{running}/{year}',
})

// Tab 2: Simulation State
const showSimModal = ref(false)
const simulating = ref(false)
const simDocTypes = ref([])
const simForm = reactive({
  document_type_id: '',
})
const simResult = ref(null)

function switchTab(tabKey) {
  activeTab.value = tabKey
  router.replace({ query: { ...route.query, tab: tabKey } })
  if (tabKey === 'number_series') fetchSeries()
}

async function fetchSeries() {
  seriesLoading.value = true
  seriesError.value = null
  try {
    const res = await api.get('/saraban/number-series')
    seriesList.value = res.data.data || []
  } catch (err) {
    seriesError.value = getErrorMessage(err)
  } finally {
    seriesLoading.value = false
  }
}

function openSeriesModal(series) {
  editingSeries.value = series
  if (series) {
    seriesForm.name = series.name
    seriesForm.prefix = series.prefix
    seriesForm.format = series.format || '{prefix}{running}/{year}'
  } else {
    seriesForm.name = ''
    seriesForm.prefix = ''
    seriesForm.format = '{prefix}{running}/{year}'
  }
  showSeriesModal.value = true
}

async function saveSeries() {
  seriesSaving.value = true
  try {
    if (editingSeries.value) {
      await api.put(`/saraban/number-series/${editingSeries.value.id}`, seriesForm)
    } else {
      await api.post('/saraban/number-series', seriesForm)
    }
    showSeriesModal.value = false
    fetchSeries()
  } catch (err) {
    alert(getErrorMessage(err))
  } finally {
    seriesSaving.value = false
  }
}

async function openSimulationModal() {
  showSimModal.value = true
  simResult.value = null
  try {
    const res = await api.get('/saraban/documents/options')
    simDocTypes.value = res.data.document_types || []
  } catch (e) {
    console.error(e)
  }
}

async function runSimulation() {
  if (!simForm.document_type_id) return
  simulating.value = true
  try {
    const res = await api.post('/saraban/workflow/simulate', simForm)
    simResult.value = res.data.data || res.data
  } catch (err) {
    alert(getErrorMessage(err))
  } finally {
    simulating.value = false
  }
}

watch(() => route.query.tab, (newTab) => {
  if (newTab && newTab !== activeTab.value) {
    activeTab.value = newTab
    if (newTab === 'number_series') fetchSeries()
  }
})

onMounted(() => {
  if (activeTab.value === 'number_series') fetchSeries()
})
</script>
