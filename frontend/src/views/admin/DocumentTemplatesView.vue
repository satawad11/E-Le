<template>
  <div class="max-w-7xl mx-auto space-y-6 pb-12">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 tracking-tight">แบบฟอร์มใบลาในระบบ</h1>
        <p class="text-sm text-gray-500 mt-1">ดูแบบฟอร์มที่ใช้พิมพ์ใบลาแต่ละประเภท และพรีวิวด้วยข้อมูลตัวอย่าง</p>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <button
          @click="seedDefaults"
          :disabled="seeding"
          class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 font-medium hover:bg-gray-50 text-sm transition-all"
        >
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.refresh"/>
          </svg>
          {{ seeding ? 'กำลังซิงค์...' : 'ลงทะเบียนแบบฟอร์มที่ขาด' }}
        </button>
        <router-link
          v-if="authStore.canManageDocumentCenter"
          to="/admin/document-center"
          class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-[#800000] text-white font-medium hover:bg-[#660000] transition-all shadow-sm text-sm"
        >
          ออกแบบแบบฟอร์มบน PDF ต้นฉบับ
        </router-link>
      </div>
    </div>

    <!-- อธิบายว่าปรับแบบฟอร์มที่ไหน -->
    <div class="p-4 rounded-xl bg-blue-50 border border-blue-200 text-blue-900 text-sm space-y-1">
      <p class="font-semibold">แบบฟอร์มในหน้านี้เป็นแบบฟอร์มมาตรฐานของระบบ และแก้ไขจากหน้านี้ไม่ได้</p>
      <p>
        ถ้าต้องการปรับแบบฟอร์มให้ตรงกับของหน่วยงาน ให้ใช้ <strong>ศูนย์จัดการเอกสาร</strong>: อัปโหลดแบบฟอร์ม PDF ต้นฉบับ
        วางตำแหน่งช่องข้อมูล แล้วเผยแพร่ ระบบจะใช้แบบฟอร์มนั้นแทนให้อัตโนมัติ
      </p>
      <p>ส่วนการเลือกว่าประเภทการลาใดใช้แบบฟอร์มใด ตั้งค่าได้ที่หน้า <router-link to="/admin/leave-types" class="underline font-semibold">ประเภทการลา</router-link></p>
    </div>

    <div v-if="loading" class="text-center py-12 text-gray-500 text-sm">กำลังโหลดแบบฟอร์ม...</div>
    <div v-else-if="loadError" class="text-center py-12 bg-white rounded-2xl border border-red-100 text-red-600 text-sm">
      {{ loadError }}
    </div>
    <div v-else-if="leaveTemplates.length === 0" class="text-center py-16 bg-white rounded-2xl border border-gray-100 shadow-sm text-gray-400 text-sm">
      ยังไม่มีแบบฟอร์มใบลาในระบบ กดปุ่ม "ลงทะเบียนแบบฟอร์มที่ขาด"
    </div>
    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-for="tpl in leaveTemplates"
        :key="tpl.id"
        class="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm flex flex-col justify-between"
      >
        <div class="space-y-3">
          <div class="flex items-center justify-between gap-2">
            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold"
              :class="tpl.render_mode === 'pdf_overlay' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600'">
              {{ tpl.render_mode === 'pdf_overlay' ? 'แบบฟอร์ม PDF' : 'แบบฟอร์มมาตรฐาน' }}
            </span>
            <span class="text-xs font-mono text-gray-400">{{ tpl.code }}</span>
          </div>
          <h3 class="font-bold text-gray-900 text-base leading-snug">{{ tpl.name }}</h3>
          <div>
            <p class="text-xs text-gray-500 mb-1">ใช้กับประเภทการลา</p>
            <div v-if="tpl.leave_types?.length" class="flex flex-wrap gap-1.5">
              <span v-for="type in tpl.leave_types" :key="type.id" class="px-2 py-0.5 rounded-md bg-[#800000]/5 text-[#800000] text-xs">
                {{ type.name }}
              </span>
            </div>
            <p v-else class="text-xs text-amber-700">ยังไม่มีประเภทการลาที่ใช้แบบฟอร์มนี้</p>
          </div>
        </div>

        <div class="mt-5 pt-4 border-t border-gray-100">
          <button
            v-if="tpl.has_preview"
            @click="preview(tpl)"
            :disabled="previewingId === tpl.id"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-100 rounded-lg"
          >
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.eye"/>
            </svg>
            {{ previewingId === tpl.id ? 'กำลังสร้างพรีวิว...' : 'พรีวิว PDF ด้วยข้อมูลตัวอย่าง' }}
          </button>
          <p v-else class="text-xs text-gray-400">พรีวิวแบบฟอร์มนี้ได้ที่ศูนย์จัดการเอกสาร</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { useAuthStore } from '../../stores/auth'
import { ICONS } from '../../utils/icons'
import { getErrorMessage } from '../../utils/errorHandler'

/*
 * TPL-SEC-01 — หน้านี้อ่านอย่างเดียว
 *
 * เดิมหน้านี้ให้แก้โค้ด HTML/Blade ของแบบฟอร์ม และเซิร์ฟเวอร์รันโค้ดนั้นจริง
 * ซึ่งเท่ากับให้รัน PHP บนเซิร์ฟเวอร์ได้ จึงปิดไป การออกแบบแบบฟอร์มย้ายไปที่ศูนย์จัดการเอกสาร
 */
const alertStore = useAlertStore()
const authStore = useAuthStore()

const templates = ref([])
const loading = ref(true)
const loadError = ref('')
const seeding = ref(false)
const previewingId = ref(null)

const leaveTemplates = computed(() => templates.value.filter((tpl) => tpl.module === 'leave'))

const fetchTemplates = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.get('/admin/document-templates', { params: { module: 'leave' } })
    templates.value = res.data
  } catch (err) {
    loadError.value = getErrorMessage(err, 'โหลดรายการแบบฟอร์มไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const preview = async (tpl) => {
  previewingId.value = tpl.id
  try {
    const res = await api.get(`/admin/document-templates/${tpl.id}/preview`, { responseType: 'blob' })
    const url = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    window.open(url, '_blank')
    setTimeout(() => window.URL.revokeObjectURL(url), 60000)
  } catch (err) {
    let detail = null
    if (err.response?.data instanceof Blob) {
      try {
        detail = JSON.parse(await err.response.data.text())?.message
      } catch {
        detail = null
      }
    }
    alertStore.error(detail || 'สร้างพรีวิวไม่สำเร็จ')
  } finally {
    previewingId.value = null
  }
}

const seedDefaults = async () => {
  seeding.value = true
  try {
    const res = await api.post('/admin/document-templates/seed-defaults')
    alertStore.success(res.data.message || 'ลงทะเบียนแบบฟอร์มเรียบร้อยแล้ว')
    await fetchTemplates()
  } catch (err) {
    alertStore.error(getErrorMessage(err, 'ลงทะเบียนแบบฟอร์มไม่สำเร็จ'))
  } finally {
    seeding.value = false
  }
}

onMounted(fetchTemplates)
</script>
