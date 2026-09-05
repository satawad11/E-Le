<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">สารบบเรื่อง</h1>
        <p class="text-sm text-gray-500 mt-1">
          จัดกลุ่มหนังสือที่เป็นเรื่องเดียวกัน โดยเลขที่หนังสือและทะเบียนไม่เปลี่ยนแปลง
        </p>
      </div>
      <AppButton v-can="'saraban.document.create'" size="sm" @click="creating = true">+ เปิดเรื่องใหม่</AppButton>
    </div>

    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
      <header class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="flex-1">
          <h2 class="font-semibold text-gray-800">รายการเรื่อง</h2>
        </div>
        <div class="w-full sm:w-72">
          <AppInput v-model="search" label="ค้นหา" placeholder="ชื่อเรื่องหรือรหัส" @keyup.enter="load" />
        </div>
      </header>

      <LoadingState v-if="loading" type="skeleton-table" :rows="4" :columns="4" />
      <ErrorState v-else-if="error" :message="error" @retry="load" />
      <EmptyState
        v-else-if="!cases.length"
        title="ยังไม่มีสารบบเรื่อง"
        description="เปิดเรื่องใหม่เพื่อรวมหนังสือที่เกี่ยวข้องกันไว้ด้วยกัน"
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm min-w-[680px]">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left px-5 py-3 font-semibold">รหัสเรื่อง</th>
              <th class="text-left px-5 py-3 font-semibold">ชื่อเรื่อง</th>
              <th class="text-left px-5 py-3 font-semibold">จำนวนหนังสือ</th>
              <th class="text-left px-5 py-3 font-semibold">สถานะ</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="row in cases" :key="row.id" class="hover:bg-gray-50/60">
              <td class="px-5 py-3 font-mono text-xs text-gray-900">{{ row.code }}</td>
              <td class="px-5 py-3 font-medium text-gray-900">{{ row.title }}</td>
              <td class="px-5 py-3 text-gray-600">{{ row.documents_count }}</td>
              <td class="px-5 py-3">
                <AppBadge :variant="row.status === 'open' ? 'success' : 'gray'">
                  {{ row.status === 'open' ? 'เปิดอยู่' : 'ปิดแล้ว' }}
                </AppBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <AppButton size="sm" variant="secondary" @click="open(row)">เปิดดู</AppButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <AppModal
      v-if="creating"
      :model-value="true"
      title="เปิดสารบบเรื่องใหม่"
      @update:model-value="creating = false"
    >
      <div class="space-y-4">
        <AppInput v-model="form.code" label="รหัสเรื่อง" placeholder="CASE-2569-001" required />
        <AppInput v-model="form.title" label="ชื่อเรื่อง" required />
        <AppTextarea v-model="form.summary" label="สาระโดยย่อ" :rows="3" />
        <p v-if="formError" class="text-sm text-red-600">{{ formError }}</p>
      </div>

      <template #footer>
        <AppButton variant="secondary" @click="creating = false">ยกเลิก</AppButton>
        <AppButton :loading="saving" :disabled="saving" @click="create">บันทึก</AppButton>
      </template>
    </AppModal>

    <AppModal
      v-if="selected"
      :model-value="true"
      :title="`${selected.code} — ${selected.title}`"
      size="lg"
      @update:model-value="selected = null"
    >
      <div class="space-y-4">
        <p v-if="selected.summary" class="text-sm text-gray-600">{{ selected.summary }}</p>

        <EmptyState
          v-if="!caseDocuments.length"
          title="ยังไม่มีหนังสือในเรื่องนี้"
          description="เลือกหนังสือจากทะเบียนเพื่อเพิ่มเข้ามาในเรื่องนี้"
        />

        <ul v-else class="divide-y divide-gray-100">
          <li v-for="doc in caseDocuments" :key="doc.id" class="py-3">
            <p class="text-sm font-medium text-gray-900">{{ doc.subject }}</p>
            <AppButton v-can="'saraban.document.create'" size="sm" variant="ghost" @click="detachDocument(doc)">ถอดออกจากเรื่อง</AppButton>
            <p class="text-xs text-gray-500 mt-0.5">
              {{ doc.document_number || 'ยังไม่มีเลขที่' }}
              <span v-if="doc.note"> · {{ doc.note }}</span>
            </p>
          </li>
        </ul>

        <!--
          บอกจำนวนที่ถูกซ่อนตรง ๆ ไม่ปล่อยให้ผู้ใช้เข้าใจผิดว่าสารบบมีแค่นี้
        -->
        <p v-if="hiddenCount > 0" class="text-xs text-gray-500">
          มีหนังสืออีก {{ hiddenCount }} ฉบับในเรื่องนี้ที่ท่านไม่มีสิทธิ์เข้าถึง
        </p>
        <div v-if="selected.status === 'open'" v-can="'saraban.document.create'" class="border-t pt-3 space-y-3">
          <AppButton variant="secondary" @click="loadCandidates(1)">เลือกหนังสือจากทะเบียน</AppButton>
          <template v-if="candidates !== null">
            <AppSelect v-model="candidateId" label="หนังสือที่ต้องการเพิ่ม"><option value="">เลือกหนังสือ</option><option v-for="doc in candidates" :key="doc.id" :value="doc.id">{{ doc.subject }}</option></AppSelect>
            <div class="flex gap-2"><AppButton :disabled="candidatePage <= 1" @click="loadCandidates(candidatePage - 1)">ก่อนหน้า</AppButton><span>หน้า {{ candidatePage }} / {{ candidateLastPage }}</span><AppButton :disabled="candidatePage >= candidateLastPage" @click="loadCandidates(candidatePage + 1)">ถัดไป</AppButton></div>
            <AppInput v-model="documentNote" label="หมายเหตุการเชื่อมโยง" />
            <AppButton :disabled="!candidateId || attaching" :loading="attaching" @click="attachDocument">เพิ่มหนังสือเข้าเรื่อง</AppButton>
          </template>
        </div>
        <p v-if="documentError" role="alert" class="text-red-700">{{ documentError }}</p>
      </div>

      <template #footer>
        <AppButton variant="secondary" @click="selected = null">ปิด</AppButton>
        <AppButton
          v-if="selected.status === 'open'"
          v-can="'saraban.document.create'"
          variant="secondary"
          @click="closeCase"
        >
          ปิดเรื่อง
        </AppButton>
      </template>
    </AppModal>
    <ConfirmOperation v-if="detachOperation" v-bind="detachOperation" @close="detachOperation = null" @done="detachOperation = null" />
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../../axios'
import AppSelect from '../../components/ui/AppSelect.vue'
import ConfirmOperation from '../../components/saraban/ConfirmOperation.vue'
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
 * สารบบเรื่อง (SoT §22 · INT-18)
 *
 * **หน้าจอนี้ไม่มีทางแก้ตัวหนังสือได้เลย** — ทุกปุ่มทำงานกับความสัมพันธ์
 * ระหว่างเรื่องกับหนังสือเท่านั้น ตัวตนและเลขที่หนังสือไม่เปลี่ยนแปลง
 */
const cases = ref([])
const caseDocuments = ref([])
const hiddenCount = ref(0)
const selected = ref(null)
const loading = ref(true)
const error = ref('')
const formError = ref('')
const saving = ref(false)
const creating = ref(false)
const search = ref('')
const candidates = ref(null), candidateId = ref(''), candidatePage = ref(1), candidateLastPage = ref(1), documentNote = ref(''), documentError = ref(''), attaching = ref(false), detachOperation = ref(null)
async function loadCandidates(page) { documentError.value = ''; try { const { data } = await api.get('/saraban/documents', { params: { page } }); candidates.value = data.data; candidatePage.value = data.current_page; candidateLastPage.value = data.last_page; candidateId.value = '' } catch(e) { documentError.value = getErrorMessage(e, 'โหลดทะเบียนไม่สำเร็จ') } }
async function attachDocument() { attaching.value = true; documentError.value = ''; try { await api.post(`/saraban/cases/${selected.value.id}/documents`, { document_id: candidateId.value, note: documentNote.value }); await open(selected.value); await load() } catch(e) { documentError.value = getErrorMessage(e, 'เพิ่มหนังสือไม่สำเร็จ') } finally { attaching.value = false } }
function detachDocument(doc) { const caseId = selected.value.id; detachOperation.value = { title: 'ถอดหนังสือออกจากเรื่อง', description: `${doc.subject} — ตัวหนังสือและเลขทะเบียนยังคงอยู่`, action: async () => { await api.delete(`/saraban/cases/${caseId}/documents/${doc.id}`); await open(selected.value); await load() } } }

const form = reactive({ code: '', title: '', summary: '' })

const load = async () => {
  loading.value = true
  error.value = ''

  try {
    const { data } = await api.get('/saraban/cases', { params: { q: search.value || undefined } })
    cases.value = data.cases
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดสารบบเรื่องไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const create = async () => {
  saving.value = true
  formError.value = ''

  try {
    await api.post('/saraban/cases', { ...form })
    creating.value = false
    Object.assign(form, { code: '', title: '', summary: '' })
    await load()
  } catch (err) {
    formError.value = getErrorMessage(err, 'เปิดเรื่องไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

const open = async (row) => {
  caseDocuments.value = []
  hiddenCount.value = 0

  try {
    const { data } = await api.get(`/saraban/cases/${row.id}`)
    selected.value = data.case
    caseDocuments.value = data.documents
    hiddenCount.value = data.hidden_count
  } catch (err) {
    error.value = getErrorMessage(err, 'เปิดสารบบเรื่องไม่สำเร็จ')
  }
}

const closeCase = async () => {
  try {
    await api.post(`/saraban/cases/${selected.value.id}/close`)
    selected.value = null
    await load()
  } catch (err) {
    error.value = getErrorMessage(err, 'ปิดเรื่องไม่สำเร็จ')
  }
}

onMounted(load)
</script>
