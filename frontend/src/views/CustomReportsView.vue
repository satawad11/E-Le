<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">รายงานที่ออกแบบเอง</h1>
        <p class="text-gray-500 text-sm mt-1">
          รายงานที่ผู้ดูแลสร้างขึ้นเองจากแหล่งข้อมูลที่ระบบอนุญาต — ดูผลบนหน้าจอ หรือส่งออกเป็น PDF และ Excel
        </p>
      </div>

      <router-link
        v-if="canDesign"
        :to="{ name: 'report-designer' }"
        class="px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm shadow-md min-h-[44px] inline-flex items-center"
      >
        + ออกแบบรายงาน
      </router-link>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <template v-else>
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-x-auto">
        <table class="w-full text-sm min-w-[720px]">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left px-5 py-3">ชื่อรายงาน</th>
              <th class="text-left px-5 py-3">แหล่งข้อมูล</th>
              <th class="text-left px-5 py-3">จัดกลุ่มตาม</th>
              <th class="text-right px-5 py-3">ออกรายงาน</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="report in reports" :key="report.id" class="border-t border-gray-50">
              <td class="px-5 py-3 font-semibold text-gray-900">{{ report.name_th }}</td>
              <td class="px-5 py-3 font-mono text-xs text-gray-500">{{ report.data_source_code }}</td>
              <td class="px-5 py-3">{{ report.grouping?.column || '—' }}</td>
              <td class="px-5 py-3 text-right space-x-2 whitespace-nowrap">
                <button @click="runReport(report)" class="px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-100 text-xs font-semibold">ดูผล</button>
                <button @click="exportReport(report, 'pdf')" class="px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-100 text-xs font-semibold">PDF</button>
                <button @click="exportReport(report, 'xlsx')" class="px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-100 text-xs font-semibold">Excel</button>
              </td>
            </tr>
            <tr v-if="reports.length === 0">
              <td colspan="4" class="px-5 py-10 text-center text-gray-400">ยังไม่มีรายงานที่ออกแบบไว้</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- ผลรายงาน -->
      <div v-if="reportResult" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 overflow-x-auto">
        <h2 class="font-bold text-gray-900 mb-3">{{ reportResult.definition.name_th }}</h2>
        <table class="w-full text-sm min-w-[720px]">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left px-3 py-2">ลำดับ</th>
              <th v-for="(label, key) in reportResult.columns" :key="key" class="text-left px-3 py-2">{{ label }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="group in reportResult.groups" :key="group.key">
              <tr v-if="group.label" class="bg-gray-50">
                <td :colspan="Object.keys(reportResult.columns).length + 1" class="px-3 py-2 font-bold text-gray-700">{{ group.label }}</td>
              </tr>
              <tr v-for="(row, index) in group.rows" :key="group.key + '-' + index" class="border-t border-gray-50">
                <td class="px-3 py-2 text-gray-400">{{ index + 1 }}</td>
                <td v-for="(label, key) in reportResult.columns" :key="key" class="px-3 py-2">{{ row[key] }}</td>
              </tr>
              <tr v-if="group.label" class="border-t border-gray-100 bg-gray-50/60">
                <td class="px-3 py-2"></td>
                <td v-for="(label, key, i) in reportResult.columns" :key="key" class="px-3 py-2 font-semibold">
                  {{ i === 0 ? 'รวม ' + group.label : (group.subtotal?.[key] ?? '') }}
                </td>
              </tr>
            </template>
            <tr class="border-t-2 border-gray-200 bg-gray-100">
              <td class="px-3 py-2"></td>
              <td v-for="(label, key, i) in reportResult.columns" :key="key" class="px-3 py-2 font-bold">
                {{ i === 0 ? 'รวมทั้งสิ้น' : (reportResult.totals?.[key] ?? '') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<script setup>
/*
 * รายงานที่ผู้ดูแลออกแบบเอง — แยกออกมาจาก "ศูนย์จัดการเอกสาร"
 *
 * เดิมรายการรายงานเป็นแท็บหนึ่งของหน้าตั้งค่าเอกสาร ผู้ใช้ที่ต้องการดูรายงานจึงต้องเข้า
 * เมนูตั้งค่าระบบ ซึ่งเป็นคนละเรื่องกับการใช้งานรายงาน — ตอนนี้อยู่ใต้เมนู "รายงาน"
 * คู่กับศูนย์รายงานกลาง ส่วนหน้าตั้งค่าเหลือเฉพาะเรื่องแบบฟอร์มและเอกสาร
 */
import { ref, computed, onMounted } from 'vue'
import api from '../axios'
import { useAlertStore } from '../stores/alert'
import { useAuthStore } from '../stores/auth'

const alertStore = useAlertStore()
const auth = useAuthStore()

const reports = ref([])
const reportResult = ref(null)
const loading = ref(true)

// ปุ่มออกแบบรายงานแสดงเฉพาะผู้ที่มีสิทธิ์จัดการนิยามรายงาน (ด่านจริงอยู่ที่ฝั่งเซิร์ฟเวอร์)
const canDesign = computed(() => auth.hasAnyPermission(['document.report.manage', 'system.setting.manage']))

const load = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/admin/document-reports')
    reports.value = data
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'โหลดรายการรายงานไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const runReport = async (report) => {
  try {
    const res = await api.get(`/admin/document-reports/${report.id}/run`)
    reportResult.value = res.data
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'ประมวลผลรายงานไม่สำเร็จ')
  }
}

const exportReport = async (report, format) => {
  try {
    const res = await api.get(`/admin/document-reports/${report.id}/export/${format}`, { responseType: 'blob' })
    const mime = format === 'pdf' ? 'application/pdf' : 'application/octet-stream'
    const url = URL.createObjectURL(new Blob([res.data], { type: mime }))

    if (format === 'pdf') {
      window.open(url, '_blank')
      return
    }

    const link = document.createElement('a')
    link.href = url
    link.download = `${report.code}.${format}`
    link.click()
    URL.revokeObjectURL(url)
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'ส่งออกรายงานไม่สำเร็จ')
  }
}

onMounted(load)
</script>
