<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
      <h2 class="text-2xl font-bold text-gray-800">การลงเวลาของฉัน</h2>
      
      <div class="flex flex-wrap gap-2">
        <select v-model="selectedMonth" class="border-gray-300 rounded-lg text-sm focus:border-[#800000] focus:ring-[#800000]">
          <option v-for="(name, index) in months" :key="index" :value="index + 1">{{ name }}</option>
        </select>
        <select v-model="selectedYear" class="border-gray-300 rounded-lg text-sm focus:border-[#800000] focus:ring-[#800000]">
          <option v-for="y in years" :key="y" :value="y">{{ y + 543 }}</option>
        </select>
        <button @click="fetchHistory" class="bg-[#800000] text-white px-4 py-2 rounded-lg text-sm hover:bg-[#600000]">
          ค้นหา
        </button>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-gray-50 border-b border-gray-100 text-gray-600 text-sm">
              <th class="py-4 px-4 font-semibold w-24">วันที่</th>
              <th class="py-4 px-4 font-semibold text-center">เวลาเข้า</th>
              <th class="py-4 px-4 font-semibold text-center">เวลาออก</th>
              <th class="py-4 px-4 font-semibold text-center">เข้า OT</th>
              <th class="py-4 px-4 font-semibold text-center">ออก OT</th>
              <th class="py-4 px-4 font-semibold text-center">สถานะ</th>
              <th class="py-4 px-4 font-semibold">หมายเหตุ</th>
              <th class="py-4 px-4 font-semibold text-right">แก้ไข</th>
            </tr>
          </thead>
          <tbody class="text-sm">
            <tr v-if="loading">
              <td colspan="8" class="py-8 text-center text-gray-500">กำลังโหลดข้อมูล...</td>
            </tr>
            <tr v-else-if="records.length === 0">
              <td colspan="8" class="py-8 text-center text-gray-500">ไม่มีข้อมูลในเดือนนี้</td>
            </tr>
            <tr v-else v-for="record in records" :key="record.id" class="border-b border-gray-50 hover:bg-gray-50/50">
              <td class="py-4 px-4 font-medium text-gray-900">
                {{ formatDate(record.date) }}
              </td>
              <td class="py-4 px-4 text-center">
                <div v-if="record.check_in_time">
                  <span class="inline-flex items-center gap-1 text-green-700 bg-green-50 px-2 py-1 rounded text-xs font-medium border border-green-100">
                    {{ formatTime(record.check_in_time) }}
                  </span>
                  <div v-if="record.late_minutes > 0" class="text-[10px] text-red-500 mt-1">สาย {{ record.late_minutes }} นาที</div>
                </div>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td class="py-4 px-4 text-center">
                <div v-if="record.check_out_time">
                  <span class="inline-flex items-center gap-1 text-orange-700 bg-orange-50 px-2 py-1 rounded text-xs font-medium border border-orange-100">
                    {{ formatTime(record.check_out_time) }}
                  </span>
                  <div v-if="record.early_leave_minutes > 0" class="text-[10px] text-yellow-600 mt-1">ออกก่อน {{ record.early_leave_minutes }} นาที</div>
                </div>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td class="py-4 px-4 text-center">
                <span v-if="record.ot_check_in_time" class="inline-flex items-center gap-1 text-blue-700 bg-blue-50 px-2 py-1 rounded text-xs font-medium border border-blue-100">
                  {{ formatTime(record.ot_check_in_time) }}
                </span>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td class="py-4 px-4 text-center">
                <div v-if="record.ot_check_out_time">
                  <span class="inline-flex items-center gap-1 text-purple-700 bg-purple-50 px-2 py-1 rounded text-xs font-medium border border-purple-100">
                    {{ formatTime(record.ot_check_out_time) }}
                  </span>
                  <div v-if="record.ot_minutes > 0" class="text-[10px] text-purple-600 mt-1">
                    รวม {{ Math.floor(record.ot_minutes / 60) }} ชม. {{ record.ot_minutes % 60 }} นาที
                  </div>
                </div>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td class="py-4 px-4 text-center">
                <span class="inline-block px-2.5 py-1 rounded-full text-xs font-medium" :class="statusClass(record.status)">
                  {{ statusText(record.status) }}
                </span>
              </td>
              <td class="py-4 px-4 text-gray-600 truncate max-w-[150px]" :title="record.notes">
                {{ record.notes || '-' }}
              </td>
              <td class="py-4 px-4 text-right">
                <button
                  type="button"
                  class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                  @click="openCorrection(record)"
                >
                  ขอแก้ไข
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!--
      คำขอแก้ไขเวลาของฉัน (CLEANUP-02)
      ฟีเจอร์นี้มีสายอนุมัติและ audit ครบมาตั้งแต่ LEGACY-08 แต่ไม่เคยมีหน้าจอให้ใช้
    -->
    <section class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <header class="px-5 py-4 border-b border-gray-100">
        <h3 class="font-semibold text-gray-800">คำขอแก้ไขเวลาของฉัน</h3>
        <p class="text-sm text-gray-500 mt-0.5">คำขอจะมีผลกับข้อมูลลงเวลาเมื่อผู้ตรวจสอบอนุมัติแล้วเท่านั้น</p>
      </header>
      <div v-if="corrections.length === 0" class="px-5 py-8 text-center text-gray-500 text-sm">
        ยังไม่มีคำขอแก้ไขเวลา
      </div>
      <ul v-else class="divide-y divide-gray-50">
        <li v-for="correction in corrections" :key="correction.id" class="px-5 py-4 flex flex-wrap items-start gap-3">
          <div class="min-w-0 flex-1">
            <p class="font-medium text-gray-900 text-sm">
              {{ formatDate(correction.attendance?.date) }} —
              <span class="text-gray-600">{{ describeChanges(correction.proposed_values) }}</span>
            </p>
            <p class="text-xs text-gray-500 mt-0.5">เหตุผล: {{ correction.reason }}</p>
          </div>
          <span class="px-2.5 py-1 rounded-full text-xs font-medium" :class="correctionStatusClass(correction.status)">
            {{ correctionStatusText(correction.status) }}
          </span>
        </li>
      </ul>
    </section>

    <!-- ฟอร์มขอแก้ไขเวลา -->
    <div v-if="correctionTarget" class="fixed inset-0 z-50 grid place-items-center bg-black/50 backdrop-blur-sm p-4 overflow-y-auto" @click.self="correctionTarget = null">
      <form role="dialog" aria-modal="true" aria-labelledby="correction-title" class="w-full max-w-lg space-y-4 rounded-2xl bg-white p-6 max-h-[90vh] overflow-y-auto my-auto" @submit.prevent="submitCorrection">
        <div>
          <h3 id="correction-title" class="text-xl font-bold text-gray-900">ขอแก้ไขเวลาปฏิบัติงาน</h3>
          <p class="text-sm text-gray-500">{{ formatDate(correctionTarget.date) }}</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label class="block text-sm font-medium text-gray-700">
            เวลาเข้างานที่ถูกต้อง
            <input v-model="correctionForm.check_in_time" type="datetime-local" class="mt-1 w-full rounded-lg border-gray-300 p-2 focus:ring-[#800000] focus:border-[#800000]">
          </label>
          <label class="block text-sm font-medium text-gray-700">
            เวลาออกงานที่ถูกต้อง
            <input v-model="correctionForm.check_out_time" type="datetime-local" class="mt-1 w-full rounded-lg border-gray-300 p-2 focus:ring-[#800000] focus:border-[#800000]">
          </label>
        </div>

        <label class="block text-sm font-medium text-gray-700">
          เหตุผล
          <textarea v-model="correctionForm.reason" required maxlength="2000" rows="3" class="mt-1 w-full rounded-lg border-gray-300 p-2 focus:ring-[#800000] focus:border-[#800000]" placeholder="เช่น ลืมสแกนออก เพราะไปราชการต่อ"></textarea>
        </label>

        <p class="text-xs text-gray-500">เว้นช่องเวลาที่ไม่ต้องการแก้ไขไว้ว่าง — ระบบจะขอแก้เฉพาะช่องที่กรอก</p>

        <div class="flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700" @click="correctionTarget = null">ยกเลิก</button>
          <button type="submit" :disabled="submitting" class="rounded-lg bg-[#800000] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
            {{ submitting ? 'กำลังส่ง…' : 'ส่งคำขอ' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'

const alertStore = useAlertStore()

const months = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม']
const currentYear = new Date().getFullYear()

const years = Array.from({length: 5}, (_, i) => currentYear - i)

const selectedMonth = ref(new Date().getMonth() + 1)
const selectedYear = ref(currentYear)
const records = ref([])
const loading = ref(false)

const fetchHistory = async () => {
  loading.value = true
  try {
    const res = await api.get('/attendances/me', {
      params: {
        month: selectedMonth.value,
        year: selectedYear.value
      }
    })
    records.value = res.data
  } catch (error) {
    console.error(error)
  } finally {
    loading.value = false
  }
}

const formatDate = (dateStr) => {
  if (!dateStr) return '-'
  return ThaiDateFormatter.toThaiShortText(dateStr)
}

const formatTime = (datetimeStr) => {
  if (!datetimeStr) return '-'
  const d = new Date(datetimeStr)
  return d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }) + ' น.'
}

const statusClass = (status) => {
  const map = {
    'Present': 'bg-green-100 text-green-700',
    'Late': 'bg-yellow-100 text-yellow-800',
    'Absent': 'bg-red-100 text-red-700',
    'Leave': 'bg-blue-100 text-blue-700'
  }
  return map[status] || 'bg-gray-100 text-gray-700'
}

const statusText = (status) => {
  const map = {
    'Present': 'มาปกติ',
    'Late': 'มาสาย',
    'Absent': 'ขาดงาน',
    'Leave': 'ลา'
  }
  return map[status] || status
}

/*
 * คำขอแก้ไขเวลา (CLEANUP-02)
 *
 * backend มีสายอนุมัติ + audit + การแจ้งเตือนครบมาตั้งแต่ LEGACY-08
 * แต่ไม่มีหน้าจอใดเรียก และไม่มี endpoint สำหรับดูรายการ ฟีเจอร์จึงไม่มีทางเข้าเลย
 */
const corrections = ref([])
const correctionTarget = ref(null)
const correctionForm = ref({ check_in_time: '', check_out_time: '', reason: '' })
const submitting = ref(false)

const CORRECTION_FIELD_LABELS = {
  check_in_time: 'เวลาเข้างาน',
  check_out_time: 'เวลาออกงาน',
  status: 'สถานะ',
  notes: 'หมายเหตุ',
}

const CORRECTION_STATUS = {
  pending: { text: 'รอพิจารณา', class: 'bg-yellow-100 text-yellow-800' },
  approved: { text: 'อนุมัติแล้ว', class: 'bg-green-100 text-green-700' },
  rejected: { text: 'ไม่อนุมัติ', class: 'bg-red-100 text-red-700' },
}

const correctionStatusText = (status) => CORRECTION_STATUS[status]?.text || status
const correctionStatusClass = (status) => CORRECTION_STATUS[status]?.class || 'bg-gray-100 text-gray-700'

const describeChanges = (changes) =>
  Object.entries(changes || {})
    .map(([key, value]) => `${CORRECTION_FIELD_LABELS[key] || key} → ${value ? formatTime(value) : 'ว่าง'}`)
    .join(' · ') || 'ไม่ระบุ'

const fetchCorrections = async () => {
  try {
    const { data } = await api.get('/attendance-corrections')
    corrections.value = data.data || data
  } catch (error) {
    console.error(error)
  }
}

// input[type=datetime-local] ต้องการรูปแบบ YYYY-MM-DDTHH:mm ตามเวลาเครื่องผู้ใช้
const toLocalInput = (value) => {
  if (!value) return ''
  const date = new Date(value)
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

const openCorrection = (record) => {
  correctionTarget.value = record
  correctionForm.value = {
    check_in_time: toLocalInput(record.check_in_time),
    check_out_time: toLocalInput(record.check_out_time),
    reason: '',
  }
}

const submitCorrection = async () => {
  const changes = {}
  if (correctionForm.value.check_in_time) changes.check_in_time = correctionForm.value.check_in_time
  if (correctionForm.value.check_out_time) changes.check_out_time = correctionForm.value.check_out_time

  if (Object.keys(changes).length === 0) {
    alertStore.error('กรุณาระบุเวลาที่ต้องการแก้ไขอย่างน้อยหนึ่งช่อง')
    return
  }

  submitting.value = true
  try {
    const { data } = await api.post(`/attendances/${correctionTarget.value.uuid}/corrections`, {
      changes,
      reason: correctionForm.value.reason,
    })
    alertStore.success(data.message || 'ส่งคำขอแก้ไขเวลาแล้ว')
    correctionTarget.value = null
    await fetchCorrections()
  } catch (error) {
    alertStore.error(
      Object.values(error.response?.data?.errors || {})[0]?.[0]
        || error.response?.data?.message
        || 'ส่งคำขอไม่สำเร็จ',
    )
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  fetchHistory()
  fetchCorrections()
})
</script>
