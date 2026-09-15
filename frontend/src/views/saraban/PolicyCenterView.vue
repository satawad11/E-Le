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
      <div class="space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-gray-100 pb-3">
          <p class="text-sm text-gray-500">
            บันทึกร่างแล้วยังไม่มีผล ต้องกด "ประกาศใช้" จึงจะบังคับใช้จริง
          </p>

          <div v-if="hasWizard(editing.key)" class="inline-flex rounded-lg border border-gray-200 p-0.5 bg-gray-50 text-xs">
            <button
              type="button"
              class="px-3 py-1.5 rounded-md font-medium transition"
              :class="editorMode === 'wizard' ? 'bg-[#800000] text-white shadow-sm' : 'text-gray-600 hover:text-gray-900'"
              @click="switchMode('wizard')"
            >
              แบบฟอร์มตัวช่วย
            </button>
            <button
              type="button"
              class="px-3 py-1.5 rounded-md font-medium transition"
              :class="editorMode === 'json' ? 'bg-[#800000] text-white shadow-sm' : 'text-gray-600 hover:text-gray-900'"
              @click="switchMode('json')"
            >
              กำหนดค่า JSON (ขั้นสูง)
            </button>
          </div>
        </div>

        <!-- โหมดตัวช่วย (Wizard Form) -->
        <div v-if="editorMode === 'wizard' && hasWizard(editing.key)" class="space-y-4 py-1">
          <!-- 1. นโยบายการเวียนหนังสือ -->
          <template v-if="editing.key === 'distribution'">
            <div class="space-y-4">
              <AppInput
                v-model.number="wizardForm.acknowledgement_due_days"
                type="number"
                min="1"
                max="365"
                label="ระยะเวลากำหนดรับทราบ (วันทำการ)"
                hint="ผู้รับที่ยังไม่รับทราบเกินกำหนดนี้จะแสดงเป็นรายการเกินกำหนดและแจ้งเตือน"
                @input="syncWizardToJson"
              />

              <div class="flex items-start space-x-3 pt-2">
                <input
                  id="allow-all-target"
                  v-model="wizardForm.allow_all_authorized_target"
                  type="checkbox"
                  class="mt-1 w-4 h-4 text-[#800000] border-gray-300 rounded focus:ring-[#800000]"
                  @change="syncWizardToJson"
                />
                <label for="allow-all-target" class="text-sm cursor-pointer select-none">
                  <span class="font-medium text-gray-800">อนุญาตให้เวียนถึงผู้รับทั้งหมดที่ได้รับอนุญาต</span>
                  <span class="block text-xs text-gray-500 mt-0.5">เปิดให้ผู้ส่งสามารถเลือกเวียนหนังสือถึงทุกหน่วยงานในสังกัดพร้อมกันได้</span>
                </label>
              </div>
            </div>
          </template>

          <!-- 2. ผังหน้าเอกสารและตำแหน่ง QR Code -->
          <template v-else-if="editing.key === 'document_layout'">
            <div class="space-y-3">
              <p class="text-xs font-semibold text-gray-700">พิกัดและขนาดของ QR Code ตรวจสอบหนังสือ</p>
              <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <AppInput
                  v-model.number="wizardForm.qr_page"
                  type="number"
                  min="1"
                  label="หน้าที่วาง (Page)"
                  hint="ค่าเริ่มต้น: 1"
                  @input="syncWizardToJson"
                />
                <AppInput
                  v-model.number="wizardForm.qr_x"
                  type="number"
                  label="พิกัด X (จุด)"
                  hint="มุมซ้ายล่าง ค่าเริ่มต้น: 470"
                  @input="syncWizardToJson"
                />
                <AppInput
                  v-model.number="wizardForm.qr_y"
                  type="number"
                  label="พิกัด Y (จุด)"
                  hint="มุมซ้ายล่าง ค่าเริ่มต้น: 740"
                  @input="syncWizardToJson"
                />
                <AppInput
                  v-model.number="wizardForm.qr_size"
                  type="number"
                  min="20"
                  max="300"
                  label="ขนาด QR (pt)"
                  hint="ความกว้างxสูง ค่าเริ่มต้น: 72"
                  @input="syncWizardToJson"
                />
              </div>
            </div>
          </template>

          <!-- 3. กติกาการออกเลขหนังสือ -->
          <template v-else-if="editing.key === 'numbering'">
            <div class="space-y-4 pt-1">
              <div class="flex items-start space-x-3">
                <input
                  id="buddhist-era"
                  v-model="wizardForm.buddhist_era"
                  type="checkbox"
                  class="mt-1 w-4 h-4 text-[#800000] border-gray-300 rounded focus:ring-[#800000]"
                  @change="syncWizardToJson"
                />
                <label for="buddhist-era" class="text-sm cursor-pointer select-none">
                  <span class="font-medium text-gray-800">ใช้ปีพุทธศักราช (พ.ศ.)</span>
                  <span class="block text-xs text-gray-500 mt-0.5">ระเบียบสำนักนายกรัฐมนตรีฯ ข้อ ๑๑ กำหนดให้ใช้ปี พ.ศ. ในเลขทะเบียน</span>
                </label>
              </div>

              <div class="flex items-start space-x-3">
                <input
                  id="annual-reset"
                  v-model="wizardForm.annual_reset"
                  type="checkbox"
                  class="mt-1 w-4 h-4 text-[#800000] border-gray-300 rounded focus:ring-[#800000]"
                  @change="syncWizardToJson"
                />
                <label for="annual-reset" class="text-sm cursor-pointer select-none">
                  <span class="font-medium text-gray-800">รีเซ็ตลำดับเลขเริ่มต้นใหม่ทุกปี (Annual Reset)</span>
                  <span class="block text-xs text-gray-500 mt-0.5">เริ่มนับเลข ๑ ใหม่เมื่อขึ้นปีพุทธศักราชใหม่ตามข้อกำหนดงานสารบรรณ</span>
                </label>
              </div>
            </div>
          </template>
        </div>

        <!-- โหมด JSON ดั้งเดิม -->
        <div v-show="editorMode === 'json' || !hasWizard(editing.key)">
          <AppTextarea v-model="draftJson" label="เนื้อหานโยบาย (JSON)" :rows="12" @input="syncJsonToWizard" />
        </div>

        <AppInput v-model="notes" label="บันทึกเหตุผลการเปลี่ยนแปลง" />

        <p v-if="modalError" class="text-sm text-red-600">{{ modalError }}</p>
      </div>

      <template #footer>
        <AppButton variant="secondary" @click="editing = null">ปิด</AppButton>
        <AppButton variant="secondary" :disabled="busy" @click="saveDraft">บันทึกร่าง</AppButton>
        <AppButton :loading="busy" :disabled="busy" @click="publish">ประกาศใช้</AppButton>
      </template>
    </AppModal>

    <!-- กล่องยืนยันตัวตน Step-Up (MFA / รหัสผ่าน) สำหรับการประกาศใช้นโยบาย -->
    <AppModal
      v-if="stepUpModal"
      :model-value="true"
      title="ยืนยันตัวตนก่อนประกาศใช้นโยบาย"
      size="sm"
      @update:model-value="stepUpModal = false"
    >
      <div class="space-y-3">
        <p class="text-sm text-gray-600">
          การประกาศใช้นโยบายเป็นมาตรการระดับองค์กร กรุณายืนยันตัวตนของท่าน
        </p>

        <div class="flex items-center space-x-2 text-xs border-b border-gray-100 pb-2">
          <button
            type="button"
            class="px-2.5 py-1 rounded font-medium transition"
            :class="stepUpAuthMode === 'password' ? 'bg-[#800000] text-white' : 'text-gray-600 hover:text-gray-900'"
            @click="stepUpAuthMode = 'password'"
          >
            ยืนยันด้วยรหัสผ่าน
          </button>
          <button
            type="button"
            class="px-2.5 py-1 rounded font-medium transition"
            :class="stepUpAuthMode === 'totp' ? 'bg-[#800000] text-white' : 'text-gray-600 hover:text-gray-900'"
            @click="stepUpAuthMode = 'totp'"
          >
            ยืนยันด้วยแอป OTP (MFA)
          </button>
        </div>

        <AppInput
          v-if="stepUpAuthMode === 'password'"
          v-model="stepUpPassword"
          type="password"
          autocomplete="current-password"
          label="รหัสผ่านปัจจุบัน"
        />

        <AppInput
          v-else
          v-model="stepUpTotp"
          type="text"
          inputmode="numeric"
          maxlength="6"
          placeholder="123456"
          label="รหัส OTP 6 หลัก"
          hint="เปิดแอปพลิเคชัน Authenticator แล้วกรอกรหัส 6 หลัก"
        />

        <p v-if="stepUpError" class="text-sm text-red-600">{{ stepUpError }}</p>
      </div>

      <template #footer>
        <AppButton variant="secondary" :disabled="stepUpBusy" @click="stepUpModal = false">ยกเลิก</AppButton>
        <AppButton :loading="stepUpBusy" :disabled="stepUpBusy || !canSubmitStepUp" @click="confirmStepUp">
          ยืนยันและประกาศใช้
        </AppButton>
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
import { computed, onMounted, reactive, ref } from 'vue'
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
 * รองรับทั้งโหมด Wizard สำหรับนโยบายทั่วไป (RISK-45) และโหมด JSON ดั้งเดิม
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

// โหมดการแก้ไข (Wizard / JSON) (RISK-45)
const editorMode = ref('wizard')
const hasWizard = (key) => ['distribution', 'document_layout', 'numbering'].includes(key)

const wizardForm = reactive({
  // distribution
  acknowledgement_due_days: 3,
  allow_all_authorized_target: true,
  // document_layout
  qr_page: 1,
  qr_x: 470,
  qr_y: 740,
  qr_size: 72,
  // numbering
  buddhist_era: true,
  annual_reset: true,
})

// Step-up Auth Modal state (RISK-29)
const stepUpModal = ref(false)
const stepUpAuthMode = ref('password')
const stepUpPassword = ref('')
const stepUpTotp = ref('')
const stepUpError = ref('')
const stepUpBusy = ref(false)
const canSubmitStepUp = computed(() => {
  return stepUpAuthMode.value === 'totp'
    ? stepUpTotp.value.trim().length >= 6
    : !!stepUpPassword.value
})

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

const syncWizardFromJsonPayload = (payload) => {
  if (!payload || typeof payload !== 'object') return

  if (editing.value?.key === 'distribution') {
    wizardForm.acknowledgement_due_days = payload.acknowledgement_due_days ?? 3
    wizardForm.allow_all_authorized_target = payload.allow_all_authorized_target ?? true
  } else if (editing.value?.key === 'document_layout') {
    const qr = payload.qr || {}
    wizardForm.qr_page = qr.page ?? 1
    wizardForm.qr_x = qr.x ?? 470
    wizardForm.qr_y = qr.y ?? 740
    wizardForm.qr_size = qr.size ?? 72
  } else if (editing.value?.key === 'numbering') {
    wizardForm.buddhist_era = payload.buddhist_era ?? true
    wizardForm.annual_reset = payload.annual_reset ?? true
  }
}

const syncWizardToJson = () => {
  if (!editing.value) return

  let current
  try {
    current = JSON.parse(draftJson.value || '{}')
  } catch {
    current = {}
  }

  if (editing.value.key === 'distribution') {
    current.acknowledgement_due_days = Number(wizardForm.acknowledgement_due_days) || 3
    current.allow_all_authorized_target = Boolean(wizardForm.allow_all_authorized_target)
  } else if (editing.value.key === 'document_layout') {
    current.qr = {
      page: Number(wizardForm.qr_page) || 1,
      x: Number(wizardForm.qr_x) || 470,
      y: Number(wizardForm.qr_y) || 740,
      size: Number(wizardForm.qr_size) || 72,
    }
    if (!current.qr_by_document_type) {
      current.qr_by_document_type = {}
    }
  } else if (editing.value.key === 'numbering') {
    current.buddhist_era = Boolean(wizardForm.buddhist_era)
    current.annual_reset = Boolean(wizardForm.annual_reset)
  }

  draftJson.value = pretty(current)
}

const syncJsonToWizard = () => {
  try {
    const parsed = JSON.parse(draftJson.value)
    syncWizardFromJsonPayload(parsed)
  } catch {
    // กำลังพิมพ์ JSON ยังไม่สมบูรณ์
  }
}

const switchMode = (mode) => {
  if (mode === 'wizard') {
    const parsed = parsedDraft()
    if (parsed === null) return
    syncWizardFromJsonPayload(parsed)
  } else {
    syncWizardToJson()
  }
  editorMode.value = mode
}

const openEditor = (policy) => {
  editing.value = policy
  modalError.value = ''
  notes.value = ''
  const payload = policy.draft_payload ?? policy.effective
  draftJson.value = pretty(payload)
  syncWizardFromJsonPayload(payload)
  editorMode.value = hasWizard(policy.key) ? 'wizard' : 'json'
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
  if (editorMode.value === 'wizard' && hasWizard(editing.value?.key)) {
    syncWizardToJson()
  }

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
  if (editorMode.value === 'wizard' && hasWizard(editing.value?.key)) {
    syncWizardToJson()
  }

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
    if (err.response?.status === 423) {
      stepUpModal.value = true
      stepUpError.value = ''
      stepUpPassword.value = ''
      stepUpTotp.value = ''
    } else {
      modalError.value = getErrorMessage(err, 'ประกาศใช้นโยบายไม่สำเร็จ')
    }
  } finally {
    busy.value = false
  }
}

const confirmStepUp = async () => {
  stepUpBusy.value = true
  stepUpError.value = ''

  try {
    const payload = stepUpAuthMode.value === 'totp'
      ? { totp_code: stepUpTotp.value.trim() }
      : { password: stepUpPassword.value }

    await api.post('/auth/confirm-password', payload)
    stepUpModal.value = false
    stepUpPassword.value = ''
    stepUpTotp.value = ''

    // ทำรายการประกาศใช้ต่อ
    await api.post(`/saraban/policies/${editing.value.key}/publish`)
    editing.value = null
    await load()
  } catch (err) {
    stepUpError.value = getErrorMessage(err, 'ยืนยันตัวตนไม่สำเร็จ กรุณาตรวจสอบรหัสผ่านหรือรหัส OTP')
  } finally {
    stepUpBusy.value = false
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
