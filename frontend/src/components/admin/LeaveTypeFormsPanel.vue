<template>
  <section class="space-y-4">
    <div class="p-4 rounded-xl bg-blue-50 border border-blue-200 text-blue-900 text-sm space-y-1">
      <p class="font-semibold">ออกแบบแบบฟอร์มใบลาแยกตามประเภทการลา</p>
      <ol class="list-decimal ml-5 space-y-0.5">
        <li>กด <strong>สร้างแบบฟอร์ม</strong> — ระบบสร้างแบบฟอร์มเปล่าและวางช่องข้อมูลให้ครบทุกช่อง (ใช้เวลาประมาณครึ่งนาที)</li>
        <li>กด <strong>ออกแบบ</strong> — ลากปรับตำแหน่ง เพิ่ม/ลบช่อง หรืออัปโหลดแบบฟอร์ม PDF ของหน่วยงานแทน แล้วกดเผยแพร่</li>
        <li>เปิดสวิตช์ <strong>ใช้แบบฟอร์มนี้</strong> — ใบลาที่ยื่นต่อจากนี้จะพิมพ์ด้วยแบบฟอร์มที่ออกแบบ</li>
      </ol>
      <p class="text-blue-800">ใบลาที่ยื่นไปก่อนหน้ายังพิมพ์ด้วยแบบฟอร์มเดิมที่ผูกไว้ตอนยื่น · ปิดสวิตช์เมื่อใดก็กลับไปใช้แบบฟอร์มมาตรฐานทันที</p>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <div class="animate-spin h-8 w-8 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <div v-else class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
          <tr>
            <th class="px-4 py-3 text-left">ประเภทการลา</th>
            <th class="px-4 py-3 text-left">แบบฟอร์ม</th>
            <th class="px-4 py-3 text-left">สถานะ</th>
            <th class="px-4 py-3 text-center">ใช้แบบฟอร์มนี้</th>
            <th class="px-4 py-3 text-right">จัดการ</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="row in rows" :key="row.target" class="align-middle">
            <td class="px-4 py-3 font-semibold text-gray-900">{{ row.name }}</td>
            <td class="px-4 py-3 text-gray-700">
              <span v-if="row.template">{{ row.template.name }}</span>
              <span v-else-if="row.can_generate" class="text-gray-400">ยังไม่ได้สร้าง</span>
              <span v-else class="text-amber-700">ยังไม่มีแบบฟอร์มในระบบสำหรับประเภทนี้</span>
            </td>
            <td class="px-4 py-3">
              <span v-if="row.active_version" class="inline-block px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-xs font-semibold">
                เผยแพร่แล้ว (เวอร์ชัน {{ row.active_version.version }})
              </span>
              <span v-if="row.draft_version" class="inline-block ml-1 px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 text-xs font-semibold">
                ฉบับร่าง (เวอร์ชัน {{ row.draft_version.version }})
              </span>
              <span v-if="!row.active_version && !row.draft_version" class="text-xs text-gray-400">—</span>
            </td>
            <td class="px-4 py-3 text-center">
              <button
                type="button"
                role="switch"
                :aria-checked="row.enabled"
                :aria-label="`ใช้แบบฟอร์มที่ออกแบบกับ ${row.name}`"
                :disabled="!row.active_version || busy === row.target"
                @click="toggle(row)"
                class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors disabled:opacity-40"
                :class="row.enabled ? 'bg-[#800000]' : 'bg-gray-300'"
                :title="row.active_version ? '' : 'ต้องเผยแพร่แบบฟอร์มก่อน'"
              >
                <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform" :class="row.enabled ? 'translate-x-5' : 'translate-x-0.5'"></span>
              </button>
            </td>
            <td class="px-4 py-3">
              <div class="flex flex-wrap justify-end gap-2">
                <button
                  v-if="row.can_generate"
                  @click="generate(row)"
                  :disabled="busy !== null"
                  class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 disabled:opacity-50 min-h-[36px]"
                >
                  {{ busy === row.target ? 'กำลังสร้าง…' : (row.template ? 'สร้างฉบับร่างใหม่' : 'สร้างแบบฟอร์ม') }}
                </button>
                <router-link
                  v-if="row.template"
                  :to="{ name: 'document-designer', params: { templateId: row.template.id } }"
                  class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-[#800000] text-white hover:bg-[#660000] min-h-[36px] inline-flex items-center"
                >
                  ออกแบบ
                </router-link>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { getErrorMessage } from '../../utils/errorHandler'

const router = useRouter()
const alertStore = useAlertStore()

const rows = ref([])
const loading = ref(true)
const busy = ref(null)

const load = async () => {
  loading.value = true
  try {
    const res = await api.get('/admin/document-engine/leave-type-forms')
    rows.value = res.data
  } catch (error) {
    alertStore.error(getErrorMessage(error, 'โหลดแบบฟอร์มใบลาไม่สำเร็จ'))
  } finally {
    loading.value = false
  }
}

const generate = async (row) => {
  if (row.template && !confirm(`สร้างฉบับร่างใหม่ของ "${row.name}" จากแบบฟอร์มในระบบ? (เวอร์ชันที่เผยแพร่อยู่ไม่ถูกแตะ)`)) return
  busy.value = row.target
  try {
    const res = await api.post(`/admin/document-engine/leave-type-forms/${row.target}/generate`, null, { timeout: 180000 })
    alertStore.success(res.data.message)
    router.push({ name: 'document-designer', params: { templateId: res.data.template_id } })
  } catch (error) {
    alertStore.error(getErrorMessage(error, 'สร้างแบบฟอร์มไม่สำเร็จ'))
  } finally {
    busy.value = null
  }
}

const toggle = async (row) => {
  busy.value = row.target
  try {
    const res = await api.put(`/admin/document-engine/leave-type-forms/${row.target}`, { enabled: !row.enabled })
    alertStore.success(res.data.message)
    await load()
  } catch (error) {
    alertStore.error(getErrorMessage(error, 'เปลี่ยนการใช้งานแบบฟอร์มไม่สำเร็จ'))
  } finally {
    busy.value = null
  }
}

onMounted(load)
</script>
