<template>
  <div class="max-w-7xl mx-auto space-y-6 p-4 sm:p-6">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">ปฏิทินวันทำการ</h1>
        <p class="text-sm text-gray-500">วันหยุดราชการประจำปี และวันหยุด/วันทำงานพิเศษ ทั้งส่วนกลางและรายหน่วยงาน · ใช้ตัดวันทำการตอนคำนวณวันลา</p>
      </div>
    </div>

    <section class="mb-4 flex flex-wrap gap-3 rounded-2xl border bg-white p-4">
      <label class="text-sm">ปี พ.ศ.<input v-model.number="buddhistYear" type="number" min="2500" max="2700" class="ml-2 w-28 rounded-lg border px-3 py-2" /></label>
      <label class="text-sm">หน่วยงาน
        <select v-model="filters.department_id" class="ml-2 rounded-lg border px-3 py-2" @change="load">
          <option value="">ทั้งหมด</option><option value="global">ส่วนกลาง</option>
          <option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option>
        </select>
      </label>
      <button class="rounded-lg border px-4 py-2" @click="loadAll">ค้นหา</button>
    </section>

    <section class="overflow-hidden rounded-2xl border bg-white">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b p-4">
        <div>
          <h2 class="text-lg font-bold text-gray-900">วันหยุดราชการประจำปี {{ buddhistYear }}</h2>
          <p class="text-xs text-gray-500">ตามประกาศคณะรัฐมนตรี มีผลกับทุกหน่วยงาน · วันสำคัญที่ตรงเสาร์-อาทิตย์ให้บันทึกวันหยุดชดเชยแทน</p>
        </div>
        <button class="rounded-xl bg-[#800000] px-4 py-2 text-white hover:bg-[#600000] transition-colors min-h-[44px]" @click="openHoliday()">เพิ่มวันหยุดราชการ</button>
      </div>
      <div v-if="holidaysLoading" class="p-8 text-center text-gray-500">กำลังโหลด...</div>
      <div v-else class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-gray-50"><tr><th class="p-3 text-left">วันที่</th><th class="p-3 text-left">รายการ</th><th class="p-3 text-left">สถานะ</th><th class="p-3 text-right">จัดการ</th></tr></thead>
        <tbody><tr v-for="item in holidays" :key="item.id" class="border-t">
          <td class="p-3 whitespace-nowrap">{{ formatDate(item.date) }}</td><td class="p-3 font-medium">{{ item.name }}</td>
          <td class="p-3"><span :class="item.is_active ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-500'" class="rounded-full px-2 py-1 text-xs">{{ item.is_active ? 'วันหยุด' : 'ปิดใช้งาน' }}</span></td>
          <td class="p-3 text-right whitespace-nowrap"><button class="mr-3 text-[#800000] font-semibold" @click="openHoliday(item)">แก้ไข</button><button class="text-red-600" @click="removeHoliday(item)">ลบ</button></td>
        </tr><tr v-if="!holidays.length"><td colspan="4" class="p-8 text-center text-gray-500">ยังไม่มีวันหยุดราชการของปีนี้ — การคำนวณวันลาจะนับเฉพาะเสาร์-อาทิตย์เป็นวันหยุด</td></tr></tbody>
      </table></div>
    </section>

    <section class="overflow-hidden rounded-2xl border bg-white">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b p-4">
        <div>
          <h2 class="text-lg font-bold text-gray-900">วันหยุด/วันทำงานพิเศษ</h2>
          <p class="text-xs text-gray-500">เช่น วันหยุดเพิ่มเติมของหน่วยงาน หรือวันเสาร์ที่ต้องมาปฏิบัติงาน</p>
        </div>
        <button class="rounded-xl border border-[#800000] px-4 py-2 text-[#800000] hover:bg-red-50 transition-colors min-h-[44px]" @click="openForm()">เพิ่มวันพิเศษ</button>
      </div>
      <div v-if="loading" class="p-8 text-center text-gray-500">กำลังโหลด...</div>
      <div v-else class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-gray-50"><tr><th class="p-3 text-left">วันที่</th><th class="p-3 text-left">รายการ</th><th class="p-3 text-left">ขอบเขต</th><th class="p-3 text-left">ประเภท</th><th class="p-3 text-right">จัดการ</th></tr></thead>
        <tbody><tr v-for="item in items" :key="item.id" class="border-t">
          <td class="p-3">{{ formatDate(item.date) }}</td><td class="p-3 font-medium">{{ item.name }}</td>
          <td class="p-3">{{ item.department?.name || 'ส่วนกลาง' }}</td>
          <td class="p-3"><span :class="item.day_type === 'working_day' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'" class="rounded-full px-2 py-1 text-xs">{{ item.day_type === 'working_day' ? 'วันทำงานพิเศษ' : 'วันหยุด' }}</span></td>
          <td class="p-3 text-right"><button class="mr-3 text-[#800000] font-semibold" @click="openForm(item)">แก้ไข</button><button class="text-red-600" @click="remove(item)">ลบ</button></td>
        </tr><tr v-if="!items.length"><td colspan="5" class="p-8 text-center text-gray-500">ไม่พบรายการ</td></tr></tbody>
      </table></div>
    </section>

    <div v-if="editing" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @click.self="editing = false">
      <form class="w-full max-w-lg space-y-4 rounded-2xl bg-white p-6 shadow-xl" @submit.prevent="save">
        <h2 class="text-xl font-bold">{{ form.id ? 'แก้ไขวันพิเศษ' : 'เพิ่มวันพิเศษ' }}</h2>
        <label class="block text-sm">วันที่<input v-model="form.date" required type="date" class="mt-1 w-full rounded-lg border px-3 py-2" /></label>
        <label class="block text-sm">ชื่อรายการ<input v-model="form.name" required maxlength="255" class="mt-1 w-full rounded-lg border px-3 py-2" /></label>
        <label class="block text-sm">หน่วยงาน<select v-model="form.department_id" class="mt-1 w-full rounded-lg border px-3 py-2"><option :value="null">ส่วนกลาง (ทุกหน่วยงาน)</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }}</option></select></label>
        <label class="block text-sm">ประเภท<select v-model="form.day_type" class="mt-1 w-full rounded-lg border px-3 py-2"><option value="holiday">วันหยุด</option><option value="working_day">วันทำงานพิเศษ</option></select></label>
        <div class="flex justify-end gap-3"><button type="button" class="rounded-lg border px-4 py-2" @click="editing = false">ยกเลิก</button><button class="rounded-lg bg-[#800000] px-4 py-2 text-white hover:bg-[#600000] transition-colors">บันทึก</button></div>
      </form>
    </div>

    <div v-if="holidayEditing" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @click.self="holidayEditing = false">
      <form class="w-full max-w-lg space-y-4 rounded-2xl bg-white p-6 shadow-xl" role="dialog" aria-modal="true" @submit.prevent="saveHoliday">
        <h2 class="text-xl font-bold">{{ holidayForm.id ? 'แก้ไขวันหยุดราชการ' : 'เพิ่มวันหยุดราชการ' }}</h2>
        <label class="block text-sm">วันที่<input v-model="holidayForm.date" required type="date" class="mt-1 w-full rounded-lg border px-3 py-2" /></label>
        <label class="block text-sm">ชื่อวันหยุด<input v-model="holidayForm.name" required maxlength="255" placeholder="เช่น วันหยุดชดเชยวันมาฆบูชา" class="mt-1 w-full rounded-lg border px-3 py-2" /></label>
        <label class="flex items-center gap-2 text-sm"><input v-model="holidayForm.is_active" type="checkbox" class="h-4 w-4 rounded border-gray-300 accent-[#800000]" />ใช้งาน (นับเป็นวันหยุด)</label>
        <div class="flex justify-end gap-3"><button type="button" class="rounded-lg border px-4 py-2" @click="holidayEditing = false">ยกเลิก</button><button :disabled="holidaySaving" class="rounded-lg bg-[#800000] px-4 py-2 text-white hover:bg-[#600000] transition-colors disabled:opacity-60">{{ holidaySaving ? 'กำลังบันทึก...' : 'บันทึก' }}</button></div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Swal from 'sweetalert2'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'

const alerts = useAlertStore(), loading = ref(false), editing = ref(false), items = ref([]), departments = ref([])
const filters = reactive({ year: new Date().getFullYear(), department_id: '' })
const buddhistYear = computed({ get: () => filters.year + 543, set: value => { filters.year = Number(value) - 543 } })
const form = reactive({ id: null, date: '', name: '', department_id: null, day_type: 'holiday', is_active: true })
const firstError = error => Object.values(error.response?.data?.errors || {})[0]?.[0] || error.response?.data?.message || 'บันทึกไม่สำเร็จ'
const confirmDelete = async name => (await Swal.fire({ title: 'ยืนยันการลบ', text: `ลบ “${name}” หรือไม่`, icon: 'warning', showCancelButton: true, confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#dc2626' })).isConfirmed

const load = async () => { loading.value = true; try { const params = { year: filters.year }; if (filters.department_id === 'global') params.department_id = null; else if (filters.department_id) params.department_id = filters.department_id; const { data } = await api.get('/admin/work-calendar', { params }); items.value = data.data || data } finally { loading.value = false } }
const loadDepartments = async () => { try { const { data } = await api.get('/departments'); departments.value = data.data || data } catch { departments.value = [] } }
const openForm = (item = null) => { Object.assign(form, item ? { id: item.id, date: String(item.date).slice(0, 10), name: item.name, department_id: item.department_id, day_type: item.day_type, is_active: item.is_active } : { id: null, date: '', name: '', department_id: null, day_type: 'holiday', is_active: true }); editing.value = true }
const save = async () => { try { const payload = { ...form }; delete payload.id; if (form.id) await api.put(`/admin/work-calendar/${form.id}`, payload); else await api.post('/admin/work-calendar', payload); editing.value = false; alerts.success('บันทึกปฏิทินวันทำการแล้ว'); await load() } catch (error) { alerts.error(firstError(error)) } }
const remove = async (item) => { if (!(await confirmDelete(item.name))) return; await api.delete(`/admin/work-calendar/${item.id}`); alerts.success('ลบรายการแล้ว'); await load() }

// วันหยุดราชการทั่วประเทศ (ตาราง holidays)
const holidays = ref([]), holidaysLoading = ref(false), holidayEditing = ref(false), holidaySaving = ref(false)
const holidayForm = reactive({ id: null, date: '', name: '', is_active: true })
const loadHolidays = async () => { holidaysLoading.value = true; try { const { data } = await api.get('/admin/holidays', { params: { year: filters.year } }); holidays.value = data.data || data } catch (error) { alerts.error(error.response?.data?.message || 'โหลดวันหยุดราชการไม่สำเร็จ') } finally { holidaysLoading.value = false } }
const openHoliday = (item = null) => { Object.assign(holidayForm, item ? { id: item.id, date: String(item.date).slice(0, 10), name: item.name, is_active: !!item.is_active } : { id: null, date: '', name: '', is_active: true }); holidayEditing.value = true }
const saveHoliday = async () => { holidaySaving.value = true; try { const payload = { date: holidayForm.date, name: holidayForm.name, is_active: holidayForm.is_active }; if (holidayForm.id) await api.put(`/admin/holidays/${holidayForm.id}`, payload); else await api.post('/admin/holidays', payload); holidayEditing.value = false; alerts.success('บันทึกวันหยุดราชการแล้ว'); await loadHolidays() } catch (error) { alerts.error(firstError(error)) } finally { holidaySaving.value = false } }
const removeHoliday = async (item) => { if (!(await confirmDelete(item.name))) return; try { await api.delete(`/admin/holidays/${item.id}`); alerts.success('ลบวันหยุดราชการแล้ว'); await loadHolidays() } catch (error) { alerts.error(firstError(error)) } }

const loadAll = () => Promise.all([load(), loadHolidays()])
const formatDate = value => ThaiDateFormatter.toThaiShortText(value)
onMounted(async () => { await Promise.all([loadDepartments(), loadAll()]) })
</script>
