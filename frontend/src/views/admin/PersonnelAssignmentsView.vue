<template>
  <div class="max-w-7xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">กำหนดประเภทบุคลากร</h1>
        <p class="text-sm text-gray-500 mt-1">ประเภทบุคลากรเป็นตัวกำหนดสิทธิ์และหลักเกณฑ์วันลาของแต่ละคน</p>
      </div>
      <button
        type="button"
        class="px-4 py-2.5 rounded-xl bg-[#800000] hover:bg-[#600000] text-white text-sm font-semibold shadow-sm"
        @click="openTypeForm()"
      >
        + เพิ่มประเภทบุคลากร
      </button>
    </div>

    <!--
      รายการประเภทบุคลากร (CLEANUP-02)
      endpoint สร้าง/แก้ไขมีมาตั้งแต่ต้น แต่ไม่มีหน้าจอ ผู้ดูแลจึงเพิ่มประเภทเองไม่ได้เลย
      ทั้งที่ `AGENTS.md` ห้าม hard-code ประเภทบุคลากร
    -->
    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
      <header class="px-5 py-4 border-b border-gray-100">
        <h2 class="font-semibold text-gray-800">ประเภทบุคลากรในระบบ</h2>
        <p class="text-sm text-gray-500 mt-0.5">ปิดใช้งานประเภทที่เลิกใช้แทนการลบ เพื่อไม่ให้ประวัติของคนเดิมเสียการอ้างอิง</p>
      </header>
      <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[560px]">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left px-5 py-3 font-semibold">ชื่อประเภท</th>
              <th class="text-left px-5 py-3 font-semibold">รหัส</th>
              <th class="text-left px-5 py-3 font-semibold">คำอธิบาย</th>
              <th class="text-center px-5 py-3 font-semibold">สถานะ</th>
              <th class="text-right px-5 py-3 font-semibold">จัดการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="type in types" :key="type.id" class="hover:bg-gray-50/60">
              <td class="px-5 py-3 font-medium text-gray-900">{{ type.name }}</td>
              <td class="px-5 py-3 font-mono text-xs text-gray-500">{{ type.code }}</td>
              <td class="px-5 py-3 text-gray-600">{{ type.description || '—' }}</td>
              <td class="px-5 py-3 text-center">
                <span
                  class="rounded-full px-2 py-1 text-xs font-medium"
                  :class="type.is_active === false ? 'text-gray-600 bg-gray-100' : 'text-green-700 bg-green-50'"
                >
                  {{ type.is_active === false ? 'ปิดใช้งาน' : 'ใช้งาน' }}
                </span>
              </td>
              <td class="px-5 py-3 text-right">
                <button
                  type="button"
                  class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-100"
                  @click="openTypeForm(type)"
                >
                  แก้ไข
                </button>
              </td>
            </tr>
            <tr v-if="types.length === 0">
              <td colspan="5" class="px-5 py-8 text-center text-gray-500">ยังไม่มีประเภทบุคลากรในระบบ</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- การกำหนดประเภทให้บุคลากรรายคน -->
    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
      <header class="px-5 py-4 border-b border-gray-100">
        <h2 class="font-semibold text-gray-800">กำหนดประเภทให้บุคลากร</h2>
      </header>
      <div class="p-4 border-b border-gray-100">
        <input
          v-model="search"
          placeholder="ค้นหาชื่อหรือหน่วยงาน"
          aria-label="ค้นหาบุคลากร"
          class="w-full max-w-md border border-gray-300 rounded-xl px-4 py-2 text-sm focus:ring-[#800000] focus:border-[#800000]"
        >
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[560px]">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left px-5 py-3 font-semibold">บุคลากร</th>
              <th class="text-left px-5 py-3 font-semibold">หน่วยงาน</th>
              <th class="text-left px-5 py-3 font-semibold">ประเภทบุคลากร</th>
              <th class="text-center px-5 py-3 font-semibold">สถานะ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="u in filtered" :key="u.id" class="hover:bg-gray-50/60">
              <td class="px-5 py-3 font-medium text-gray-900">{{ u.name }}</td>
              <td class="px-5 py-3 text-gray-600">{{ u.department?.name || '—' }}</td>
              <td class="px-5 py-3">
                <select
                  :value="u.personnel_type_id"
                  :aria-label="`ประเภทบุคลากรของ ${u.name}`"
                  class="border border-gray-300 rounded-lg px-3 py-2 min-w-48 text-sm focus:ring-[#800000] focus:border-[#800000]"
                  @change="assign(u, $event.target.value)"
                >
                  <option value="" disabled>กรุณากำหนด</option>
                  <option v-for="p in types" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
              </td>
              <td class="px-5 py-3 text-center">
                <span
                  class="rounded-full px-2 py-1 text-xs font-medium"
                  :class="u.personnel_type_id ? 'text-green-700 bg-green-50' : 'text-red-700 bg-red-50'"
                >
                  {{ u.personnel_type_id ? 'พร้อมใช้งาน' : 'ยังไม่กำหนด' }}
                </span>
              </td>
            </tr>
            <tr v-if="loading">
              <td colspan="4" class="px-5 py-8 text-center text-gray-500">กำลังโหลดรายชื่อบุคลากร…</td>
            </tr>
            <tr v-else-if="filtered.length === 0">
              <td colspan="4" class="px-5 py-8 text-center text-gray-500">
                {{ search ? 'ไม่พบบุคลากรที่ตรงกับคำค้น' : 'ยังไม่มีบุคลากรในระบบ' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ฟอร์มประเภทบุคลากร -->
    <div v-if="typeForm" class="fixed inset-0 z-50 grid place-items-center bg-black/50 backdrop-blur-sm p-4 overflow-y-auto" @click.self="typeForm = null">
      <form role="dialog" aria-modal="true" aria-labelledby="type-form-title" class="w-full max-w-md space-y-4 rounded-2xl bg-white p-6 max-h-[90vh] overflow-y-auto my-auto" @submit.prevent="saveType">
        <h2 id="type-form-title" class="text-xl font-bold text-gray-900">
          {{ typeForm.id ? 'แก้ไขประเภทบุคลากร' : 'เพิ่มประเภทบุคลากร' }}
        </h2>

        <label class="block text-sm font-medium text-gray-700">
          ชื่อประเภท
          <input v-model="typeForm.name" required maxlength="255" type="text" class="mt-1 w-full rounded-lg border-gray-300 p-2 focus:ring-[#800000] focus:border-[#800000]">
        </label>

        <label class="block text-sm font-medium text-gray-700">
          รหัส
          <input v-model="typeForm.code" required maxlength="50" type="text" class="mt-1 w-full rounded-lg border-gray-300 p-2 font-mono focus:ring-[#800000] focus:border-[#800000]">
          <span class="text-xs font-normal text-gray-500">ใช้อ้างอิงในนโยบายสิทธิการลา เปลี่ยนแล้วต้องตรวจนโยบายที่ผูกไว้</span>
        </label>

        <label class="block text-sm font-medium text-gray-700">
          คำอธิบาย
          <textarea v-model="typeForm.description" rows="2" class="mt-1 w-full rounded-lg border-gray-300 p-2 focus:ring-[#800000] focus:border-[#800000]"></textarea>
        </label>

        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
          <input v-model="typeForm.is_active" type="checkbox" class="rounded text-[#800000] focus:ring-[#800000]">
          เปิดใช้งาน
        </label>

        <div class="flex justify-end gap-2">
          <button type="button" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700" @click="typeForm = null">ยกเลิก</button>
          <button type="submit" :disabled="savingType" class="rounded-lg bg-[#800000] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
            {{ savingType ? 'กำลังบันทึก…' : 'บันทึก' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { fetchAllPersonnel } from '../../utils/personnelDirectory'
import { getErrorMessage } from '../../utils/errorHandler'

const alerts = useAlertStore()
const users = ref([])
const types = ref([])
const search = ref('')
const typeForm = ref(null)
const savingType = ref(false)
const loading = ref(false)

/*
 * รายชื่อบุคลากรต้องเป็น array และต้องครบทุกคน
 *
 * เดิมเขียนว่า `users.value = userResponse.data` ซึ่งรับ **ทั้งซอง** ของ
 * `/admin/users` (endpoint แบ่งหน้าแล้ว จึงคืน `{ data: [...], last_page: N }`)
 * `users` จึงเป็น object ไม่ใช่ array และ `filtered` ที่เรียก `.filter()` ก็พัง
 * ทุกครั้ง — ตาราง "กำหนดประเภทให้บุคลากร" ของหน้านี้จึงไม่เคยแสดงใครเลย
 *
 * `fetchAllPersonnel()` ไล่ดึงจนครบทุกหน้า เพราะหน้านี้ไม่มีปุ่มเลื่อนหน้า
 * ถ้าดึงหน้าเดียวจะกำหนดประเภทให้คนที่ 26 เป็นต้นไปไม่ได้
 */
const load = async () => {
  loading.value = true
  try {
    const [personnel, typeResponse] = await Promise.all([
      fetchAllPersonnel(),
      api.get('/personnel-types'),
    ])
    users.value = personnel
    types.value = typeResponse.data
  } catch (e) {
    alerts.error(getErrorMessage(e, 'โหลดข้อมูลประเภทบุคลากรไม่สำเร็จ'))
  } finally {
    loading.value = false
  }
}

const filtered = computed(() => {
  const q = search.value.toLowerCase()
  return users.value.filter(
    (u) => !q || u.name.toLowerCase().includes(q) || (u.department?.name || '').toLowerCase().includes(q),
  )
})

const assign = async (user, id) => {
  try {
    await api.put(`/admin/users/${user.id}/personnel-type`, { personnel_type_id: Number(id) })
    user.personnel_type_id = Number(id)
    alerts.success('บันทึกประเภทบุคลากรแล้ว')
  } catch (e) {
    alerts.error(e.response?.data?.message || 'บันทึกไม่สำเร็จ')
    await load()
  }
}

const openTypeForm = (type = null) => {
  typeForm.value = type
    ? { ...type, is_active: type.is_active !== false }
    : { id: null, code: '', name: '', description: '', is_active: true }
}

const saveType = async () => {
  savingType.value = true
  try {
    const payload = {
      code: typeForm.value.code,
      name: typeForm.value.name,
      description: typeForm.value.description || null,
      is_active: typeForm.value.is_active,
    }

    if (typeForm.value.id) {
      await api.put(`/admin/personnel-types/${typeForm.value.id}`, payload)
    } else {
      await api.post('/admin/personnel-types', payload)
    }

    alerts.success('บันทึกประเภทบุคลากรเรียบร้อยแล้ว')
    typeForm.value = null
    await load()
  } catch (e) {
    alerts.error(
      Object.values(e.response?.data?.errors || {})[0]?.[0]
        || e.response?.data?.message
        || 'บันทึกไม่สำเร็จ',
    )
  } finally {
    savingType.value = false
  }
}

onMounted(load)
</script>
