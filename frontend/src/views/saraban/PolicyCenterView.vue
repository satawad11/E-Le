<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-800">ศูนย์นโยบายงานสารบรรณ</h1>
      <p class="text-sm text-gray-500 mt-1">
        นโยบายที่เผยแพร่แล้วแก้ไม่ได้ · การเปลี่ยนกติกาคือการประกาศเวอร์ชันใหม่เสมอ
        เพื่อให้ตอบได้ตลอดว่าหนังสือแต่ละฉบับถูกตัดสินด้วยกติกาใด
      </p>
    </div>

    <LoadingState v-if="loading" type="skeleton-card" />
    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <section
      v-for="policy in policies"
      v-else
      :key="policy.key"
      class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden"
    >
      <header class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h2 class="font-semibold text-gray-800">{{ policy.name }}</h2>
          <p class="text-sm text-gray-500 mt-0.5">
            <template v-if="policy.published_version">
              ใช้เวอร์ชันที่ {{ policy.published_version }} ตั้งแต่ {{ formatDateTime(policy.published_at) }}
            </template>
            <template v-else>ยังไม่เคยประกาศ — ใช้ค่าเริ่มต้นของระบบ</template>
          </p>
        </div>

        <div class="flex flex-wrap gap-2">
          <AppBadge v-if="policy.has_draft" variant="warning">มีฉบับร่างรอประกาศ</AppBadge>
          <AppButton size="sm" variant="secondary" @click="openHistory(policy)">ประวัติ</AppButton>
          <AppButton v-can="'saraban.policy.manage'" size="sm" @click="openEditor(policy)">แก้ไข</AppButton>
        </div>
      </header>

      <div class="px-5 py-4">
        <pre class="text-xs bg-gray-50 border border-gray-100 rounded-xl p-3 overflow-x-auto">{{ pretty(policy.effective) }}</pre>
      </div>
    </section>

    <AppModal
      v-if="editing"
      :model-value="true"
      :title="`แก้ไขนโยบาย — ${editing.name}`"
      size="lg"
      @update:model-value="editing = null"
    >
      <div class="space-y-3">
        <p class="text-sm text-gray-500">
          บันทึกร่างแล้วยังไม่มีผล ต้องกด "ประกาศใช้" จึงจะบังคับใช้จริง
          และการประกาศต้องยืนยันรหัสผ่านอีกครั้ง
        </p>

        <AppTextarea v-model="draftJson" label="เนื้อหานโยบาย (JSON)" :rows="12" />
        <AppInput v-model="notes" label="บันทึกเหตุผลการเปลี่ยนแปลง" />

        <p v-if="modalError" class="text-sm text-red-600">{{ modalError }}</p>
      </div>

      <template #footer>
        <AppButton variant="secondary" @click="editing = null">ปิด</AppButton>
        <AppButton variant="secondary" :disabled="busy" @click="saveDraft">บันทึกร่าง</AppButton>
        <AppButton :loading="busy" :disabled="busy" @click="publish">ประกาศใช้</AppButton>
      </template>
    </AppModal>

    <AppModal
      v-if="historyOf"
      :model-value="true"
      :title="`ประวัตินโยบาย — ${historyOf.name}`"
      size="lg"
      @update:model-value="historyOf = null"
    >
      <EmptyState v-if="!history.length" title="ยังไม่มีประวัติ" description="นโยบายนี้ยังไม่เคยถูกประกาศ" />

      <ul v-else class="divide-y divide-gray-100">
        <li v-for="version in history" :key="version.version_no" class="py-3">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-sm font-medium text-gray-900">
                เวอร์ชันที่ {{ version.version_no }}
                <AppBadge class="ml-2" :variant="statusVariant(version.status)">
                  {{ statusLabel(version.status) }}
                </AppBadge>
              </p>
              <p class="text-xs text-gray-500 mt-0.5">
                <span v-if="version.published_at">
                  ประกาศเมื่อ {{ formatDateTime(version.published_at) }} โดย {{ version.published_by || '—' }}
                </span>
                <span v-else>ฉบับร่างโดย {{ version.created_by || '—' }}</span>
              </p>
              <p v-if="version.notes" class="text-xs text-gray-500 mt-0.5">{{ version.notes }}</p>
            </div>

            <AppButton
              v-if="version.status === 'archived'"
              v-can="'saraban.policy.manage'"
              size="sm"
              variant="secondary"
              :disabled="busy"
              @click="rollback(version)"
            >
              ย้อนกลับไปใช้
            </AppButton>
          </div>

          <pre class="text-xs bg-gray-50 border border-gray-100 rounded-lg p-2 mt-2 overflow-x-auto">{{ pretty(version.payload) }}</pre>
        </li>
      </ul>

      <template #footer>
        <AppButton variant="secondary" @click="historyOf = null">ปิด</AppButton>
      </template>
    </AppModal>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { onMounted } from 'vue'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import AppBadge from '../../components/ui/AppBadge.vue'
import AppButton from '../../components/ui/AppButton.vue'
import AppInput from '../../components/ui/AppInput.vue'
import AppModal from '../../components/ui/AppModal.vue'
import AppTextarea from '../../components/ui/AppTextarea.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'

/**
 * ศูนย์นโยบายงานสารบรรณ (SoT §18 · INT-16)
 *
 * **แก้เป็น JSON โดยตั้งใจในรอบนี้** — โครงสร้างของแต่ละนโยบายต่างกันมาก
 * การทำฟอร์มเฉพาะทางต้องรู้โครงสร้างทุกตัวล่วงหน้า ซึ่งจะกลายเป็นภาระที่ต้อง
 * ตามแก้ทุกครั้งที่เพิ่มคีย์ · หน้าจอนี้จึงแสดงค่าที่ใช้อยู่จริงให้เห็นชัด
 * และบังคับให้ทุกการเปลี่ยนแปลงผ่านร่าง → ประกาศ ซึ่งเป็นส่วนที่สำคัญกว่า
 */
const STATUS_LABELS = { draft: 'ฉบับร่าง', published: 'ใช้บังคับอยู่', archived: 'เคยใช้' }

const policies = ref([])
const history = ref([])
const historyOf = ref(null)
const editing = ref(null)
const draftJson = ref('')
const notes = ref('')
const loading = ref(true)
const error = ref('')
const modalError = ref('')
const busy = ref(false)

const pretty = (value) => JSON.stringify(value ?? {}, null, 2)
const statusLabel = (status) => STATUS_LABELS[status] || status
const statusVariant = (status) =>
  status === 'published' ? 'success' : status === 'draft' ? 'warning' : 'gray'

const formatDateTime = (value) => {
  if (!value) return '—'

  const date = new Date(value)

  return `${String(date.getDate()).padStart(2, '0')}/${String(date.getMonth() + 1).padStart(2, '0')}/${date.getFullYear() + 543}`
}

const load = async () => {
  loading.value = true
  error.value = ''

  try {
    const { data } = await api.get('/saraban/policies')
    policies.value = data.policies
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดนโยบายไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const openEditor = (policy) => {
  editing.value = policy
  modalError.value = ''
  notes.value = ''
  draftJson.value = pretty(policy.draft_payload ?? policy.effective)
}

const openHistory = async (policy) => {
  historyOf.value = policy
  history.value = []

  try {
    const { data } = await api.get(`/saraban/policies/${policy.key}/history`)
    history.value = data.versions
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดประวัตินโยบายไม่สำเร็จ')
  }
}

const parsedDraft = () => {
  try {
    return JSON.parse(draftJson.value)
  } catch {
    modalError.value = 'เนื้อหานโยบายไม่ใช่ JSON ที่ถูกต้อง'

    return null
  }
}

const saveDraft = async () => {
  const payload = parsedDraft()
  if (payload === null) return

  busy.value = true
  modalError.value = ''

  try {
    await api.post(`/saraban/policies/${editing.value.key}/draft`, {
      payload,
      notes: notes.value || undefined,
    })
    await load()
  } catch (err) {
    modalError.value = getErrorMessage(err, 'บันทึกร่างไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

const publish = async () => {
  const payload = parsedDraft()
  if (payload === null) return

  busy.value = true
  modalError.value = ''

  try {
    await api.post(`/saraban/policies/${editing.value.key}/draft`, {
      payload,
      notes: notes.value || undefined,
    })
    await api.post(`/saraban/policies/${editing.value.key}/publish`)
    editing.value = null
    await load()
  } catch (err) {
    modalError.value = getErrorMessage(err, 'ประกาศใช้นโยบายไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

const rollback = async (version) => {
  busy.value = true

  try {
    await api.post(`/saraban/policies/${historyOf.value.key}/rollback`, {
      version_no: version.version_no,
    })
    await openHistory(historyOf.value)
    await load()
  } catch (err) {
    error.value = getErrorMessage(err, 'ย้อนกลับนโยบายไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>
