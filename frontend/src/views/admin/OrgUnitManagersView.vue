<template>
  <div class="max-w-7xl mx-auto space-y-6">
    <header class="flex flex-col sm:flex-row sm:items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">ผู้รับผิดชอบหน่วยงาน</h1>
        <p class="text-sm text-gray-500 mt-1">
          กำหนดว่าใครเป็นหัวหน้าหน่วยงานไหนตั้งแต่เมื่อไร — ระบบใช้ข้อมูลนี้หาผู้อนุมัติใบลาของผู้ยื่นแต่ละคน
        </p>
      </div>
      <button
        class="rounded-xl bg-[#800000] px-5 py-2.5 text-white font-semibold text-sm shadow-sm hover:bg-[#600000] transition-colors min-h-[44px]"
        @click="openForm()"
      >
        มอบหมายผู้รับผิดชอบ
      </button>
    </header>

    <!--
      หน่วยงานที่ยังไม่มีหัวหน้าคือสาเหตุที่ใบลาของคนในหน่วยนั้นจะค้าง
      แสดงไว้บนสุดเพราะเป็นสิ่งที่ต้องลงมือก่อนอย่างอื่น
    -->
    <section v-if="unitsWithoutManager.length" class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
      <h2 class="font-bold text-amber-900 mb-2">หน่วยงานที่ยังไม่มีผู้รับผิดชอบ ({{ unitsWithoutManager.length }})</h2>
      <p class="text-sm text-amber-900 mb-3">
        ขั้นอนุมัติแบบ “หัวหน้าหน่วยงานของผู้ยื่น” จะหาตัวผู้อนุมัติไม่พบสำหรับคนในหน่วยงานเหล่านี้
      </p>
      <ul class="flex flex-wrap gap-2">
        <li v-for="unit in unitsWithoutManager" :key="unit.id">
          <button
            class="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100"
            @click="openForm(unit.id)"
          >
            {{ unit.name }}
            <span class="font-normal text-amber-700">
              {{ unit.parent_id === null ? '· ทั้งองค์กร (ใช้กับขั้น “ผู้อำนวยการ”)' : `· ${unit.members} คน` }}
            </span>
          </button>
        </li>
      </ul>
    </section>

    <section class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
      <div v-if="loading" class="p-10 text-center text-gray-500">กำลังโหลด...</div>
      <div v-else-if="!rows.length" class="p-10 text-center text-gray-500">ยังไม่มีการมอบหมายผู้รับผิดชอบหน่วยงาน</div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-xs uppercase text-gray-500">
            <tr>
              <th class="p-3 text-left">หน่วยงาน</th>
              <th class="p-3 text-left">ผู้รับผิดชอบ</th>
              <th class="p-3 text-left">ฐานะ</th>
              <th class="p-3 text-left">ช่วงที่มีผล</th>
              <th class="p-3 text-right">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id" class="border-t border-gray-50" :class="isCurrent(row) ? '' : 'text-gray-400'">
              <td class="p-3 font-medium">{{ row.org_unit_name }}</td>
              <td class="p-3">{{ row.user_name }}</td>
              <td class="p-3">{{ typeLabel(row.assignment_type) }}</td>
              <td class="p-3">
                {{ thaiDate(row.effective_from) }} – {{ row.effective_to ? thaiDate(row.effective_to) : 'ปัจจุบัน' }}
              </td>
              <td class="p-3 text-right">
                <button v-if="isCurrent(row)" class="text-sm font-semibold text-[#800000]" @click="endAssignment(row)">
                  สิ้นสุดการมอบหมาย
                </button>
                <span v-else class="text-xs">สิ้นสุดแล้ว</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <div v-if="showForm" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @click.self="showForm = false">
      <form class="w-full max-w-lg space-y-4 rounded-2xl bg-white p-6 shadow-xl" @submit.prevent="save">
        <h2 class="text-xl font-bold text-gray-900">มอบหมายผู้รับผิดชอบหน่วยงาน</h2>

        <label class="block text-sm">หน่วยงาน
          <select v-model="form.org_unit_id" required class="mt-1 w-full rounded-lg border px-3 py-2">
            <option :value="null" disabled>-- เลือกหน่วยงาน --</option>
            <option v-for="unit in units" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
          </select>
        </label>

        <label class="block text-sm">ผู้รับผิดชอบ
          <select v-model="form.user_id" required class="mt-1 w-full rounded-lg border px-3 py-2">
            <option :value="null" disabled>-- เลือกผู้ใช้ --</option>
            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.full_name_with_title || user.name }}</option>
          </select>
        </label>

        <div class="grid grid-cols-2 gap-3">
          <label class="block text-sm">ฐานะ
            <select v-model="form.assignment_type" class="mt-1 w-full rounded-lg border px-3 py-2">
              <option value="MANAGER">หัวหน้าหน่วยงาน</option>
              <option value="ACTING">ผู้รักษาการแทน</option>
              <option value="DELEGATE">ผู้รับมอบอำนาจ</option>
            </select>
          </label>
          <label class="block text-sm">ขอบเขต
            <select v-model="form.scope" class="mt-1 w-full rounded-lg border px-3 py-2">
              <option value="UNIT">เฉพาะหน่วยงานนี้</option>
              <option value="DESCENDANTS">รวมหน่วยงานลูก</option>
            </select>
          </label>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <label class="block text-sm">เริ่มมีผล
            <input v-model="form.effective_from" type="date" required class="mt-1 w-full rounded-lg border px-3 py-2" />
          </label>
          <label class="block text-sm">สิ้นสุด (ถ้ามี)
            <input v-model="form.effective_to" type="date" class="mt-1 w-full rounded-lg border px-3 py-2" />
          </label>
        </div>

        <label class="block text-sm">หมายเหตุ
          <input v-model="form.note" maxlength="255" class="mt-1 w-full rounded-lg border px-3 py-2" placeholder="เช่น เลขที่คำสั่ง" />
        </label>

        <div class="flex justify-end gap-3 pt-2">
          <button type="button" class="rounded-lg border px-4 py-2" @click="showForm = false">ยกเลิก</button>
          <button :disabled="saving" class="rounded-lg bg-[#800000] px-4 py-2 text-white disabled:opacity-50">
            {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
/*
 * ผู้รับผิดชอบหน่วยงาน
 *
 * `org_unit_managers` เป็นข้อมูลที่ตัวหาผู้อนุมัติใช้ตัดสินว่า "หัวหน้าหน่วยงานของ
 * ผู้ยื่นคือใคร" แต่เดิมไม่มีหน้าจอหรือ API ให้กรอกเลย ตารางจึงว่างเปล่าทุกการติดตั้ง
 * และขั้นอนุมัติแบบอิงผังองค์กรตกไปใช้ทางถอยตามชื่อบทบาททุกครั้ง
 */
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { getErrorMessage } from '../../utils/errorHandler'
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'

const alerts = useAlertStore()
const loading = ref(true)
const saving = ref(false)
const showForm = ref(false)
const rows = ref([])
const units = ref([])
const users = ref([])

const form = reactive({
  org_unit_id: null,
  user_id: null,
  assignment_type: 'MANAGER',
  scope: 'UNIT',
  effective_from: new Date().toISOString().slice(0, 10),
  effective_to: '',
  note: '',
})

/*
 * หน่วยงานที่การไม่มีผู้รับผิดชอบทำให้ใบลาค้างจริง
 *
 * หน่วยงานที่ยังไม่มีคนสังกัดเลยไม่ต้องเตือน — ยกเว้นยอดผัง ซึ่งขั้น "ผู้อำนวยการ"
 * อ่านผู้รับผิดชอบของมันโดยตรงไม่ว่าจะมีใครสังกัดอยู่ตรงนั้นหรือไม่
 */
const unitsWithoutManager = computed(
  () => units.value.filter(unit => !unit.has_manager && (unit.members > 0 || unit.parent_id === null)),
)

const typeLabel = value => ({
  MANAGER: 'หัวหน้าหน่วยงาน',
  ACTING: 'ผู้รักษาการแทน',
  DELEGATE: 'ผู้รับมอบอำนาจ',
}[value] || value)

const thaiDate = value => (value ? ThaiDateFormatter.toThaiShortText(String(value).slice(0, 10)) : '-')

// แถวที่ "มีผลอยู่ตอนนี้" — เริ่มแล้วและยังไม่สิ้นสุด
const isCurrent = (row) => {
  const today = new Date().toISOString().slice(0, 10)
  const from = String(row.effective_from).slice(0, 10)
  const to = row.effective_to ? String(row.effective_to).slice(0, 10) : null

  return from <= today && (to === null || to >= today)
}

const load = async () => {
  loading.value = true
  try {
    const [assignments, unitList, userList] = await Promise.all([
      api.get('/admin/org-unit-managers'),
      api.get('/admin/org-unit-managers/units'),
      api.get('/admin/users'),
    ])
    rows.value = assignments.data
    units.value = unitList.data
    users.value = userList.data.data || userList.data
  } catch (error) {
    alerts.error(getErrorMessage(error, 'โหลดข้อมูลผู้รับผิดชอบหน่วยงานไม่สำเร็จ'))
  } finally {
    loading.value = false
  }
}

const openForm = (orgUnitId = null) => {
  Object.assign(form, {
    org_unit_id: orgUnitId,
    user_id: null,
    assignment_type: 'MANAGER',
    scope: 'UNIT',
    effective_from: new Date().toISOString().slice(0, 10),
    effective_to: '',
    note: '',
  })
  showForm.value = true
}

const save = async () => {
  saving.value = true
  try {
    await api.post('/admin/org-unit-managers', { ...form, effective_to: form.effective_to || null })
    showForm.value = false
    alerts.success('บันทึกผู้รับผิดชอบหน่วยงานเรียบร้อยแล้ว')
    await load()
  } catch (error) {
    alerts.error(getErrorMessage(error, 'บันทึกไม่สำเร็จ'))
  } finally {
    saving.value = false
  }
}

/*
 * สิ้นสุดการมอบหมาย ไม่ใช่การลบ — ประวัติว่าใครรับผิดชอบช่วงใดเป็นหลักฐานว่า
 * ใบลาที่อนุมัติไปแล้วเดินผ่านผู้มีอำนาจจริง
 */
const endAssignment = async (row) => {
  const today = new Date().toISOString().slice(0, 10)
  if (!confirm(`สิ้นสุดการมอบหมายของ ${row.user_name} ที่ ${row.org_unit_name} ณ วันที่ ${thaiDate(today)} หรือไม่`)) return

  try {
    await api.put(`/admin/org-unit-managers/${row.id}/end`, { effective_to: today })
    alerts.success('ปิดการมอบหมายเรียบร้อยแล้ว')
    await load()
  } catch (error) {
    alerts.error(getErrorMessage(error, 'ปิดการมอบหมายไม่สำเร็จ'))
  }
}

onMounted(load)
</script>
