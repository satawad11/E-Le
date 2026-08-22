<template>
  <div>
    <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6">
      <div>
        <button @click="$router.back()" class="text-sm text-gray-500 hover:text-gray-800 mb-1">← กลับไปศูนย์จัดการเอกสาร</button>
        <h1 class="text-2xl font-bold text-gray-900">ออกแบบรายงาน</h1>
        <p class="text-gray-500 text-sm mt-1">
          เลือกแหล่งข้อมูลที่ระบบมีให้ แล้วจัดคอลัมน์ การจัดกลุ่ม และยอดรวม — ไม่ต้องเขียนคำสั่งฐานข้อมูลเอง
        </p>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <select v-model="selectedId" @change="selectReport" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm bg-white min-h-[44px]">
          <option :value="null">+ สร้างรายงานใหม่</option>
          <option v-for="report in reports" :key="report.id" :value="report.id">{{ report.name_th }}</option>
        </select>
        <button @click="runPreview" :disabled="!form.data_source_code || saving" class="px-4 py-2.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50">
          ทดลองประมวลผล
        </button>
        <button @click="save" :disabled="saving" class="px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm shadow-md min-h-[44px] disabled:opacity-50">
          {{ saving ? 'กำลังบันทึก...' : 'บันทึกรายงาน' }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <div v-else class="grid grid-cols-1 xl:grid-cols-3 gap-6">
      <!-- ตั้งค่ารายงาน -->
      <section class="xl:col-span-1 space-y-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-3">
          <h3 class="font-bold text-gray-800 text-sm">ข้อมูลรายงาน</h3>

          <label class="block">
            <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ชื่อรายงาน *</span>
            <input v-model="form.name_th" type="text" placeholder="เช่น รายงานสถิติการลาประจำเดือน" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm" />
          </label>

          <label class="block">
            <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">รหัสรายงาน *</span>
            <input v-model="form.code" type="text" placeholder="LEAVE_MONTHLY_STATISTICS" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-mono" />
          </label>

          <label class="block">
            <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">แหล่งข้อมูล *</span>
            <select v-model="form.data_source_code" @change="loadColumns" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
              <option value="">เลือกแหล่งข้อมูล</option>
              <option v-for="source in datasetSources" :key="source.id" :value="source.code">{{ source.name_th }}</option>
            </select>
            <span class="block text-[11px] text-gray-400 mt-1">เลือกได้เฉพาะแหล่งข้อมูลที่ระบบลงทะเบียนไว้ ขอบเขตข้อมูลยังเป็นไปตามสิทธิ์ของผู้เรียกรายงานเสมอ</span>
          </label>

          <div class="grid grid-cols-2 gap-3">
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">โมดูล</span>
              <select v-model="form.module" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
                <option value="leave">ระบบการลา</option>
                <option value="hr">งานบุคคล</option>
                <option value="finance">การเงิน</option>
                <option value="core">ส่วนกลาง</option>
              </select>
            </label>
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">แนวกระดาษ</span>
              <select v-model="form.orientation" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
                <option value="portrait">แนวตั้ง</option>
                <option value="landscape">แนวนอน</option>
              </select>
            </label>
          </div>

          <label class="block">
            <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">จัดกลุ่มตาม</span>
            <select v-model="groupColumn" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">
              <option value="">ไม่จัดกลุ่ม</option>
              <option v-for="column in availableColumns" :key="column.key" :value="column.key">{{ column.label }}</option>
            </select>
            <span class="block text-[11px] text-gray-400 mt-1">จัดกลุ่มแล้วจะมียอดรวมของแต่ละกลุ่มต่อท้ายอัตโนมัติ</span>
          </label>

          <label class="flex items-center gap-2 text-sm text-gray-700">
            <input v-model="withSignature" type="checkbox" class="rounded" />
            มีช่องลงนามท้ายรายงาน
          </label>
        </div>
      </section>

      <!-- คอลัมน์และผลรวม -->
      <section class="xl:col-span-2 space-y-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <h3 class="font-bold text-gray-800 text-sm mb-3">คอลัมน์ที่แสดงในรายงาน</h3>

          <div v-if="availableColumns.length === 0" class="text-sm text-gray-400 py-6 text-center">
            เลือกแหล่งข้อมูลก่อน จึงจะเห็นคอลัมน์ที่ใช้ได้
          </div>

          <div v-else class="space-y-2">
            <div
              v-for="column in availableColumns"
              :key="column.key"
              class="flex flex-wrap items-center gap-3 border border-gray-100 rounded-xl px-3 py-2"
            >
              <label class="flex items-center gap-2 flex-1 min-w-[180px]">
                <input type="checkbox" :checked="isSelected(column.key)" @change="toggleColumn(column)" class="rounded" />
                <span class="text-sm font-semibold text-gray-800">{{ column.label }}</span>
                <span class="font-mono text-[10px] text-gray-400">{{ column.key }}</span>
              </label>

              <input
                v-if="isSelected(column.key)"
                v-model="labelFor(column.key).label"
                type="text"
                placeholder="ชื่อหัวคอลัมน์"
                class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs w-40"
              />

              <select
                v-if="isSelected(column.key)"
                :value="aggregateFor(column.key)"
                @change="setAggregate(column.key, $event.target.value)"
                class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs bg-white"
              >
                <option value="">ไม่มีผลรวม</option>
                <option value="SUM">รวม (SUM)</option>
                <option value="COUNT">นับจำนวน (COUNT)</option>
                <option value="AVG">เฉลี่ย (AVG)</option>
                <option value="MIN">ต่ำสุด (MIN)</option>
                <option value="MAX">สูงสุด (MAX)</option>
              </select>

              <div v-if="isSelected(column.key)" class="flex items-center gap-1">
                <button @click="move(column.key, -1)" class="w-7 h-7 rounded border border-gray-200 text-xs">↑</button>
                <button @click="move(column.key, 1)" class="w-7 h-7 rounded border border-gray-200 text-xs">↓</button>
              </div>
            </div>
          </div>
        </div>

        <!-- ผลทดลอง -->
        <div v-if="preview" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 overflow-x-auto">
          <h3 class="font-bold text-gray-900 mb-3">ตัวอย่างผลรายงาน</h3>
          <table class="w-full text-sm min-w-[640px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-3 py-2">ลำดับ</th>
                <th v-for="(label, key) in preview.columns" :key="key" class="text-left px-3 py-2">{{ label }}</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="group in preview.groups" :key="group.key">
                <tr v-if="group.label" class="bg-gray-50">
                  <td :colspan="Object.keys(preview.columns).length + 1" class="px-3 py-2 font-bold text-gray-700">{{ group.label }}</td>
                </tr>
                <tr v-for="(row, index) in group.rows" :key="group.key + '-' + index" class="border-t border-gray-50">
                  <td class="px-3 py-2 text-gray-400">{{ index + 1 }}</td>
                  <td v-for="(label, key) in preview.columns" :key="key" class="px-3 py-2">{{ row[key] }}</td>
                </tr>
              </template>
              <tr class="border-t-2 border-gray-200 bg-gray-100">
                <td class="px-3 py-2"></td>
                <td v-for="(label, key, i) in preview.columns" :key="key" class="px-3 py-2 font-bold">
                  {{ i === 0 ? 'รวมทั้งสิ้น' : (preview.totals?.[key] ?? '') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'

const alertStore = useAlertStore()

const loading = ref(false)
const saving = ref(false)
const reports = ref([])
const dataSources = ref([])
const selectedId = ref(null)
const preview = ref(null)

/*
 * คอลัมน์ที่เลือกได้มาจาก "ผลจริง" ของแหล่งข้อมูล ไม่ใช่รายการที่พิมพ์เอง
 * ผู้ดูแลจึงเลือกได้เฉพาะสิ่งที่ระบบมีข้อมูลให้จริง
 */
const availableColumns = ref([])

const form = ref({
  id: null,
  code: '',
  name_th: '',
  module: 'leave',
  data_source_code: '',
  columns: [],
  aggregates: [],
  orientation: 'portrait',
  paper_size: 'A4',
})

const groupColumn = ref('')
const withSignature = ref(false)

const datasetSources = computed(() => dataSources.value.filter(source => source.kind === 'DATASET'))

const isSelected = (key) => form.value.columns.some(column => column.key === key)
const labelFor = (key) => form.value.columns.find(column => column.key === key) || { label: '' }
const aggregateFor = (key) => form.value.aggregates.find(item => item.column === key)?.function || ''

const toggleColumn = (column) => {
  if (isSelected(column.key)) {
    form.value.columns = form.value.columns.filter(item => item.key !== column.key)
    form.value.aggregates = form.value.aggregates.filter(item => item.column !== column.key)
    return
  }
  form.value.columns.push({ key: column.key, label: column.label })
}

const setAggregate = (key, fn) => {
  form.value.aggregates = form.value.aggregates.filter(item => item.column !== key)
  if (fn) form.value.aggregates.push({ column: key, function: fn })
}

const move = (key, direction) => {
  const index = form.value.columns.findIndex(column => column.key === key)
  const target = index + direction
  if (index < 0 || target < 0 || target >= form.value.columns.length) return
  const [item] = form.value.columns.splice(index, 1)
  form.value.columns.splice(target, 0, item)
}

const loadAll = async () => {
  loading.value = true
  try {
    const [rep, src] = await Promise.all([
      api.get('/admin/document-reports'),
      api.get('/admin/document-engine/data-sources'),
    ])
    reports.value = rep.data
    dataSources.value = src.data
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const selectReport = () => {
  preview.value = null

  if (!selectedId.value) {
    form.value = { id: null, code: '', name_th: '', module: 'leave', data_source_code: '', columns: [], aggregates: [], orientation: 'portrait', paper_size: 'A4' }
    groupColumn.value = ''
    withSignature.value = false
    availableColumns.value = []
    return
  }

  const report = reports.value.find(item => item.id === selectedId.value)
  form.value = {
    id: report.id,
    code: report.code,
    name_th: report.name_th,
    module: report.module,
    data_source_code: report.data_source_code,
    columns: report.columns || [],
    aggregates: report.aggregates || [],
    orientation: report.orientation || 'portrait',
    paper_size: report.paper_size || 'A4',
  }
  groupColumn.value = report.grouping?.column || ''
  withSignature.value = !!report.layout?.signature
  loadColumns()
}

/** ดึงคอลัมน์จริงของแหล่งข้อมูลด้วยการรันรายงานที่มีอยู่ หรือรายงานตัวอย่างของแหล่งนั้น */
const loadColumns = async () => {
  if (!form.value.data_source_code) {
    availableColumns.value = []
    return
  }

  const sample = reports.value.find(report => report.data_source_code === form.value.data_source_code)

  if (!sample) {
    // ยังไม่มีรายงานใดใช้แหล่งนี้ — ใช้คอลัมน์ที่ผู้ดูแลเคยเลือกไว้เป็นตัวตั้ง
    availableColumns.value = form.value.columns.map(column => ({ key: column.key, label: column.label }))
    return
  }

  try {
    const res = await api.get(`/admin/document-reports/${sample.id}/run`)
    availableColumns.value = Object.entries(res.data.columns).map(([key, label]) => ({ key, label }))
  } catch {
    availableColumns.value = form.value.columns.map(column => ({ key: column.key, label: column.label }))
  }
}

const save = async () => {
  if (!form.value.code.trim() || !form.value.name_th.trim() || !form.value.data_source_code) {
    alertStore.error('กรุณากรอกชื่อ รหัส และเลือกแหล่งข้อมูลของรายงาน')
    return
  }

  saving.value = true
  try {
    const res = await api.post('/admin/document-reports', {
      ...form.value,
      grouping: groupColumn.value ? { column: groupColumn.value } : null,
      layout: { title: form.value.name_th, signature: withSignature.value ? {} : null },
    })
    alertStore.success(res.data.message)
    selectedId.value = res.data.report.id
    await loadAll()
    selectReport()
  } catch (error) {
    alertStore.error(error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || 'บันทึกรายงานไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

const runPreview = async () => {
  if (!form.value.id) {
    alertStore.error('บันทึกรายงานก่อน จึงจะทดลองประมวลผลได้')
    return
  }
  try {
    const res = await api.get(`/admin/document-reports/${form.value.id}/run`)
    preview.value = res.data
  } catch {
    alertStore.error('ประมวลผลรายงานไม่สำเร็จ')
  }
}

onMounted(loadAll)
</script>
