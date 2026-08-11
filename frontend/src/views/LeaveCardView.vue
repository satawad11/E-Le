<template>
  <main class="mx-auto max-w-6xl space-y-5 p-4 sm:p-6" aria-labelledby="card-title">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 id="card-title" class="text-2xl font-bold text-gray-900">บัตรลา</h1>
        <p class="text-sm text-gray-500">สรุปสถิติการลารายบุคคลประจำปีงบประมาณ สำหรับแนบประกอบการเลื่อนเงินเดือน</p>
      </div>
      <button type="button" class="rounded-xl bg-[#800000] px-4 py-2 text-white text-sm font-semibold" @click="exportPdf">
        พิมพ์บัตรลา (PDF)
      </button>
    </header>

    <form class="flex flex-wrap items-end gap-3 rounded-2xl border bg-white p-4" @submit.prevent="load">
      <label v-if="canViewOthers" class="text-sm">บุคลากร
        <select v-model="filters.user_id" class="mt-1 block min-w-56 rounded-lg border p-2">
          <option value="">ตนเอง</option>
          <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
        </select>
      </label>
      <label class="text-sm">ปีงบประมาณ (พ.ศ.)
        <input v-model.number="thaiYear" type="number" min="2500" max="2700" class="mt-1 block rounded-lg border p-2">
      </label>
      <button class="rounded-lg bg-[#800000] px-4 py-2 text-white text-sm">แสดงบัตรลา</button>
    </form>

    <div v-if="loading" role="status" class="rounded-2xl border bg-white p-10 text-center text-gray-500">กำลังประมวลผล...</div>

    <template v-else-if="card">
      <section class="rounded-2xl border bg-white p-5">
        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
          <div><dt class="text-gray-500">ชื่อ-สกุล</dt><dd class="font-semibold">{{ card.user.name }}</dd></div>
          <div><dt class="text-gray-500">ตำแหน่ง</dt><dd class="font-semibold">{{ card.user.position }}</dd></div>
          <div><dt class="text-gray-500">สังกัด</dt><dd class="font-semibold">{{ card.user.department }}</dd></div>
          <div><dt class="text-gray-500">ประเภทบุคลากร</dt><dd class="font-semibold">{{ card.user.personnel_type }}</dd></div>
          <div><dt class="text-gray-500">รหัสบุคลากร</dt><dd class="font-semibold">{{ card.user.personnel_code }}</dd></div>
          <div><dt class="text-gray-500">ปีงบประมาณ</dt><dd class="font-semibold">พ.ศ. {{ card.fiscal_year_th }}</dd></div>
        </dl>
      </section>

      <section class="overflow-hidden rounded-2xl border bg-white">
        <h2 class="border-b bg-gray-50 px-5 py-3 text-sm font-bold text-gray-700">สรุปตามประเภทการลา</h2>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
              <tr>
                <th class="p-3 text-left">ประเภทการลา</th>
                <th class="p-3 text-right">ยกมา</th>
                <th class="p-3 text-right">สิทธิ์รวม</th>
                <th class="p-3 text-right">ครั้ง</th>
                <th class="p-3 text-right">ลาไป</th>
                <th class="p-3 text-right">รับเงินเดือน</th>
                <th class="p-3 text-right">ไม่รับเงินเดือน</th>
                <th class="p-3 text-right">คงเหลือ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in card.summary" :key="row.leave_type" class="border-t">
                <td class="p-3">{{ row.leave_type }}</td>
                <td class="p-3 text-right">{{ row.carried_forward || '—' }}</td>
                <td class="p-3 text-right">{{ row.quota === null ? 'ไม่จำกัด' : row.quota }}</td>
                <td class="p-3 text-right">{{ row.times || '—' }}</td>
                <td class="p-3 text-right">{{ row.used_days || '—' }}</td>
                <td class="p-3 text-right">
                  {{ row.paid_days || '—' }}<span v-if="row.paid_quota !== null" class="text-gray-400"> / {{ row.paid_quota }}</span>
                </td>
                <td class="p-3 text-right" :class="row.unpaid_days ? 'font-semibold text-amber-700' : ''">{{ row.unpaid_days || '—' }}</td>
                <td class="p-3 text-right">{{ row.remaining === null ? 'ไม่จำกัด' : row.remaining }}</td>
              </tr>
              <tr class="border-t bg-gray-50 font-bold">
                <td class="p-3" colspan="3">รวมทั้งสิ้น</td>
                <td class="p-3 text-right">{{ card.totals.times }}</td>
                <td class="p-3 text-right">{{ card.totals.used_days }}</td>
                <td class="p-3 text-right">{{ card.totals.paid_days }}</td>
                <td class="p-3 text-right">{{ card.totals.unpaid_days }}</td>
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="overflow-hidden rounded-2xl border bg-white">
        <h2 class="border-b bg-gray-50 px-5 py-3 text-sm font-bold text-gray-700">รายการใบลา</h2>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
              <tr>
                <th class="p-3 text-left">เลขที่ใบลา</th>
                <th class="p-3 text-left">ประเภทการลา</th>
                <th class="p-3 text-left">ช่วงวันที่ลา</th>
                <th class="p-3 text-right">จำนวนวัน</th>
                <th class="p-3 text-right">ไม่รับเงินเดือน</th>
                <th class="p-3 text-left">สถานะ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(e, i) in card.entries" :key="i" class="border-t">
                <td class="p-3">{{ e.document_number || '—' }}</td>
                <td class="p-3">{{ e.leave_type }}</td>
                <td class="p-3">{{ thaiDate(e.start_date) }} – {{ thaiDate(e.end_date) }}</td>
                <td class="p-3 text-right">{{ e.total_days }}</td>
                <td class="p-3 text-right">{{ e.unpaid_days || '—' }}</td>
                <td class="p-3">{{ statusLabels[e.status] || e.status }}</td>
              </tr>
              <tr v-if="!card.entries.length"><td colspan="6" class="p-10 text-center text-gray-500">ไม่มีการลาในปีงบประมาณนี้</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </main>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../axios'
import { useAlertStore } from '../stores/alert'
import { useAuthStore } from '../stores/auth'

const alerts = useAlertStore()
const auth = useAuthStore()
const loading = ref(false)
const card = ref(null)
const users = ref([])
const statusLabels = { Approved: 'อนุมัติแล้ว', Pending: 'รออนุมัติ' }

// ปีงบประมาณ พ.ศ. = ค.ศ. + 543 โดยเดือน ต.ค. เป็นต้นไปนับเป็นปีถัดไป
const currentFiscalYear = () => {
  const now = new Date()
  return now.getMonth() >= 9 ? now.getFullYear() + 1 : now.getFullYear()
}

const thaiYear = ref(currentFiscalYear() + 543)
const filters = reactive({ user_id: '' })
const canViewOthers = computed(() => auth.hasAnyPermission(['leave.report.view', 'leave.management.manage']))

const thaiDate = v => v ? new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium' }).format(new Date(v)) : '—'

const params = () => {
  const p = { year: thaiYear.value - 543 }
  if (filters.user_id) p.user_id = filters.user_id
  return p
}

const load = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/reports/leave-card', { params: params() })
    card.value = data
  } catch (e) {
    alerts.error(e.response?.data?.message || 'โหลดบัตรลาไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const exportPdf = async () => {
  try {
    const res = await api.get('/reports/leave-card.pdf', { params: params(), responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = `leave-card-${thaiYear.value}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    alerts.error('พิมพ์บัตรลาไม่สำเร็จ')
  }
}

onMounted(async () => {
  if (canViewOthers.value) {
    try { const { data } = await api.get('/users/list'); users.value = data.data || data } catch { /* ไม่มีสิทธิ์ก็ข้ามไป */ }
  }
  await load()
})
</script>
