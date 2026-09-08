<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">สายการเดินหนังสือ</h1>
        <p class="text-sm text-gray-500 mt-1">
          กำหนดว่าหนังสือต้องผ่านใครบ้างก่อนออกเลข · ใช้เฉพาะงานสารบรรณ ไม่เกี่ยวกับการลา
        </p>
      </div>
      <AppButton variant="ghost" @click="goToSimulation">จำลองเส้นทาง</AppButton>
    </div>

    <LoadingState v-if="loading" type="skeleton-card" />
    <ErrorState v-else-if="loadError" :message="loadError" @retry="boot" />

    <template v-else>
      <p v-if="error" role="alert" class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-xl px-4 py-3">
        {{ error }}
      </p>

      <!-- สายที่ใช้อยู่จริง — อ่านอย่างเดียวเสมอ -->
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center gap-3">
          <div class="flex-1 min-w-0">
            <h2 class="font-semibold text-gray-800">สายที่ใช้อยู่</h2>
            <p class="text-sm text-gray-500 mt-0.5">
              เวอร์ชันที่ {{ active?.version ?? '—' }} · เริ่มใช้ {{ formatDate(active?.effective_from) }}
            </p>
          </div>
          <AppBadge variant="success">ใช้งานอยู่</AppBadge>
        </header>

        <div class="p-5">
          <StepChain v-if="activeDetail" :steps="activeDetail.steps" :permission-labels="permissionLabels" />
          <EmptyState v-else title="ยังไม่มีสายที่ใช้งาน" description="สร้างฉบับร่างแล้วเผยแพร่เพื่อเริ่มใช้งาน" />
        </div>
      </section>

      <!-- ฉบับร่าง — ที่เดียวที่แก้ได้ -->
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center gap-3">
          <div class="flex-1 min-w-0">
            <h2 class="font-semibold text-gray-800">ฉบับร่าง</h2>
            <p class="text-sm text-gray-500 mt-0.5">
              แก้ไขได้เฉพาะฉบับร่าง · เวอร์ชันที่เผยแพร่แล้วเป็นหลักฐานของเรื่องที่เดินไปแล้ว
            </p>
          </div>
          <AppButton v-if="!draft" :loading="busy" @click="createDraft">สร้างฉบับร่างจากสายที่ใช้อยู่</AppButton>
        </header>

        <div v-if="!draft" class="p-5">
          <EmptyState
            title="ยังไม่มีฉบับร่าง"
            description="กดสร้างฉบับร่าง ระบบจะคัดลอกสายที่ใช้อยู่มาให้ แล้วปรับได้ตามต้องการ"
          />
        </div>

        <div v-else class="p-5 space-y-4">
          <ol class="space-y-3">
            <li
              v-for="(step, index) in draftSteps"
              :key="index"
              class="border border-gray-200 rounded-xl p-4 grid gap-3 sm:grid-cols-[auto,1fr,1fr,auto] sm:items-end"
            >
              <span
                class="shrink-0 w-8 h-8 rounded-full bg-[#800000]/10 text-[#800000] grid place-items-center font-semibold"
                aria-hidden="true"
              >
                {{ index + 1 }}
              </span>

              <AppInput v-model="step.name" :label="`ชื่อขั้นที่ ${index + 1}`" />

              <AppSelect v-model="step.assignee_permission" label="ผู้รับผิดชอบ (ตามสิทธิ์)">
                <option v-for="permission in permissions" :key="permission.name" :value="permission.name">
                  {{ permission.label }}
                </option>
              </AppSelect>

              <div class="flex gap-2">
                <AppButton size="sm" variant="ghost" :disabled="index === 0" @click="move(index, -1)">
                  เลื่อนขึ้น
                </AppButton>
                <AppButton size="sm" variant="ghost" :disabled="index === draftSteps.length - 1" @click="move(index, 1)">
                  เลื่อนลง
                </AppButton>
                <AppButton size="sm" variant="ghost" :disabled="draftSteps.length <= 1" @click="removeStep(index)">
                  เอาออก
                </AppButton>
              </div>
            </li>
          </ol>

          <!--
            อธิบายสิ่งที่ระบบทำให้ — ผู้ดูแลไม่ได้ผูกเส้นทางเอง จึงต้องรู้ว่า
            ลำดับที่จัดไว้จะกลายเป็นเส้นทางแบบใด ไม่ใช่เดาเอง
          -->
          <p class="text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3">
            ระบบผูกเส้นทางให้จากลำดับนี้เอง — เห็นชอบแล้วไปขั้นถัดไป ขั้นสุดท้ายคือลงนามและออกเลข ·
            ส่งกลับจะย้อนไปขั้นก่อนหน้า (ขั้นแรกคืนเรื่องให้ผู้ยื่น) · ไม่เห็นชอบจบเรื่องได้ทุกขั้น
            และทั้งสองอย่างต้องระบุเหตุผล
          </p>

          <div class="flex flex-wrap gap-2">
            <AppButton variant="secondary" :disabled="draftSteps.length >= MAX_STEPS" @click="addStep">
              + เพิ่มขั้น
            </AppButton>
            <AppButton variant="secondary" :loading="busy" :disabled="!canSave" @click="save">บันทึกฉบับร่าง</AppButton>
            <AppButton :disabled="!canSave" @click="publishing = true">เผยแพร่ให้มีผลใช้งาน</AppButton>
            <AppButton variant="ghost" :loading="busy" @click="discarding = true">ทิ้งฉบับร่าง</AppButton>
          </div>
        </div>
      </section>

      <!-- ประวัติเวอร์ชัน — ตรวจย้อนหลังได้ว่าเรื่องช่วงใดเดินตามกติกาใด -->
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100">
          <h2 class="font-semibold text-gray-800">ประวัติเวอร์ชัน</h2>
        </header>
        <div class="overflow-x-auto">
          <table class="w-full text-sm min-w-[620px]">
            <thead class="bg-gray-50 text-gray-600 text-left">
              <tr>
                <th class="px-5 py-3 font-medium">เวอร์ชัน</th>
                <th class="px-5 py-3 font-medium">สถานะ</th>
                <th class="px-5 py-3 font-medium">จำนวนขั้น</th>
                <th class="px-5 py-3 font-medium">ช่วงที่ใช้</th>
                <th class="px-5 py-3 font-medium">หมายเหตุ</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="version in versions" :key="version.id">
                <td class="px-5 py-3 text-gray-900">{{ version.version }}</td>
                <td class="px-5 py-3">
                  <AppBadge :variant="STATUS[version.status]?.variant || 'gray'">
                    {{ STATUS[version.status]?.label || version.status }}
                  </AppBadge>
                </td>
                <td class="px-5 py-3 text-gray-600">{{ version.steps_count }}</td>
                <td class="px-5 py-3 text-gray-600">
                  {{ formatDate(version.effective_from) }} – {{ version.effective_to ? formatDate(version.effective_to) : 'ปัจจุบัน' }}
                </td>
                <td class="px-5 py-3 text-gray-600">{{ version.notes || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <ConfirmOperation
        v-if="publishing"
        title="เผยแพร่สายการเดินหนังสือ"
        :description="publishDescription"
        :action="publish"
        @close="publishing = false"
        @done="onPublished"
      />

      <ConfirmOperation
        v-if="discarding"
        title="ทิ้งฉบับร่าง"
        description="ฉบับร่างนี้จะถูกลบทิ้ง สายที่ใช้งานอยู่ไม่เปลี่ยนแปลง"
        :action="discard"
        @close="discarding = false"
        @done="onDiscarded"
      />
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { getErrorMessage } from '../../utils/errorHandler'
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'
import AppBadge from '../../components/ui/AppBadge.vue'
import AppButton from '../../components/ui/AppButton.vue'
import AppInput from '../../components/ui/AppInput.vue'
import AppSelect from '../../components/ui/AppSelect.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import ConfirmOperation from '../../components/saraban/ConfirmOperation.vue'
import StepChain from '../../components/saraban/StepChain.vue'

/**
 * จัดการสายการเดินหนังสือ (RISK-26 · NAV §5.2)
 *
 * **ผู้ดูแลตอบคำถามเดียว: "หนังสือต้องผ่านใครบ้าง ตามลำดับใด"**
 * ส่วนเส้นทาง (เห็นชอบไปไหน ส่งกลับไปไหน) ระบบผูกให้จากลำดับนั้นที่ฝั่ง server
 * — สายงานที่ผูกเส้นทางเองผิดจะกลายเป็นเรื่องค้างถาวรที่ไม่มีข้อความใดบอก
 *
 * ผู้รับผิดชอบเลือกจากรายชื่อสิทธิ์ที่ server ส่งมา ไม่ให้พิมพ์เอง — พิมพ์ผิด
 * หนึ่งตัวจะได้ขั้นที่ไม่มีใครทำได้ และกว่าจะรู้ก็ตอนมีเรื่องค้างแล้ว
 */
const STATUS = {
  draft: { label: 'ฉบับร่าง', variant: 'warning' },
  active: { label: 'ใช้งานอยู่', variant: 'success' },
  retired: { label: 'ปลดแล้ว', variant: 'gray' },
}

const MAX_STEPS = 12

const router = useRouter()
const alertStore = useAlertStore()

const versions = ref([])
const permissions = ref([])
const activeDetail = ref(null)
const draft = ref(null)
const draftSteps = ref([])
const loading = ref(true)
const loadError = ref('')
const error = ref('')
const busy = ref(false)
const publishing = ref(false)
const discarding = ref(false)

const active = computed(() => versions.value.find((v) => v.status === 'active') || null)

const permissionLabels = computed(() =>
  Object.fromEntries(permissions.value.map((item) => [item.name, item.label])),
)

const canSave = computed(() =>
  draftSteps.value.length > 0 &&
  draftSteps.value.every((step) => step.name.trim() !== '' && step.assignee_permission),
)

const publishDescription = computed(
  () =>
    `สายใหม่จะมี ${draftSteps.value.length} ขั้น: ${draftSteps.value.map((s) => s.name).join(' → ')} — ` +
    'มีผลกับหนังสือที่เสนอหลังจากนี้เท่านั้น เรื่องที่กำลังเดินอยู่ยังใช้สายเดิมจนจบ',
)

const formatDate = (value) => (value ? ThaiDateFormatter.toThaiText(value) : '—')

async function boot() {
  loading.value = true
  loadError.value = ''

  try {
    const { data } = await api.get('/saraban/workflow/design')

    versions.value = data.versions || []
    permissions.value = data.assignable_permissions || []

    const activeVersion = versions.value.find((v) => v.status === 'active')
    const draftVersion = versions.value.find((v) => v.status === 'draft')

    activeDetail.value = activeVersion ? (await api.get(`/saraban/workflow/design/${activeVersion.id}`)).data : null

    if (draftVersion) {
      await loadDraft(draftVersion.id)
    } else {
      draft.value = null
      draftSteps.value = []
    }
  } catch (err) {
    loadError.value = getErrorMessage(err, 'โหลดสายการเดินหนังสือไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const loadDraft = async (id) => {
  const { data } = await api.get(`/saraban/workflow/design/${id}`)

  draft.value = data
  draftSteps.value = (data.steps || []).map((step) => ({
    code: step.code,
    name: step.name,
    assignee_permission: step.assignee_permission,
  }))
}

const run = async (action, fallback) => {
  busy.value = true
  error.value = ''

  try {
    return await action()
  } catch (err) {
    error.value = getErrorMessage(err, fallback)

    return null
  } finally {
    busy.value = false
  }
}

const createDraft = () =>
  run(async () => {
    const { data } = await api.post('/saraban/workflow/design/drafts')

    await boot()
    alertStore.success(`สร้างฉบับร่างเวอร์ชันที่ ${data.version} แล้ว`)
  }, 'สร้างฉบับร่างไม่สำเร็จ')

const save = () =>
  run(async () => {
    await api.put(`/saraban/workflow/design/${draft.value.id}`, { steps: payload() })
    await loadDraft(draft.value.id)
    alertStore.success('บันทึกฉบับร่างแล้ว')
  }, 'บันทึกฉบับร่างไม่สำเร็จ')

/*
 * บันทึกก่อนเผยแพร่เสมอ — สิ่งที่ผู้ดูแลเห็นบนหน้าจอคือสิ่งที่ต้องถูกเผยแพร่
 * ถ้าเผยแพร่เฉพาะที่บันทึกไว้ จะได้สายที่ไม่ตรงกับที่เพิ่งแก้โดยไม่มีอะไรเตือน
 */
const publish = async () => {
  await api.put(`/saraban/workflow/design/${draft.value.id}`, { steps: payload() })

  return api.post(`/saraban/workflow/design/${draft.value.id}/publish`)
}

const discard = () => api.delete(`/saraban/workflow/design/${draft.value.id}`)

const payload = () =>
  draftSteps.value.map((step) => ({
    code: step.code,
    name: step.name.trim(),
    assignee_permission: step.assignee_permission,
  }))

const onPublished = async () => {
  publishing.value = false
  alertStore.success('เผยแพร่สายการเดินหนังสือแล้ว')
  await boot()
}

const onDiscarded = async () => {
  discarding.value = false
  alertStore.success('ทิ้งฉบับร่างแล้ว')
  await boot()
}

const addStep = () => {
  draftSteps.value = [...draftSteps.value, { name: '', assignee_permission: permissions.value[0]?.name || '' }]
}

const removeStep = (index) => {
  draftSteps.value = draftSteps.value.filter((_, position) => position !== index)
}

/** สลับกับขั้นข้างเคียง — ลำดับคือสิ่งเดียวที่กำหนดเส้นทาง */
const move = (index, offset) => {
  const next = [...draftSteps.value]
  const target = index + offset

  ;[next[index], next[target]] = [next[target], next[index]]
  draftSteps.value = next
}

const goToSimulation = () => router.push({ name: 'saraban-workflow-simulate' })

onMounted(boot)
</script>
