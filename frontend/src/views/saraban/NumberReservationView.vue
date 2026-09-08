<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-800">จอง/ออกเลข</h1>
      <p class="text-sm text-gray-500 mt-1">จองเลขหนังสือไว้ล่วงหน้าสำหรับหนังสือที่กำลังจัดทำ</p>
    </div>

    <p v-if="error" role="alert" class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-xl px-4 py-3">
      {{ error }}
    </p>

    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5 space-y-4">
      <h2 class="font-semibold">การจองเลขหนังสือ</h2>

      <div class="grid sm:grid-cols-3 gap-3">
        <AppSelect v-model="form.number_series_id" label="ชุดเลขหนังสือ">
          <option value="">เลือกชุดเลข</option>
          <option v-for="s in series" :key="s.id" :value="s.id">{{ s.name }} ({{ s.code }})</option>
        </AppSelect>
        <AppInput v-model.number="form.buddhist_year" type="number" label="ปี พ.ศ." />
        <div class="flex items-end">
          <AppButton :disabled="!form.number_series_id || busy" :loading="busy" @click="reserve">จองเลข</AppButton>
        </div>
      </div>

      <!--
        การ์ดผลการจอง — แสดงเลขที่ได้ตัวใหญ่ เพราะเจ้าหน้าที่ต้องคัดลอกไปใส่ในร่าง
        หนังสือทันที · เลขที่อ่านผิดหนึ่งหลักทำให้ทะเบียนกับตัวเอกสารไม่ตรงกัน
      -->
      <div v-if="reserved" class="border border-emerald-200 bg-emerald-50 rounded-xl p-5 space-y-2">
        <p class="text-emerald-800 font-semibold">จองเลขหนังสือสำเร็จ</p>
        <p class="text-3xl font-bold text-emerald-900">{{ reserved.formatted }}</p>
        <p class="text-sm text-emerald-800">
          ชุดเลข {{ reserved.series }} · จองเมื่อ {{ formatDateTime(reserved.reserved_at) }}
        </p>
      </div>
    </section>

    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
      <div class="px-5 py-4 border-b border-gray-100">
        <h2 class="font-semibold">เลขที่จองไว้และยังไม่ได้ใช้</h2>
      </div>

      <LoadingState v-if="loading" type="skeleton-table" :rows="4" :columns="5" />
      <EmptyState
        v-else-if="!items.length"
        title="ยังไม่มีเลขที่จองไว้"
        description="เลขที่จองแล้วแต่ยังไม่ผูกกับหนังสือ จะแสดงที่นี่"
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm min-w-[760px]">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left px-5 py-3 font-semibold">เลขที่หนังสือ</th>
              <th class="text-left px-5 py-3 font-semibold">ชุดเลข</th>
              <th class="text-left px-5 py-3 font-semibold">สถานะ</th>
              <th class="text-left px-5 py-3 font-semibold">เรื่อง</th>
              <th class="text-left px-5 py-3 font-semibold">จองเมื่อ</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="item in items" :key="item.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-medium whitespace-nowrap">{{ item.formatted }}</td>
              <td class="px-5 py-3">{{ item.series }}</td>
              <td class="px-5 py-3">{{ statusLabel(item.status) }}</td>
              <td class="px-5 py-3">{{ item.document_subject || '—' }}</td>
              <td class="px-5 py-3 whitespace-nowrap">{{ formatDateTime(item.reserved_at) }}</td>
              <td class="px-5 py-3 text-end whitespace-nowrap">
                <AppButton v-can="'saraban.number.release'" variant="secondary" @click="askRelease(item)">คืนเลข</AppButton>
                <AppButton v-can="'saraban.number.cancel'" variant="ghost" @click="askCancel(item)">ยกเลิกเลข</AppButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!--
      กรอกเหตุผลก่อนยืนยัน — ยกเลิกเลขทำให้ทะเบียนมีช่องว่างถาวรที่ผู้ตรวจสอบ
      ต้องอธิบายได้ · server บังคับเหตุผลอีกชั้นและต้องยืนยันตัวตนซ้ำ
    -->
    <section v-if="selected" class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5 space-y-3">
      <h2 class="font-semibold">{{ selected.title }}</h2>
      <p class="text-sm text-gray-700">{{ selected.description }}</p>
      <AppInput
        v-model="reason"
        :label="selected.requiresReason ? 'เหตุผล (อย่างน้อย 10 ตัวอักษร)' : 'เหตุผล (ถ้ามี)'"
      />
      <div class="flex flex-wrap gap-2">
        <AppButton
          :disabled="selected.requiresReason && reason.trim().length < 10"
          @click="operation = selected.confirm()"
        >ดำเนินการ</AppButton>
        <AppButton variant="secondary" @click="selected = null">ยกเลิก</AppButton>
      </div>
    </section>

    <ConfirmOperation
      v-if="operation"
      v-bind="operation"
      @close="operation = null"
      @done="done"
    />
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import AppButton from '../../components/ui/AppButton.vue'
import AppInput from '../../components/ui/AppInput.vue'
import AppSelect from '../../components/ui/AppSelect.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'
import ConfirmOperation from '../../components/saraban/ConfirmOperation.vue'

const items = ref([])
const series = ref([])
const reserved = ref(null)
const selected = ref(null)
const operation = ref(null)
const reason = ref('')
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const form = reactive({ number_series_id: '', buddhist_year: null })

const statusLabel = (v) => ({ reserved: 'จองไว้', allocated: 'ผูกกับหนังสือแล้ว' }[v] || v)

const formatDateTime = (value) => {
  if (!value) return '—'
  const date = new Date(value)

  return Number.isNaN(date.getTime())
    ? '—'
    : `${date.toLocaleDateString('th-TH')} ${date.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })} น.`
}

async function load() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await api.get('/saraban/numbers/reserved')
    items.value = data.reserved || []
    series.value = data.series || []
    if (!form.buddhist_year) form.buddhist_year = data.current_year
  } catch (e) {
    error.value = getErrorMessage(e, 'โหลดรายการเลขหนังสือไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

async function reserve() {
  busy.value = true
  error.value = ''

  try {
    const { data } = await api.post('/saraban/numbers/reserve', {
      number_series_id: form.number_series_id,
      buddhist_year: form.buddhist_year || undefined,
    })
    reserved.value = data.number
    await load()
  } catch (e) {
    error.value = getErrorMessage(e, 'จองเลขไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

const askRelease = (item) => {
  reason.value = ''
  selected.value = {
    title: 'คืนเลขหนังสือ',
    description: `คืนเลข ${item.formatted} กลับเข้าชุดเลข เลขนี้จะถูกนำไปใช้กับหนังสือฉบับอื่นได้`,
    requiresReason: false,
    confirm: () => ({
      title: 'คืนเลขหนังสือ',
      description: `คืนเลข ${item.formatted} กลับเข้าชุดเลข`,
      action: () => api.post(`/saraban/numbers/${item.id}/release`, { reason: reason.value || undefined }),
    }),
  }
}

const askCancel = (item) => {
  reason.value = ''
  selected.value = {
    title: 'ยกเลิกเลขหนังสือ',
    description: `ยกเลิกเลข ${item.formatted} อย่างเป็นทางการ — เลขนี้จะไม่ถูกใช้อีกเลย และทะเบียนจะมีช่องว่างที่ต้องอธิบายได้`,
    requiresReason: true,
    confirm: () => ({
      title: 'ยกเลิกเลขหนังสือ',
      description: `ยกเลิกเลข ${item.formatted} อย่างถาวร · เหตุผล: ${reason.value}`,
      action: () => api.post(`/saraban/numbers/${item.id}/cancel`, { reason: reason.value }),
    }),
  }
}

async function done() {
  operation.value = null
  selected.value = null
  reason.value = ''
  await load()
}

onMounted(load)
</script>
