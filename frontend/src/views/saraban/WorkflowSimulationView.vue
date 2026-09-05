<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-800">จำลองเส้นทางเดินหนังสือ</h1>
      <p class="text-sm text-gray-500 mt-1">
        ดูว่าหนังสือของหน่วยงานหนึ่งจะเดินผ่านใครบ้าง ก่อนที่จะมีหนังสือจริง ·
        การจำลองไม่สร้างเรื่อง ไม่ออกเลข และไม่ส่งการแจ้งเตือนใด ๆ
      </p>
    </div>

    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
      <header class="px-5 py-4 border-b border-gray-100">
        <h2 class="font-semibold text-gray-800">เงื่อนไขที่ต้องการจำลอง</h2>
      </header>

      <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
        <AppSelect v-model="form.org_unit_id" label="หน่วยงานเจ้าของเรื่อง" required>
          <option value="">เลือกหน่วยงาน</option>
          <option v-for="unit in orgUnits" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
        </AppSelect>

        <AppSelect v-model="form.document_type_id" label="ประเภทหนังสือ">
          <option value="">ทุกประเภท</option>
          <option v-for="type in documentTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
        </AppSelect>

        <AppSelect v-model="form.workflow_version_id" label="เวอร์ชันสายงาน">
          <option value="">เวอร์ชันที่มีผลบังคับใช้</option>
          <option v-for="version in versions" :key="version.id" :value="version.id">
            เวอร์ชัน {{ version.version }} ({{ versionStatusLabel(version.status) }})
          </option>
        </AppSelect>

        <AppInput v-model="form.as_of" type="date" label="ณ วันที่" />
      </div>

      <div class="px-5 pb-5 flex justify-end">
        <AppButton :loading="running" :disabled="!form.org_unit_id" @click="run">จำลองเส้นทาง</AppButton>
      </div>
    </section>

    <section v-if="result" class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
      <header class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center gap-3">
        <div class="flex-1">
          <h2 class="font-semibold text-gray-800">ผลการจำลอง</h2>
          <p v-if="result.workflow" class="text-sm text-gray-500 mt-0.5">
            {{ result.workflow.definition_code }} · เวอร์ชัน {{ result.workflow.version }}
            · ณ วันที่ {{ result.as_of }}
          </p>
        </div>
        <AppBadge :variant="statusVariant">{{ statusLabel }}</AppBadge>
      </header>

      <div v-if="result.warnings.length" class="mx-5 mt-5 p-4 bg-amber-50 border border-amber-200 rounded-xl">
        <ul class="text-sm text-amber-800 list-disc list-inside space-y-0.5">
          <li v-for="(warning, index) in result.warnings" :key="index">{{ warning }}</li>
        </ul>
      </div>

      <div v-if="result.route.length" class="p-5 space-y-3">
        <article
          v-for="step in result.route"
          :key="step.step_code"
          class="border border-gray-100 rounded-xl p-4"
        >
          <div class="flex flex-wrap items-center gap-2">
            <span class="w-7 h-7 rounded-full bg-gray-100 text-gray-700 text-sm font-semibold flex items-center justify-center">
              {{ step.order }}
            </span>
            <h3 class="font-semibold text-gray-800">{{ step.step_name }}</h3>
            <AppBadge v-if="step.is_final" variant="brand">ขั้นสุดท้าย</AppBadge>
            <AppBadge v-if="!step.candidates.length" variant="danger">ไม่พบผู้มีอำนาจ</AppBadge>
          </div>

          <p v-if="step.required_ability" class="text-xs text-gray-400 mt-1 font-mono">
            {{ step.required_ability }}
          </p>

          <p v-if="step.candidates.length" class="text-sm text-gray-600 mt-2">
            ผู้มีอำนาจ: {{ step.candidates.map((c) => c.name).join(', ') }}
          </p>
          <p v-else class="text-sm text-red-600 mt-2">{{ step.unresolved_reason }}</p>
        </article>
      </div>

      <EmptyState
        v-else
        title="ไม่มีเส้นทางให้แสดง"
        description="ตรวจสอบเงื่อนไขที่เลือกอีกครั้ง"
      />
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import { useAlertStore } from '../../stores/alert'
import AppBadge from '../../components/ui/AppBadge.vue'
import AppButton from '../../components/ui/AppButton.vue'
import AppInput from '../../components/ui/AppInput.vue'
import AppSelect from '../../components/ui/AppSelect.vue'
import EmptyState from '../../components/ui/EmptyState.vue'

const STATUS = {
  VALID: { label: 'เส้นทางสมบูรณ์', variant: 'success' },
  VALID_WITH_WARNINGS: { label: 'มีข้อควรระวัง', variant: 'warning' },
  UNRESOLVED_AUTHORITY: { label: 'หาผู้มีอำนาจไม่ได้', variant: 'danger' },
  INVALID: { label: 'จำลองไม่ได้', variant: 'danger' },
}
const VERSION_STATUS = { draft: 'ฉบับร่าง', active: 'มีผลบังคับใช้', retired: 'เลิกใช้แล้ว' }

const alertStore = useAlertStore()
const orgUnits = ref([])
const documentTypes = ref([])
const versions = ref([])
const result = ref(null)
const running = ref(false)

const form = reactive({
  org_unit_id: '',
  document_type_id: '',
  workflow_version_id: '',
  as_of: '',
})

const statusLabel = computed(() => STATUS[result.value?.status]?.label || '—')
const statusVariant = computed(() => STATUS[result.value?.status]?.variant || 'gray')
const versionStatusLabel = (status) => VERSION_STATUS[status] || status

const boot = async () => {
  try {
    const [options, versionList] = await Promise.all([
      api.get('/saraban/documents/options'),
      api.get('/saraban/workflow/versions'),
    ])

    orgUnits.value = options.data.org_units || []
    documentTypes.value = options.data.document_types || []
    versions.value = versionList.data.versions || []
  } catch (err) {
    alertStore.error(getErrorMessage(err, 'โหลดข้อมูลตั้งต้นไม่สำเร็จ'))
  }
}

const run = async () => {
  running.value = true
  try {
    const { data } = await api.post('/saraban/workflow/simulate', {
      org_unit_id: form.org_unit_id,
      document_type_id: form.document_type_id || undefined,
      workflow_version_id: form.workflow_version_id || undefined,
      as_of: form.as_of || undefined,
    })

    result.value = data
  } catch (err) {
    alertStore.error(getErrorMessage(err, 'จำลองเส้นทางไม่สำเร็จ'))
  } finally {
    running.value = false
  }
}

onMounted(boot)
</script>
