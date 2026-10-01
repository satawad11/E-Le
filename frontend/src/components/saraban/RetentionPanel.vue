<template>
  <section class="bg-white border border-gray-100 rounded-2xl p-6 shadow-sm space-y-5">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-gray-100 pb-4">
      <div>
        <h2 class="text-lg font-bold text-gray-800">
          {{ documentId ? 'อายุการเก็บรักษาและการอายัดหนังสือ (Document Retention & Legal Hold)' : 'หนังสือครบกำหนดเก็บรักษา (Retention Disposal Queue)' }}
        </h2>
        <p class="text-xs text-gray-500 mt-0.5">
          ระเบียบสำนักนายกรัฐมนตรีว่าด้วยงานสารบรรณ พ.ศ. ๒๕๒๖ (หมวด ๓) · ทำลายไฟล์เอกสารโดยคงทะเบียนไว้ตรวจสอบเสมอ
        </p>
      </div>
      <AppButton variant="secondary" size="sm" :loading="busy" @click="load">
        ตรวจสอบรายการ
      </AppButton>
    </div>

    <p v-if="error" class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-xl p-3" role="alert">
      {{ error }}
    </p>

    <!-- สำหรับหน้ารายละเอียดหนังสือรายฉบับ (Document ID Provided) -->
    <template v-if="state && documentId">
      <!-- แถบเตือนสถานะการอายัด -->
      <div
        class="rounded-xl p-4 flex items-start gap-3 border"
        :class="state.on_hold ? 'bg-amber-50 border-amber-200 text-amber-900' : 'bg-green-50 border-green-200 text-green-900'"
      >
        <span class="text-2xl">{{ state.on_hold ? '🔒' : '🛡️' }}</span>
        <div>
          <h3 class="font-bold text-sm">
            {{ state.on_hold ? 'หนังสือฉบับนี้ถูกอายัด (Legal Hold Active) — ห้ามทำลายเด็ดขาด' : 'ไม่มีการอายัดที่มีผล (No Active Legal Hold)' }}
          </h3>
          <p class="text-xs mt-0.5 opacity-90">
            {{ state.on_hold ? 'การอายัดทางกฎหมาย/การสอบสวนชนะกำหนดการทำลายทุกกรณี ต้องปลดการอายัดก่อนจึงจะดำเนินการต่อได้' : 'เอกสารสามารถดำเนินการตามวงจรอายุการเก็บรักษาและการทำลายตามระเบียบสารบรรณ' }}
          </p>
        </div>
      </div>

      <!-- สรุปกำหนดการเก็บรักษาปัจจุบัน -->
      <div v-if="state.schedule" class="bg-gray-50 border border-gray-200 rounded-xl p-4 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="text-sm font-medium text-gray-800">
            ระยะเวลาเก็บรักษา: <span class="font-bold text-[#800000]">{{ state.schedule.retain_years }} ปี</span>
            (เก็บถึง <span class="font-bold text-gray-900">{{ state.schedule.retain_until }}</span> · {{ statusLabel(state.schedule.status) }})
          </div>
          <AppBadge :variant="scheduleBadgeVariant(state.schedule.status)">
            {{ statusLabel(state.schedule.status) }}
          </AppBadge>
        </div>
        <p v-if="state.schedule.status === 'disposed'" class="text-xs text-red-600 font-mono">
          🗑️ ไฟล์ถูกทำลายแล้วเมื่อ {{ state.schedule.disposed_at || '—' }} (แฮชหลักฐาน: {{ state.schedule.disposed_file_hash || '—' }})
        </p>
        <div class="flex flex-wrap gap-2 pt-1 border-t border-gray-200/60">
          <AppButton
            v-if="state.schedule.status !== 'disposed' && state.schedule.status !== 'approved'"
            v-can="'saraban.policy.manage'"
            size="sm"
            variant="secondary"
            :disabled="state?.on_hold"
            @click="approve(state.schedule)"
          >
            อนุมัติการทำลาย
          </AppButton>
          <AppButton
            v-if="state.schedule.status === 'approved'"
            v-can="'saraban.config.manage'"
            size="sm"
            variant="danger"
            :disabled="state?.on_hold"
            @click="dispose(state.schedule)"
          >
            ทำลายไฟล์ตามอนุมัติ
          </AppButton>
        </div>
      </div>

      <!-- ฟอร์มกำหนดอายุการเก็บรักษา -->
      <div v-can="'saraban.config.manage'" class="border-t border-gray-100 pt-4 space-y-3">
        <div class="flex items-center justify-between">
          <h3 class="font-semibold text-sm text-gray-800">กำหนดหรือปรับปรุงอายุการเก็บรักษา</h3>
        </div>
        <div class="flex flex-wrap gap-2 text-xs">
          <button
            v-for="preset in RETENTION_PRESETS"
            :key="preset.years"
            type="button"
            class="px-2.5 py-1.5 rounded-lg border border-gray-200 bg-gray-50 hover:bg-gray-100 transition-colors text-gray-700 font-medium"
            @click="years = preset.years"
          >
            {{ preset.label }}
          </button>
        </div>
        <div class="flex flex-wrap items-end gap-3">
          <div class="w-48">
            <AppInput v-model.number="years" type="number" min="1" max="100" label="ระยะเวลาเก็บรักษา (ปี)" />
          </div>
          <AppButton :disabled="!years" @click="schedule">
            กำหนดการเก็บรักษา
          </AppButton>
        </div>
      </div>

      <!-- ฟอร์มอายัดหนังสือราชการ (Legal Hold) -->
      <div v-if="canHold" class="border-t border-gray-100 pt-4 space-y-3">
        <h3 class="font-semibold text-sm text-gray-800">การอายัดหนังสือราชการ (Legal Hold Request)</h3>
        <p class="text-xs text-gray-500">
          ใช้กรณีหนังสืออยู่ระหว่างการตรวจสอบของ ป.ป.ช./สตง. คดีความ หรือการสอบสวนวินัย
        </p>
        <div class="flex flex-wrap gap-2 text-xs">
          <button
            v-for="rPreset in REASON_PRESETS"
            :key="rPreset"
            type="button"
            class="px-2.5 py-1.5 rounded-lg border border-amber-200 bg-amber-50 hover:bg-amber-100 transition-colors text-amber-900 font-medium"
            @click="reason = rPreset"
          >
            + {{ rPreset }}
          </button>
        </div>
        <div class="grid sm:grid-cols-2 gap-3">
          <AppInput v-model="reference" label="เลขอ้างอิงการอายัด" placeholder="เช่น อายัดตามคำสั่ง สตง. ที่ ๑๒/๒๕๖๙" />
          <AppInput v-model="reason" label="เหตุผลการอายัด" placeholder="ระบุเหตุผลการอายัดอย่างน้อย ๕ ตัวอักษร" />
        </div>
        <div class="flex justify-end">
          <AppButton variant="secondary" :disabled="!reference.trim() || reason.trim().length < 5" @click="hold">
            อายัดหนังสือ
          </AppButton>
        </div>
      </div>

      <!-- ประวัติการอายัด -->
      <div v-if="state.holds && state.holds.length" class="border-t border-gray-100 pt-4 space-y-3">
        <h3 class="font-semibold text-sm text-gray-800">ประวัติการอายัดหนังสือฉบับนี้</h3>
        <ul class="space-y-2">
          <li
            v-for="h in state.holds"
            :key="h.id"
            class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border rounded-xl p-3 text-sm bg-gray-50"
          >
            <div class="space-y-0.5">
              <div class="font-medium text-gray-900 flex items-center gap-2">
                <span>{{ h.reference }}</span>
                <AppBadge :variant="h.active ? 'warning' : 'gray'">{{ h.active ? '🔒 มีผลบังคับ' : 'ปลดแล้ว' }}</AppBadge>
              </div>
              <p class="text-xs text-gray-600">{{ h.reason }}</p>
              <p class="text-xs text-gray-400">
                อายัดเมื่อ: {{ h.placed_at || '—' }} โดย {{ h.placed_by || 'เจ้าหน้าที่' }}
                <span v-if="h.released_at"> · ปลดอายัดเมื่อ: {{ h.released_at }} ({{ h.release_note || 'ไม่มีหมายเหตุ' }})</span>
              </p>
            </div>
            <AppButton v-if="h.active && canHold" size="sm" variant="outline" @click="release(h)">
              ปลดอายัด
            </AppButton>
          </li>
        </ul>
      </div>
    </template>

    <!-- สำหรับหน้ารายการคิวครบกำหนดทำลาย (Retention Queue List) -->
    <template v-else-if="rows !== null">
      <div v-if="!rows.length" class="text-center py-8 text-gray-500">
        <span class="text-3xl block mb-2">📋</span>
        <p class="font-medium">ไม่มีหนังสือที่ครบกำหนดทำลายในขณะนี้</p>
        <p class="text-xs text-gray-400 mt-1">หนังสือทั้งหมดอยู่ในช่วงอายุการเก็บรักษา หรือถูกอายัดตามกฎหมาย</p>
      </div>

      <ul v-else class="space-y-4">
        <li
          v-for="row in rows"
          :key="row.id"
          class="border border-gray-200 rounded-xl p-4 space-y-3 bg-gray-50/50 hover:bg-gray-50 transition-colors"
        >
          <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
            <div>
              <h3 class="font-bold text-gray-900">{{ row.document?.subject || 'หนังสือในกำหนดการ' }}</h3>
              <p class="text-xs text-gray-500 mt-0.5">
                ครบกำหนดเก็บรักษา: <span class="font-semibold text-gray-800">{{ row.retain_until }}</span>
              </p>
            </div>
            <AppBadge :variant="scheduleBadgeVariant(row.status)">
              {{ statusLabel(row.status) }}
            </AppBadge>
          </div>

          <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200/60 pt-3">
            <router-link
              v-if="row.document?.id && !documentId"
              :to="`/saraban/documents/${row.document.id}`"
              class="text-xs font-semibold text-[#800000] hover:underline flex items-center gap-1"
            >
              📄 เปิดดูหนังสือและประวัติการอายัด
            </router-link>

            <div class="flex flex-wrap gap-2">
              <AppButton
                v-if="row.status !== 'disposed' && row.status !== 'approved'"
                v-can="'saraban.policy.manage'"
                size="sm"
                variant="secondary"
                :disabled="state?.on_hold"
                @click="approve(row)"
              >
                อนุมัติการทำลาย
              </AppButton>
              <AppButton
                v-if="row.status === 'approved'"
                v-can="'saraban.config.manage'"
                size="sm"
                variant="danger"
                :disabled="state?.on_hold"
                @click="dispose(row)"
              >
                ทำลายไฟล์ตามอนุมัติ
              </AppButton>
            </div>
          </div>
        </li>
      </ul>
    </template>

    <ConfirmOperation v-if="operation" v-bind="operation" @close="operation = null" @done="completed" />
  </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import api from '../../axios'
import { useAuthStore } from '../../stores/auth'
import { getErrorMessage } from '../../utils/errorHandler'
import AppBadge from '../ui/AppBadge.vue'
import AppButton from '../ui/AppButton.vue'
import AppInput from '../ui/AppInput.vue'
import ConfirmOperation from '../ui/ConfirmOperation.vue'

const props = defineProps({
  documentId: { type: [String, Number], default: null },
})

let auth = null
try {
  auth = useAuthStore()
} catch {
  auth = null
}
const canHold = computed(() => auth?.hasAnyPermission?.(['saraban.policy.manage', 'saraban.config.manage']) ?? true)

const state = ref(null)
const rows = ref(null)
const busy = ref(false)
const error = ref('')
const operation = ref(null)
const years = ref('')
const reference = ref('')
const reason = ref('')

const RETENTION_PRESETS = [
  { years: 1, label: '๑ ปี (เรื่องทั่วไป/เสร็จสิ้น)' },
  { years: 5, label: '๕ ปี (การเงิน/พัสดุ/ธุรการ)' },
  { years: 10, label: '๑๐ ปี (บริหารงาน/หลักฐาน)' },
  { years: 20, label: '๒๐ ปี (มาตรฐานสำคัญ)' },
  { years: 25, label: '๒๕ ปี (สำคัญ/เก็บถาวร)' },
]

const REASON_PRESETS = [
  'คดีความ/ข้อพิพาททางกฎหมาย',
  'การตรวจสอบของ สตง./ป.ป.ช.',
  'การสอบสวนวินัย/ข้อเท็จจริง',
  'รอผลการพิจารณาอุทธรณ์/ศาลปกครอง',
]

const statusLabel = (s) => ({
  scheduled: 'อยู่ระหว่างเก็บรักษา',
  proposed: 'รอกำหนดการ',
  approved: 'อนุมัติทำลายแล้ว',
  disposed: 'ทำลายไฟล์แล้ว (คงทะเบียน)',
  pending: 'รอพิจารณา',
}[s] || 'อยู่ระหว่างดำเนินการ')

const scheduleBadgeVariant = (s) => ({
  scheduled: 'gray',
  proposed: 'info',
  approved: 'warning',
  disposed: 'danger',
}[s] || 'gray')

async function load() {
  busy.value = true
  error.value = ''
  try {
    if (props.documentId) {
      state.value = (await api.get(`/saraban/documents/${props.documentId}/retention`)).data
      rows.value = state.value.schedule ? [state.value.schedule] : []
    } else {
      rows.value = (await api.get('/saraban/retention/due')).data.due
    }
  } catch (e) {
    error.value = getErrorMessage(e, 'โหลดการเก็บรักษาไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

function schedule() {
  operation.value = {
    title: 'กำหนดการเก็บรักษา',
    description: `เก็บหนังสือ ${years.value} ปีตามการพิจารณาของเจ้าหน้าที่`,
    action: () => api.post(`/saraban/documents/${props.documentId}/retention`, { retain_years: years.value }),
  }
}

function hold() {
  operation.value = {
    title: 'อายัดหนังสือ',
    description: `${reference.value}: ${reason.value}`,
    action: () => api.post(`/saraban/documents/${props.documentId}/holds`, {
      reference: reference.value,
      reason: reason.value,
    }),
  }
}

function release(h) {
  operation.value = {
    title: 'ปลดอายัด',
    description: `ปลดอายัดตามเลขอ้างอิง ${h.reference}`,
    action: () => api.post(`/saraban/holds/${h.id}/release`),
  }
}

function approve(row) {
  operation.value = {
    title: 'อนุมัติการทำลาย',
    description: 'อนุมัติให้เจ้าหน้าที่อีกคนทำลายไฟล์เมื่อครบกำหนดและไม่มีการอายัด',
    action: () => api.post(`/saraban/retention/${row.id}/approve`),
  }
}

function dispose(row) {
  operation.value = {
    title: 'ทำลายไฟล์',
    description: 'การทำลายไฟล์ย้อนกลับไม่ได้ โปรดตรวจหนังสือและอนุมัติก่อนยืนยัน ทะเบียนยังคงอยู่เพื่อการตรวจสอบ',
    action: () => api.post(`/saraban/retention/${row.id}/dispose`),
  }
}

async function completed() {
  operation.value = null
  await load()
}
</script>

