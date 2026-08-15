<template>
  <div class="max-w-7xl mx-auto space-y-6" aria-labelledby="screening-title">
    <header>
      <h1 id="screening-title" class="text-2xl font-bold text-gray-900">คัดกรองเกณฑ์การเลื่อนเงินเดือน</h1>
      <p class="text-sm text-gray-500">ตรวจวันลาและการมาสายของบุคลากรทั้งหน่วยงานตามรอบการประเมิน</p>
    </header>

    <form class="flex flex-wrap items-end gap-3 rounded-2xl border bg-white p-4" @submit.prevent="load">
      <label class="text-sm">ปีงบประมาณ (พ.ศ.)
        <input v-model.number="thaiYear" type="number" min="2500" max="2700" class="mt-1 block w-32 rounded-lg border p-2">
      </label>
      <label class="text-sm">รอบการประเมิน
        <select v-model.number="filters.round" class="mt-1 block rounded-lg border p-2">
          <option :value="1">ครั้งที่ 1 (ต.ค. – มี.ค.)</option>
          <option :value="2">ครั้งที่ 2 (เม.ย. – ก.ย.)</option>
        </select>
      </label>
      <label class="text-sm">หน่วยงาน
        <select v-model="filters.department_id" class="mt-1 block min-w-44 rounded-lg border p-2">
          <option value="">ทั้งหมด</option>
          <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
        </select>
      </label>
      <label class="text-sm">ลาเกิน (วัน)
        <input v-model.number="filters.max_leave_days" type="number" min="0" class="mt-1 block w-24 rounded-lg border p-2">
      </label>
      <label class="text-sm">สายเกิน (ครั้ง)
        <input v-model.number="filters.max_late_times" type="number" min="0" class="mt-1 block w-24 rounded-lg border p-2">
      </label>
      <button class="rounded-lg bg-[#800000] px-4 py-2 text-sm text-white">แสดงรายงาน</button>
      <button type="button" class="rounded-lg border px-4 py-2 text-sm" @click="exportCsv">ส่งออก CSV</button>
    </form>

    <div v-if="loading" role="status" class="rounded-2xl border bg-white p-10 text-center text-gray-500">กำลังประมวลผล...</div>

    <template v-else-if="result">
      <section class="grid gap-4 sm:grid-cols-3">
        <article class="rounded-2xl border bg-white p-4">
          <p class="text-sm text-gray-500">บุคลากรทั้งหมด</p>
          <p class="mt-1 text-2xl font-bold">{{ result.totals.all }} คน</p>
        </article>
        <article class="rounded-2xl border bg-white p-4">
          <p class="text-sm text-gray-500">ต้องนำเข้าพิจารณา</p>
          <p class="mt-1 text-2xl font-bold" :class="result.totals.flagged ? 'text-amber-700' : 'text-emerald-700'">
            {{ result.totals.flagged }} คน
          </p>
        </article>
        <article class="rounded-2xl border bg-white p-4">
          <p class="text-sm text-gray-500">รอบการประเมิน</p>
          <p class="mt-1 text-base font-bold">พ.ศ. {{ result.fiscal_year_th }} {{ result.round_label }}</p>
        </article>
      </section>

      <p class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-900">
        การลาที่ไม่นับรวมในเกณฑ์นี้: {{ result.criteria.excluded_leave_types.join(' · ') || 'ไม่มี' }}
        <span class="text-blue-700"> (ปรับได้ที่หน้าจัดการประเภทการลา)</span>
      </p>

      <section class="overflow-hidden rounded-2xl border bg-white">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
              <tr>
                <th class="p-3 text-left">บุคลากร</th>
                <th class="p-3 text-left">สังกัด</th>
                <th class="p-3 text-right">ลา (ครั้ง)</th>
                <th class="p-3 text-right">ลาที่นับได้</th>
                <th class="p-3 text-right">ลาที่ไม่นับ</th>
                <th class="p-3 text-right">มาสาย</th>
                <th class="p-3 text-right">ปฏิบัติราชการ</th>
                <th class="p-3 text-left">ผลการคัดกรอง</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in result.rows" :key="row.user_id" class="border-t" :class="row.passed ? '' : 'bg-amber-50/60'">
                <td class="p-3">
                  <p class="font-medium">{{ row.name }}</p>
                  <p class="text-xs text-gray-500">{{ row.position }} · {{ row.personnel_type }}</p>
                </td>
                <td class="p-3">{{ row.department }}</td>
                <td class="p-3 text-right">{{ row.leave_times || '—' }}</td>
                <td class="p-3 text-right font-semibold">{{ row.leave_days || '—' }}</td>
                <td class="p-3 text-right text-gray-500">{{ row.excluded_days || '—' }}</td>
                <td class="p-3 text-right">{{ row.late_times ? `${row.late_times} ครั้ง` : '—' }}</td>
                <td class="p-3 text-right">{{ row.service_months }} เดือน</td>
                <td class="p-3">
                  <span v-if="row.passed" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">ผ่านเกณฑ์</span>
                  <ul v-else class="space-y-0.5">
                    <li v-for="flag in row.flags" :key="flag" class="text-xs font-medium text-amber-800">• {{ flag }}</li>
                  </ul>
                </td>
              </tr>
              <tr v-if="!result.rows.length">
                <td colspan="8" class="p-10 text-center text-gray-500">ไม่พบบุคลากรตามเงื่อนไข</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <p class="text-xs text-gray-500">
        หมายเหตุ: รายงานนี้ช่วยคัดกรองเบื้องต้นจากข้อมูลวันลาและการลงเวลาเท่านั้น
        การพิจารณาเลื่อนเงินเดือนยังต้องประกอบกับผลการประเมินและข้อมูลทางวินัยตามระเบียบ
      </p>
    </template>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'

const alerts = useAlertStore()
const loading = ref(false)
const result = ref(null)
const departments = ref([])

const currentFiscalYear = () => {
  const now = new Date()
  return now.getMonth() >= 9 ? now.getFullYear() + 1 : now.getFullYear()
}
const currentRound = () => {
  const m = new Date().getMonth() + 1
  return m >= 4 && m <= 9 ? 2 : 1
}

const thaiYear = ref(currentFiscalYear() + 543)
const filters = reactive({ round: currentRound(), department_id: '', max_leave_days: 23, max_late_times: 18 })

const params = () => {
  const p = { year: thaiYear.value - 543, round: filters.round, max_leave_days: filters.max_leave_days, max_late_times: filters.max_late_times }
  if (filters.department_id) p.department_id = filters.department_id
  return p
}

const load = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/reports/promotion-screening', { params: params() })
    result.value = data
  } catch (e) {
    alerts.error(e.response?.data?.message || 'โหลดรายงานไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const exportCsv = async () => {
  try {
    const res = await api.get('/reports/promotion-screening.csv', { params: params(), responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = `promotion-screening-${thaiYear.value}-r${filters.round}.csv`
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    alerts.error('ส่งออกรายงานไม่สำเร็จ')
  }
}

onMounted(async () => {
  try {
    const { data } = await api.get('/departments')
    departments.value = data.data || data
  } catch { /* ไม่มีสิทธิ์ดูหน่วยงานก็ยังใช้รายงานได้ */ }
  await load()
})
</script>
