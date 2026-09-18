<template>
  <div class="max-w-7xl mx-auto space-y-6" aria-labelledby="vocational-eval-title">
    <!-- Header -->
    <header class="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-3">
      <div>
        <h1 id="vocational-eval-title" class="text-2xl font-bold text-gray-900">รายงานประเมินวันลาและเวลาปฏิบัติราชการ (ก.ค.ศ.)</h1>
        <p class="text-sm text-gray-500 mt-1">สรุปข้อมูลวันลาและเวลาปฏิบัติงานตามรอบเลื่อนขั้นเงินเดือน เกณฑ์วันลา ๒๓ วัน และแฟ้มหลักฐาน ว PA</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          class="inline-flex items-center gap-2 px-4 py-2 bg-[#800000] hover:bg-[#660000] text-white text-sm font-semibold rounded-xl transition-colors shadow-sm"
          @click="openMyPaEvidence"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
          แฟ้มหลักฐาน ว PA ของฉัน
        </button>
      </div>
    </header>

    <!-- Quick Cycle Selection & Filters -->
    <section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm space-y-4">
      <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 pb-4">
        <span class="text-xs font-semibold text-gray-500 uppercase mr-2">รอบการประเมิน ก.ค.ศ.:</span>
        <button
          type="button"
          v-for="c in cyclePresets"
          :key="c.value"
          class="px-3.5 py-1.5 rounded-lg text-xs font-medium transition-all"
          :class="filters.cycle === c.value
            ? 'bg-[#800000] text-white shadow-sm'
            : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
          @click="setCycle(c.value)"
        >
          {{ c.label }}
        </button>
      </div>

      <form class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4" @submit.prevent="loadReport">
        <div>
          <label for="filter-year" class="block text-xs font-medium text-gray-700 mb-1">ปีงบประมาณ (พ.ศ.)</label>
          <select id="filter-year" v-model.number="filters.year" class="w-full border-gray-200 rounded-xl text-sm focus:ring-[#800000] focus:border-[#800000]">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>

        <div>
          <label for="filter-cycle" class="block text-xs font-medium text-gray-700 mb-1">ช่วงรอบเวลา</label>
          <select id="filter-cycle" v-model="filters.cycle" class="w-full border-gray-200 rounded-xl text-sm focus:ring-[#800000] focus:border-[#800000]">
            <option value="round_1">รอบที่ ๑ (๑ ต.ค. – ๓๑ มี.ค.)</option>
            <option value="round_2">รอบที่ ๒ (๑ เม.ย. – ๓๐ ก.ย.)</option>
            <option value="fiscal_year">ตลอดปีงบประมาณ (๑ ต.ค. – ๓๐ ก.ย.)</option>
          </select>
        </div>

        <div>
          <label for="filter-org" class="block text-xs font-medium text-gray-700 mb-1">สังกัดฝ่าย / แผนกวิชา</label>
          <select id="filter-org" v-model="filters.org_unit_id" class="w-full border-gray-200 rounded-xl text-sm focus:ring-[#800000] focus:border-[#800000]">
            <option value="">ทั้งหมดตามสิทธิ์</option>
            <option v-for="unit in orgUnits" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
          </select>
        </div>

        <div>
          <label for="filter-type" class="block text-xs font-medium text-gray-700 mb-1">ประเภทบุคลากร</label>
          <select id="filter-type" v-model="filters.personnel_type_id" class="w-full border-gray-200 rounded-xl text-sm focus:ring-[#800000] focus:border-[#800000]">
            <option value="">ทั้งหมด</option>
            <option v-for="type in personnelTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
          </select>
        </div>

        <div class="sm:col-span-2 lg:col-span-4 flex flex-wrap items-center gap-3 pt-2">
          <button
            type="submit"
            class="rounded-xl bg-[#800000] hover:bg-[#660000] px-5 py-2.5 text-white font-medium text-sm shadow-sm transition-colors"
          >
            แสดงรายงาน
          </button>
          <button
            type="button"
            class="rounded-xl border border-gray-200 hover:bg-gray-50 px-4 py-2.5 text-gray-700 font-medium text-sm transition-colors"
            @click="resetFilters"
          >
            ล้างตัวกรอง
          </button>
          <span v-if="reportData" class="ml-auto text-xs text-gray-500">
            รอบ: {{ reportData.date_range?.from || reportData.period?.start_date }} ถึง {{ reportData.date_range?.to || reportData.period?.end_date }} ({{ reportData.date_range?.label || 'รอบการประเมิน' }}) · พบ {{ filteredRecords.length }} รายการ
          </span>
        </div>
      </form>
    </section>

    <!-- KPI Summary Cards -->
    <section v-if="reportData" class="grid grid-cols-2 lg:grid-cols-4 gap-4">
      <article class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500">บุคลากรทั้งหมด</p>
        <p class="mt-1 text-2xl font-bold text-gray-900">{{ reportData.summary?.total_personnel || 0 }} <span class="text-sm font-normal text-gray-500">คน</span></p>
      </article>

      <article class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500">ปกติ (ผ่านเกณฑ์วันลา)</p>
        <p class="mt-1 text-2xl font-bold text-emerald-600">{{ reportData.summary?.passed_count ?? reportData.summary?.total_normal ?? 0 }} <span class="text-sm font-normal text-gray-500">คน</span></p>
      </article>

      <article class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500">เกินเกณฑ์วันลาสะสม</p>
        <p class="mt-1 text-2xl font-bold text-red-600">{{ reportData.summary?.exceeded_count ?? reportData.summary?.total_exceeded ?? 0 }} <span class="text-sm font-normal text-gray-500">คน</span></p>
      </article>

      <article class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500">เกณฑ์สูงสุด (ข้าราชการครู)</p>
        <p class="mt-1 text-2xl font-bold text-amber-700">๒๓ <span class="text-sm font-normal text-gray-500">วันทำการ</span></p>
      </article>
    </section>

    <!-- Loading State -->
    <div v-if="loading" class="rounded-2xl border border-gray-100 bg-white p-8 shadow-sm">
      <LoadingState type="skeleton-table" :rows="5" :cols="7" message="กำลังประมวลผลข้อมูลการลาและเวลาปฏิบัติราชการ..." />
    </div>

    <!-- Error State -->
    <div v-else-if="loadError" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
      <ErrorState :message="loadError" @retry="loadReport" />
    </div>

    <!-- Results Table -->
    <section v-else-if="reportData" class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
      <header class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
        <h2 class="font-semibold text-gray-800 text-base">บัญชีสรุปวันลาและการปฏิบัติราชการ</h2>
        <span class="text-xs text-gray-500">เกณฑ์ ก.ค.ศ. ลาป่วย+ลากิจ ไม่เกิน ๒๓ วันทำการในรอบการประเมิน</span>
      </header>

      <EmptyState
        v-if="!filteredRecords.length"
        title="ไม่พบข้อมูลบุคลากรตามเงื่อนไข"
        description="ลองเปลี่ยนตัวกรองสังกัดฝ่าย หรือประเภทบุคลากร"
        :icon="ICONS.document"
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-gray-600 border-b border-gray-100 text-xs uppercase">
            <tr>
              <th scope="col" class="p-3 text-right font-semibold w-12">ลำดับ</th>
              <th scope="col" class="p-3 text-left font-semibold">บุคลากร</th>
              <th scope="col" class="p-3 text-left font-semibold">สังกัด / ฝ่าย</th>
              <th scope="col" class="p-3 text-right font-semibold">ลาป่วย</th>
              <th scope="col" class="p-3 text-right font-semibold">ลากิจ</th>
              <th scope="col" class="p-3 text-right font-semibold">ลาพักผ่อน</th>
              <th scope="col" class="p-3 text-right font-semibold">มาสาย</th>
              <th scope="col" class="p-3 text-right font-semibold text-[#800000]">รวมนับได้</th>
              <th scope="col" class="p-3 text-center font-semibold">ผลการประเมิน</th>
              <th scope="col" class="p-3 text-center font-semibold">แฟ้มหลักฐาน</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr
              v-for="(row, index) in filteredRecords"
              :key="row.user_id"
              class="hover:bg-gray-50 transition-colors"
              :class="row.threshold_exceeded ? 'bg-red-50/30' : ''"
            >
              <td class="p-3 text-right text-gray-400 tabular-nums">{{ index + 1 }}</td>
              <td class="p-3">
                <p class="font-medium text-gray-900">{{ row.name }}</p>
                <p class="text-xs text-gray-500">{{ row.position || 'บุคลากร' }} · {{ row.personnel_type || 'ข้าราชการครู' }}</p>
              </td>
              <td class="p-3 text-gray-600">
                <p>{{ row.department_name || row.org_unit_name || '—' }}</p>
                <p class="text-xs text-gray-400">{{ row.division_name }}</p>
              </td>
              <td class="p-3 text-right tabular-nums">{{ formatDays(row.sick_days) }}</td>
              <td class="p-3 text-right tabular-nums">{{ formatDays(row.personal_days) }}</td>
              <td class="p-3 text-right tabular-nums text-gray-500">{{ formatDays(row.vacation_days) }}</td>
              <td class="p-3 text-right tabular-nums text-amber-700 font-medium">
                {{ (row.late_times ?? row.late_count) ? `${row.late_times ?? row.late_count} ครั้ง` : '—' }}
              </td>
              <td class="p-3 text-right font-bold tabular-nums" :class="row.threshold_exceeded ? 'text-red-600' : 'text-gray-900'">
                {{ formatDays(row.total_countable_days) }} วัน
              </td>
              <td class="p-3 text-center">
                <span
                  v-if="!row.threshold_exceeded"
                  class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800"
                >
                  ปกติ (ผ่านเกณฑ์)
                </span>
                <span
                  v-else
                  class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800"
                >
                  เกินเกณฑ์วันลา ({{ formatDays(row.total_countable_days) }}/{{ row.max_threshold_days ?? row.max_allowed_days ?? 23 }} วัน)
                </span>
              </td>
              <td class="p-3 text-center">
                <button
                  type="button"
                  class="inline-flex items-center gap-1 px-3 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-medium transition-colors"
                  @click="openTeacherPaEvidence(row.user_id)"
                >
                  <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                  </svg>
                  ดูแฟ้ม ว PA
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Modal: Teacher PA Evidence Pack -->
    <AppModal
      v-if="showPaModal"
      :model-value="true"
      size="xl"
      :title="`แฟ้มหลักฐาน ว PA และภาระงาน — ${paEvidence?.user?.name || 'บุคลากร'}`"
      @update:model-value="closePaModal"
    >
      <div v-if="loadingPa" class="py-8 text-center">
        <LoadingState type="spinner" message="กำลังเตรียมแฟ้มหลักฐานผลการปฏิบัติงาน..." />
      </div>

      <div v-else-if="paEvidence" class="space-y-6">
        <!-- Personal Header Profile -->
        <div class="rounded-xl bg-gray-50 border border-gray-100 p-4 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
          <div>
            <h3 class="text-base font-bold text-gray-900">{{ paEvidence.user?.name }}</h3>
            <p class="text-xs text-gray-500 mt-0.5">
              ตำแหน่ง: {{ paEvidence.user?.position || 'ครูผู้สอน' }} · สังกัด: {{ paEvidence.user?.org_unit || 'สถานศึกษา' }} ({{ paEvidence.user?.division }})
            </p>
            <p class="text-xs text-gray-500">ประเภทบุคลากร: {{ paEvidence.user?.personnel_type || 'ข้าราชการครู' }}</p>
          </div>
          <div class="text-right">
            <span
              class="inline-block px-3 py-1 rounded-full text-xs font-semibold"
              :class="paEvidence.evaluation?.threshold_exceeded ? 'bg-red-100 text-red-800' : 'bg-emerald-100 text-emerald-800'"
            >
              {{ paEvidence.evaluation?.evaluation_status }}
            </span>
            <p class="text-xs text-gray-400 mt-1">ปีงบประมาณ พ.ศ. {{ paEvidence.fiscal_year }}</p>
          </div>
        </div>

        <!-- Metric Grid -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div class="rounded-xl border border-gray-100 p-3 bg-white">
            <p class="text-xs text-gray-500">วันลาป่วย</p>
            <p class="text-lg font-bold text-gray-800">{{ paEvidence.leave_breakdown?.sick || 0 }} วัน</p>
          </div>
          <div class="rounded-xl border border-gray-100 p-3 bg-white">
            <p class="text-xs text-gray-500">วันลากิจ</p>
            <p class="text-lg font-bold text-gray-800">{{ paEvidence.leave_breakdown?.personal || 0 }} วัน</p>
          </div>
          <div class="rounded-xl border border-gray-100 p-3 bg-white">
            <p class="text-xs text-gray-500">วันลาพักผ่อน</p>
            <p class="text-lg font-bold text-gray-800">{{ paEvidence.leave_breakdown?.vacation || 0 }} วัน</p>
          </div>
          <div class="rounded-xl border border-gray-100 p-3 bg-white">
            <p class="text-xs text-gray-500">มาสาย</p>
            <p class="text-lg font-bold text-amber-700">{{ paEvidence.attendance_summary?.late_count || 0 }} ครั้ง</p>
          </div>
        </div>

        <!-- Workload Summary -->
        <div class="rounded-xl border border-gray-100 p-4 bg-white space-y-2">
          <h4 class="text-xs font-semibold text-gray-500 uppercase">สรุปเวลาปฏิบัติงานและภาระงานราชการ</h4>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-sm">
            <div><span class="text-gray-500">บันทึกเวลาปฏิบัติงาน:</span> <span class="font-medium text-gray-800">{{ paEvidence.workload_summary?.total_days_worked || 0 }} วัน</span></div>
            <div><span class="text-gray-500">ขาดงาน/ไม่ลงเวลา:</span> <span class="font-medium text-gray-800">{{ paEvidence.attendance_summary?.absent_count || 0 }} วัน</span></div>
            <div><span class="text-gray-500">ชั่วโมงปฏิบัติงาน OT:</span> <span class="font-medium text-gray-800">{{ paEvidence.workload_summary?.total_ot_hours || 0 }} ชม.</span></div>
          </div>
        </div>

        <!-- Notice Alert -->
        <p class="text-xs text-gray-500 bg-amber-50 border border-amber-200 rounded-xl p-3 text-amber-900">
          เอกสารชุดนี้เป็นข้อมูลสรุปประกอบการประเมินผลการปฏิบัติงานตามข้อตกลงในการพัฒนางาน (ว PA)
          ตามเกณฑ์ ก.ค.ศ. สำหรับนำเสนอต่อคณะกรรมการประเมิน
        </p>
      </div>

      <template #footer>
        <AppButton variant="secondary" @click="closePaModal">ปิดหน้าต่าง</AppButton>
        <AppButton v-if="paEvidence" @click="printEvidence">พิมพ์แฟ้มหลักฐาน</AppButton>
      </template>
    </AppModal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { getErrorMessage } from '../../utils/errorHandler'
import { ICONS } from '../../utils/icons'
import LoadingState from '../../components/ui/LoadingState.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import AppModal from '../../components/ui/AppModal.vue'
import AppButton from '../../components/ui/AppButton.vue'

const alerts = useAlertStore()

const currentYear = () => {
  const now = new Date()
  return (now.getMonth() >= 9 ? now.getFullYear() + 1 : now.getFullYear()) + 543
}

const currentDefaultCycle = () => {
  const m = new Date().getMonth() + 1
  return m >= 4 && m <= 9 ? 'round_2' : 'round_1'
}

const filters = reactive({
  year: currentYear(),
  cycle: currentDefaultCycle(),
  org_unit_id: '',
  personnel_type_id: '',
})

const yearOptions = [currentYear() + 1, currentYear(), currentYear() - 1, currentYear() - 2]

const cyclePresets = [
  { label: 'รอบที่ ๑ (๑ ต.ค. – ๓๑ มี.ค.)', value: 'round_1' },
  { label: 'รอบที่ ๒ (๑ เม.ย. – ๓๐ ก.ย.)', value: 'round_2' },
  { label: 'ตลอดปีงบประมาณ', value: 'fiscal_year' },
]

const reportData = ref(null)
const loading = ref(false)
const loadError = ref('')
const orgUnits = ref([])
const personnelTypes = ref([])

// Teacher PA Modal State
const showPaModal = ref(false)
const loadingPa = ref(false)
const paEvidence = ref(null)

const setCycle = (c) => {
  filters.cycle = c
  loadReport()
}

const resetFilters = () => {
  filters.year = currentYear()
  filters.cycle = currentDefaultCycle()
  filters.org_unit_id = ''
  filters.personnel_type_id = ''
  loadReport()
}

const formatDays = (d) => {
  if (d === null || d === undefined || d === 0) return '—'
  return new Intl.NumberFormat('th-TH', { maximumFractionDigits: 1 }).format(d)
}

const filteredRecords = computed(() => {
  if (!reportData.value?.records) return []
  return reportData.value.records.filter((r) => {
    if (filters.personnel_type_id && r.personnel_type_id !== Number(filters.personnel_type_id)) {
      return false
    }
    return true
  })
})

const loadReport = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const params = {
      cycle: filters.cycle,
      year: filters.year,
    }
    if (filters.org_unit_id) params.org_unit_id = filters.org_unit_id
    if (filters.personnel_type_id) params.personnel_type_id = filters.personnel_type_id

    const { data } = await api.get('/v1/reports/vocational/evaluation-summary', { params })
    reportData.value = data.data || data
  } catch (err) {
    reportData.value = null
    loadError.value = getErrorMessage(err, 'ไม่สามารถโหลดรายงานประเมินวันลาได้')
    alerts.error(loadError.value)
  } finally {
    loading.value = false
  }
}

const openTeacherPaEvidence = async (targetUserId) => {
  showPaModal.value = true
  loadingPa.value = true
  paEvidence.value = null
  try {
    const { data } = await api.get('/v1/reports/vocational/teacher-pa-evidence', {
      params: {
        target_user_id: targetUserId,
        year: filters.year,
      },
    })
    paEvidence.value = data.data || data
  } catch (err) {
    alerts.error(getErrorMessage(err, 'ไม่สามารถเปิดแฟ้มหลักฐาน ว PA ได้'))
    showPaModal.value = false
  } finally {
    loadingPa.value = false
  }
}

const openMyPaEvidence = async () => {
  showPaModal.value = true
  loadingPa.value = true
  paEvidence.value = null
  try {
    const { data } = await api.get('/v1/reports/vocational/teacher-pa-evidence', {
      params: { year: filters.year },
    })
    paEvidence.value = data.data || data
  } catch (err) {
    alerts.error(getErrorMessage(err, 'ไม่สามารถเปิดแฟ้มหลักฐาน ว PA ของคุณได้'))
    showPaModal.value = false
  } finally {
    loadingPa.value = false
  }
}

const closePaModal = () => {
  showPaModal.value = false
  paEvidence.value = null
}

const printEvidence = () => {
  window.print()
}

onMounted(async () => {
  try {
    const [unitsRes, typesRes] = await Promise.allSettled([
      api.get('/org-units'),
      api.get('/personnel-types'),
    ])
    if (unitsRes.status === 'fulfilled') {
      orgUnits.value = unitsRes.value.data.data || unitsRes.value.data
    }
    if (typesRes.status === 'fulfilled') {
      personnelTypes.value = typesRes.value.data
    }
  } catch (e) {
    console.warn('Failed to load filter choices', e)
  }
  await loadReport()
})
</script>
