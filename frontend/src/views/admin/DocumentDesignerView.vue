<template>
  <div>
    <!-- Header -->
    <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6">
      <div>
        <button @click="$router.back()" class="text-sm text-gray-500 hover:text-gray-800 mb-1">← กลับไปศูนย์จัดการเอกสาร</button>
        <h1 class="text-2xl font-bold text-gray-900">{{ template?.name || 'ออกแบบแบบฟอร์ม' }}</h1>
        <p class="text-gray-500 text-sm mt-1">
          ลากตัวแปรจากรายการด้านซ้ายมาวางบนแบบฟอร์ม แล้วลากกล่องเพื่อจัดตำแหน่ง — ทุกอย่างบันทึกที่ "ฉบับร่าง" เท่านั้น
        </p>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <select
          v-model="selectedVersionId"
          @change="loadVersion"
          class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm bg-white min-h-[44px]"
        >
          <option v-for="version in versions" :key="version.id" :value="version.id">
            เวอร์ชัน {{ version.version }} — {{ statusLabel(version.status) }}
          </option>
        </select>

        <button @click="createVersion" :disabled="saving" class="px-4 py-2.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50">
          สร้างเวอร์ชันใหม่
        </button>

        <button v-if="isDraft" @click="triggerUpload" :disabled="saving" class="px-4 py-2.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50">
          อัปโหลดไฟล์ต้นฉบับ
        </button>
        <input ref="fileInput" type="file" accept="application/pdf" class="hidden" @change="uploadPdf" />

        <button @click="preview" :disabled="!hasPdf" class="px-4 py-2.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50">
          พรีวิว PDF
        </button>

        <button v-if="isDraft" @click="save" :disabled="saving" class="px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white font-semibold rounded-xl text-sm shadow-md min-h-[44px] disabled:opacity-50">
          {{ saving ? 'กำลังบันทึก...' : 'บันทึกตำแหน่งฟิลด์' }}
        </button>

        <button v-if="isDraft" @click="publish" :disabled="saving" class="px-5 py-2.5 bg-green-700 hover:bg-green-800 text-white font-semibold rounded-xl text-sm shadow-md min-h-[44px] disabled:opacity-50">
          เผยแพร่เวอร์ชันนี้
        </button>

        <button
          v-if="isDraft && versions.length > 1"
          @click="removeDraft"
          :disabled="saving"
          class="px-4 py-2.5 bg-white border border-red-200 hover:bg-red-50 text-red-600 font-semibold rounded-xl text-sm min-h-[44px] disabled:opacity-50"
          title="ลบฉบับร่างนี้ทิ้ง"
        >
          ลบฉบับร่าง
        </button>
      </div>
    </div>

    <div v-if="!isDraft" class="mb-4 rounded-2xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-900">
      เวอร์ชันนี้เผยแพร่แล้ว จึงแก้ตำแหน่งฟิลด์ไม่ได้ — เอกสารที่ออกไปแล้วอ้างอิงเวอร์ชันนี้อยู่
      กด "สร้างเวอร์ชันใหม่" เพื่อคัดลอกไปแก้ต่อ
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <div v-else class="grid grid-cols-1 xl:grid-cols-4 gap-6">
      <!-- รายการตัวแปร -->
      <aside class="xl:col-span-1 space-y-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
          <h3 class="font-bold text-gray-800 text-sm mb-2">ตัวแปรที่วางได้</h3>
          <input v-model="fieldSearch" type="text" placeholder="ค้นหาตัวแปร..." class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm mb-3" />

          <div class="max-h-[520px] overflow-y-auto space-y-3 pr-1">
            <div v-for="(groupFields, group) in groupedFields" :key="group">
              <p class="text-[11px] font-bold text-gray-400 uppercase mb-1">{{ group }}</p>
              <button
                v-for="field in groupFields"
                :key="field.id"
                @click="addField(field)"
                :disabled="!isDraft"
                class="w-full text-left px-3 py-2 rounded-xl border border-gray-100 hover:border-[#800000]/40 hover:bg-[#800000]/5 mb-1 disabled:opacity-40 disabled:cursor-not-allowed"
              >
                <span class="block text-xs font-semibold text-gray-800">{{ field.label_th }}</span>
                <span class="block font-mono text-[10px] text-gray-400">{{ field.code }}</span>
              </button>
            </div>
          </div>
        </div>

        <!-- คุณสมบัติของฟิลด์ที่เลือก -->
        <div v-if="selected" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="font-bold text-gray-800 text-sm">คุณสมบัติฟิลด์</h3>
            <button @click="removeSelected" :disabled="!isDraft" class="text-xs font-semibold text-red-600 hover:underline disabled:opacity-40">ลบฟิลด์</button>
          </div>
          <p class="font-mono text-[11px] text-gray-400 break-all">{{ selected.field_code }}</p>

          <div class="grid grid-cols-2 gap-2">
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">แนวนอน (pt)</span>
              <input v-model.number="selected.x" type="number" step="1" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">แนวตั้ง (pt)</span>
              <input v-model.number="selected.y" type="number" step="1" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">กว้าง</span>
              <input v-model.number="selected.width" type="number" step="1" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">สูง</span>
              <input v-model.number="selected.height" type="number" step="1" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ขนาดอักษร</span>
              <input v-model.number="selected.font_size" type="number" step="0.5" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>
            <label class="block">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">จัดวาง</span>
              <select v-model="selected.align" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm bg-white">
                <option value="left">ชิดซ้าย</option>
                <option value="center">กึ่งกลาง</option>
                <option value="right">ชิดขวา</option>
              </select>
            </label>
            <label class="block col-span-2">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">ชนิดฟิลด์</span>
              <select v-model="selected.field_type" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm bg-white">
                <option v-for="type in fieldTypes" :key="type" :value="type">{{ type }}</option>
              </select>
            </label>
            <label class="block col-span-2">
              <span class="block text-[11px] font-bold text-gray-500 uppercase mb-1">หน้า</span>
              <input v-model.number="selected.page" type="number" min="1" :max="pageCount" :disabled="!isDraft" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" />
            </label>
          </div>
        </div>
      </aside>

      <!-- พื้นที่ออกแบบ -->
      <section class="xl:col-span-3 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-2 text-sm">
            <span class="text-gray-500">หน้า</span>
            <button
              v-for="page in pageCount"
              :key="page"
              @click="currentPage = page"
              class="w-9 h-9 rounded-lg text-sm font-semibold"
              :class="currentPage === page ? 'bg-[#800000] text-white' : 'bg-white border border-gray-200 text-gray-600'"
            >{{ page }}</button>
          </div>
          <div class="flex items-center gap-2 text-sm">
            <span class="text-gray-500">ย่อ/ขยาย</span>
            <button @click="zoom = Math.max(0.5, zoom - 0.1)" class="w-9 h-9 rounded-lg bg-white border border-gray-200">−</button>
            <span class="w-14 text-center">{{ Math.round(zoom * 100) }}%</span>
            <button @click="zoom = Math.min(2, zoom + 0.1)" class="w-9 h-9 rounded-lg bg-white border border-gray-200">+</button>
            <label class="flex items-center gap-1.5 ml-3 text-xs text-gray-600">
              <input v-model="showBackground" type="checkbox" class="rounded" />
              แสดงแบบฟอร์มต้นฉบับ
            </label>
          </div>
        </div>

        <div v-if="!hasPdf" class="bg-white rounded-2xl border border-dashed border-gray-300 p-12 text-center text-gray-500">
          เวอร์ชันนี้ยังไม่มีไฟล์แบบฟอร์มต้นฉบับ — กด "อัปโหลดไฟล์ต้นฉบับ" เพื่อเริ่มวางฟิลด์
        </div>

        <!-- กระดาษ: ใช้ iframe แสดง PDF จริง แล้ววางกล่องฟิลด์ทับด้านบน -->
        <div v-else class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 overflow-auto">
          <div
            ref="canvas"
            class="relative mx-auto border border-gray-200 bg-white"
            :style="{ width: pageWidth * zoom + 'px', height: pageHeight * zoom + 'px' }"
            @click.self="selected = null"
          >
            <iframe
              v-if="showBackground && pdfUrl"
              :src="pdfUrl + '#page=' + currentPage + '&toolbar=0&navpanes=0&scrollbar=0&view=Fit'"
              class="absolute inset-0 w-full h-full pointer-events-none"
              frameborder="0"
            ></iframe>

            <div
              v-for="(mapping, index) in pageMappings"
              :key="mapping._key"
              class="absolute border-2 rounded cursor-move flex items-center px-1 overflow-hidden select-none"
              :class="selected === mapping
                ? 'border-[#800000] bg-[#800000]/15'
                : 'border-blue-500/70 bg-blue-500/10 hover:bg-blue-500/20'"
              :style="boxStyle(mapping)"
              @mousedown.stop="startDrag($event, mapping)"
              @click.stop="selected = mapping"
            >
              <span class="text-[10px] font-semibold text-gray-800 truncate pointer-events-none">
                {{ labelFor(mapping.field_code) }}
              </span>
              <span
                v-if="isDraft"
                class="absolute bottom-0 right-0 w-3 h-3 bg-[#800000] cursor-se-resize"
                @mousedown.stop="startResize($event, mapping)"
              ></span>
            </div>
          </div>

          <p class="text-xs text-gray-400 mt-3 text-center">
            พิกัดเป็นหน่วย PDF point วัดจากมุมซ้ายบนของหน้า — ค่าเดียวกับที่ระบบใช้ตอนพิมพ์จริง
          </p>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'

const route = useRoute()
const alertStore = useAlertStore()

// ขนาดหน้ากระดาษ A4 เป็น point — ตรงกับหน่วยที่ backend ใช้วางฟิลด์
const pageWidth = 595
const pageHeight = 842

const loading = ref(false)
const saving = ref(false)
const template = ref(null)
const versions = ref([])
const selectedVersionId = ref(null)
const version = ref(null)
const mappings = ref([])
const fields = ref([])
const selected = ref(null)
const currentPage = ref(1)
const zoom = ref(1)
const showBackground = ref(true)
const fieldSearch = ref('')
const pdfUrl = ref('')
const fileInput = ref(null)
const canvas = ref(null)

const fieldTypes = ['TEXT', 'MULTILINE_TEXT', 'DATE', 'NUMBER', 'CHECKBOX', 'SIGNATURE', 'IMAGE', 'QR_CODE', 'BARCODE']

const isDraft = computed(() => version.value?.status === 'DRAFT')
const hasPdf = computed(() => !!version.value?.pdf_file_path)
const pageCount = computed(() => version.value?.page_count || 1)
const pageMappings = computed(() => mappings.value.filter(m => Number(m.page) === currentPage.value))

const groupedFields = computed(() => {
  const query = fieldSearch.value.trim().toLowerCase()
  const groups = {}
  for (const field of fields.value) {
    if (query && !field.code.toLowerCase().includes(query) && !field.label_th.toLowerCase().includes(query)) continue
    const key = field.group_th || 'อื่น ๆ'
    groups[key] = groups[key] || []
    groups[key].push(field)
  }
  return groups
})

const statusLabel = (status) => ({ DRAFT: 'ฉบับร่าง', ACTIVE: 'ใช้งานอยู่', RETIRED: 'เลิกใช้แล้ว', ARCHIVED: 'เก็บถาวร' })[status] || status
const labelFor = (code) => fields.value.find(f => f.code === code)?.label_th || code

const boxStyle = (mapping) => ({
  left: mapping.x * zoom.value + 'px',
  top: mapping.y * zoom.value + 'px',
  width: mapping.width * zoom.value + 'px',
  height: mapping.height * zoom.value + 'px',
})

const loadTemplate = async () => {
  loading.value = true
  try {
    const [tpl, fld] = await Promise.all([
      api.get(`/admin/document-engine/templates/${route.params.templateId}`),
      api.get('/admin/document-engine/fields'),
    ])
    template.value = tpl.data.template
    versions.value = tpl.data.versions
    fields.value = fld.data

    // เปิดฉบับร่างล่าสุดให้ก่อน เพราะเป็นเวอร์ชันเดียวที่แก้ได้
    const draft = versions.value.find(v => v.status === 'DRAFT')
    selectedVersionId.value = draft?.id || versions.value[0]?.id
    await loadVersion()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'โหลดแม่แบบไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const loadVersion = async () => {
  if (!selectedVersionId.value) return
  try {
    const res = await api.get(`/admin/document-engine/versions/${selectedVersionId.value}/mappings`)
    version.value = res.data.version
    mappings.value = res.data.mappings.map((m, index) => ({ ...m, _key: `${m.id}-${index}`, x: Number(m.x), y: Number(m.y), width: Number(m.width), height: Number(m.height), font_size: Number(m.font_size) }))
    selected.value = null
    currentPage.value = 1
    await loadPdf()
  } catch (error) {
    alertStore.error('โหลดตำแหน่งฟิลด์ไม่สำเร็จ')
  }
}

const loadPdf = async () => {
  if (pdfUrl.value) URL.revokeObjectURL(pdfUrl.value)
  pdfUrl.value = ''
  if (!version.value?.pdf_file_path) return
  try {
    const res = await api.get(`/admin/document-engine/versions/${version.value.id}/pdf`, { responseType: 'blob' })
    pdfUrl.value = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
  } catch (error) {
    // แสดงกล่องฟิลด์ต่อได้แม้โหลดพื้นหลังไม่ขึ้น
    showBackground.value = false
  }
}

const addField = (field) => {
  if (!isDraft.value) return
  const mapping = {
    _key: `new-${Date.now()}-${Math.random()}`,
    field_code: field.code,
    field_type: field.data_type || 'TEXT',
    page: currentPage.value,
    x: 60, y: 60, width: 200, height: 20,
    font: null, font_size: 14, align: 'left', overflow: 'shrink',
    format: null, static_text: null, options: null,
  }
  mappings.value.push(mapping)
  selected.value = mapping
}

const removeSelected = () => {
  if (!isDraft.value || !selected.value) return
  mappings.value = mappings.value.filter(m => m !== selected.value)
  selected.value = null
}

// ── ลาก/ปรับขนาดกล่องฟิลด์ ──
let dragState = null

const startDrag = (event, mapping) => {
  if (!isDraft.value) return
  selected.value = mapping
  dragState = { mode: 'move', mapping, startX: event.clientX, startY: event.clientY, originX: mapping.x, originY: mapping.y }
  window.addEventListener('mousemove', onDrag)
  window.addEventListener('mouseup', endDrag)
}

const startResize = (event, mapping) => {
  if (!isDraft.value) return
  selected.value = mapping
  dragState = { mode: 'resize', mapping, startX: event.clientX, startY: event.clientY, originW: mapping.width, originH: mapping.height }
  window.addEventListener('mousemove', onDrag)
  window.addEventListener('mouseup', endDrag)
}

const onDrag = (event) => {
  if (!dragState) return
  const dx = (event.clientX - dragState.startX) / zoom.value
  const dy = (event.clientY - dragState.startY) / zoom.value

  if (dragState.mode === 'move') {
    dragState.mapping.x = Math.max(0, Math.round(dragState.originX + dx))
    dragState.mapping.y = Math.max(0, Math.round(dragState.originY + dy))
  } else {
    dragState.mapping.width = Math.max(10, Math.round(dragState.originW + dx))
    dragState.mapping.height = Math.max(8, Math.round(dragState.originH + dy))
  }
}

const endDrag = () => {
  dragState = null
  window.removeEventListener('mousemove', onDrag)
  window.removeEventListener('mouseup', endDrag)
}

// ── การกระทำกับเวอร์ชัน ──
const triggerUpload = () => fileInput.value?.click()

const uploadPdf = async (event) => {
  const file = event.target.files?.[0]
  if (!file) return
  saving.value = true
  try {
    const form = new FormData()
    form.append('file', file)
    const res = await api.post(`/admin/document-engine/versions/${version.value.id}/pdf`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    alertStore.success(res.data.message)
    version.value = res.data.version
    await loadPdf()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'อัปโหลดไฟล์ไม่สำเร็จ')
  } finally {
    saving.value = false
    event.target.value = ''
  }
}

const save = async () => {
  saving.value = true
  try {
    const res = await api.put(`/admin/document-engine/versions/${version.value.id}/mappings`, {
      mappings: mappings.value.map(m => ({
        field_code: m.field_code,
        field_type: m.field_type,
        page: Number(m.page) || 1,
        x: Number(m.x), y: Number(m.y),
        width: Number(m.width), height: Number(m.height),
        font: m.font || null,
        font_size: Number(m.font_size) || 14,
        align: m.align || 'left',
        overflow: m.overflow || 'shrink',
        format: m.format || null,
        static_text: m.static_text || null,
        options: m.options || null,
      })),
    })
    alertStore.success(res.data.message)
  } catch (error) {
    alertStore.error(error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || 'บันทึกไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

const preview = async () => {
  try {
    const res = await api.get(`/admin/document-engine/versions/${version.value.id}/preview`, { responseType: 'blob' })
    window.open(URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' })), '_blank')
  } catch (error) {
    alertStore.error('สร้างตัวอย่างเอกสารไม่สำเร็จ — ตรวจสอบว่าอัปโหลดแบบฟอร์มต้นฉบับแล้วหรือยัง')
  }
}

const createVersion = async () => {
  saving.value = true
  try {
    const res = await api.post(`/admin/document-engine/templates/${template.value.id}/versions`, {
      from_version_id: version.value?.id,
    })
    alertStore.success(res.data.message)
    await loadTemplate()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'สร้างเวอร์ชันใหม่ไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

const removeDraft = async () => {
  if (!window.confirm('ลบฉบับร่างนี้ทิ้ง? ตำแหน่งฟิลด์ที่วางไว้ในฉบับร่างนี้จะหายไปด้วย (เวอร์ชันที่เผยแพร่แล้วไม่ถูกแตะ)')) return

  saving.value = true
  try {
    const res = await api.delete(`/admin/document-engine/versions/${version.value.id}`)
    alertStore.success(res.data.message)
    selectedVersionId.value = null
    await loadTemplate()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'ลบฉบับร่างไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

const publish = async () => {
  if (!window.confirm('เผยแพร่เวอร์ชันนี้? เอกสารที่ออกใหม่จะใช้เวอร์ชันนี้ และเวอร์ชันเดิมจะถูกปลดเป็น "เลิกใช้แล้ว" (ไม่ถูกลบ)')) return
  saving.value = true
  try {
    await save()
    const res = await api.post(`/admin/document-engine/versions/${version.value.id}/publish`)
    alertStore.success(res.data.message)
    await loadTemplate()
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'เผยแพร่ไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

onMounted(loadTemplate)
onBeforeUnmount(() => {
  if (pdfUrl.value) URL.revokeObjectURL(pdfUrl.value)
  endDrag()
})
</script>
