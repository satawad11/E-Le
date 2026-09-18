<template>
  <transition name="modal-fade">
    <div
      v-if="isOpen"
      class="fixed inset-0 z-50 overflow-y-auto bg-black/60 backdrop-blur-sm flex items-center justify-center p-4 select-none"
      role="dialog"
      aria-modal="true"
      aria-labelledby="dispatch-modal-title"
      @keydown.escape="close"
    >
      <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full border border-gray-100 overflow-hidden text-gray-800 animate-in fade-in zoom-in-95 duration-200">
        <!-- Header -->
        <div class="px-6 py-4 bg-gradient-to-r from-[#800000] to-[#990000] text-white flex items-center justify-between">
          <div class="flex items-center gap-2.5">
            <div class="w-9 h-9 rounded-xl bg-white/15 flex items-center justify-center border border-white/20">
              <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
              </svg>
            </div>
            <div>
              <h2 id="dispatch-modal-title" class="font-bold text-base leading-tight">ส่งหนังสือราชการข้ามสถานศึกษา / หน่วยงาน</h2>
              <p class="text-xs text-white/70">Inter-College & Inter-Agency Electronic Envelope Exchange (SoT §17, §27)</p>
            </div>
          </div>
          <button
            type="button"
            @click="close"
            class="p-1.5 rounded-lg text-white/80 hover:bg-white/20 hover:text-white transition-colors focus:outline-none"
            title="ปิดหน้าต่าง"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <!-- Body -->
        <div class="p-6 space-y-5 max-h-[75vh] overflow-y-auto">
          <!-- ข้อมูลหนังสือเบื้องต้น -->
          <div class="bg-amber-50/70 border border-amber-200 rounded-xl p-3.5 text-xs text-amber-900 space-y-1">
            <p class="font-semibold text-sm text-amber-950 flex items-center gap-1.5">
              <span>📄</span>
              <span class="truncate">{{ document?.subject || 'หนังสือราชการ' }}</span>
            </p>
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-amber-800">
              <span>เลขที่: <strong>{{ document?.document_number || 'ฉบับร่าง' }}</strong></span>
              <span>สถานะ: <strong>{{ document?.status || '-' }}</strong></span>
              <span>แฮชความถูกต้อง: <code class="font-mono text-[10px] bg-amber-100 px-1 py-0.5 rounded">{{ (document?.verification_hash || 'SHA-512').slice(0, 16) }}...</code></span>
            </div>
          </div>

          <!-- แบบฟอร์มระบุปลายทาง -->
          <div class="space-y-4 text-sm">
            <div>
              <label for="target-agency" class="block font-semibold text-gray-700 mb-1">
                ชื่อสถานศึกษา / หน่วยงานปลายทาง <span class="text-red-500">*</span>
              </label>
              <input
                id="target-agency"
                v-model="form.target_agency_name"
                type="text"
                placeholder="เช่น วิทยาลัยเทคนิคเชียงใหม่ / สำนักงานคณะกรรมการการอาชีวศึกษา"
                class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl focus:bg-white focus:ring-2 focus:ring-[#800000] focus:border-transparent text-sm transition-all"
              />
            </div>

            <!-- ช่องทางการส่ง -->
            <div>
              <label class="block font-semibold text-gray-700 mb-2">รูปแบบการส่งมอบเอกสาร</label>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label
                  class="flex items-start gap-3 p-3.5 border rounded-xl cursor-pointer transition-all"
                  :class="form.method === 'download' ? 'border-[#800000] bg-[#800000]/5 ring-1 ring-[#800000]' : 'border-gray-200 hover:bg-gray-50'"
                >
                  <input type="radio" v-model="form.method" value="download" class="mt-0.5 text-[#800000] focus:ring-[#800000]"/>
                  <div>
                    <p class="font-bold text-gray-900 text-xs sm:text-sm">📦 ดาวน์โหลดแพ็กเกจหลักฐาน (.zip)</p>
                    <p class="text-xs text-gray-500 mt-0.5">รวมไฟล์ PDF, เอกสารแนบ, บันทึกการลงนามดิจิทัล และ manifest.json พร้อมค่าแฮช SHA-512</p>
                  </div>
                </label>

                <label
                  class="flex items-start gap-3 p-3.5 border rounded-xl cursor-pointer transition-all"
                  :class="form.method === 'webhook' ? 'border-[#800000] bg-[#800000]/5 ring-1 ring-[#800000]' : 'border-gray-200 hover:bg-gray-50'"
                >
                  <input type="radio" v-model="form.method" value="webhook" class="mt-0.5 text-[#800000] focus:ring-[#800000]"/>
                  <div>
                    <p class="font-bold text-gray-900 text-xs sm:text-sm">⚡ ส่งตรงผ่าน Webhook API</p>
                    <p class="text-xs text-gray-500 mt-0.5">ส่งข้อมูลไปยังปลายทางอัตโนมัติพร้อมลายมือชื่อความปลอดภัย HMAC-SHA256</p>
                  </div>
                </label>
              </div>
            </div>

            <!-- ข้อมูลเพิ่มเติมกรณี Webhook -->
            <div v-if="form.method === 'webhook'" class="space-y-3 p-4 bg-gray-50 rounded-xl border border-gray-200 animate-in fade-in duration-150">
              <div>
                <label for="webhook-url" class="block font-semibold text-gray-700 text-xs mb-1">
                  Webhook Endpoint URL <span class="text-red-500">*</span>
                </label>
                <input
                  id="webhook-url"
                  v-model="form.webhook_url"
                  type="url"
                  placeholder="https://agency.domain.go.th/api/saraban/incoming/import-package"
                  class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-xs focus:ring-2 focus:ring-[#800000] focus:outline-none"
                />
              </div>
              <div>
                <label for="secret-key" class="block font-semibold text-gray-700 text-xs mb-1">
                  รหัสลับความปลอดภัย (Secret Key สำหรับ HMAC) <span class="text-red-500">*</span>
                </label>
                <input
                  id="secret-key"
                  v-model="form.secret_key"
                  type="password"
                  placeholder="อย่างน้อย 8 ตัวอักษร"
                  class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-xs focus:ring-2 focus:ring-[#800000] focus:outline-none"
                />
              </div>
            </div>

            <!-- บันทึกข้อความเพิ่มเติม -->
            <div>
              <label for="dispatch-note" class="block font-semibold text-gray-700 mb-1">บันทึกข้อความประกอบการส่ง</label>
              <textarea
                id="dispatch-note"
                v-model="form.note"
                rows="2"
                placeholder="ระบุข้อความนำส่ง หรือหมายเหตุเพิ่มเติม (ถ้ามี)"
                class="w-full px-3.5 py-2 bg-gray-50 border border-gray-300 rounded-xl focus:bg-white focus:ring-2 focus:ring-[#800000] text-xs transition-all"
              ></textarea>
            </div>
          </div>

          <!-- ข้อความแจ้งเตือนข้อกำหนด -->
          <div class="flex items-start gap-2.5 p-3 bg-blue-50/70 border border-blue-200 rounded-xl text-xs text-blue-900">
            <span class="text-base flex-shrink-0">ℹ️</span>
            <div>
              <p class="font-semibold text-blue-950">มาตรฐานความปลอดภัยตามระเบียบสารบรรณฯ</p>
              <p class="text-blue-800 text-[11px] mt-0.5">
                แพ็กเกจเอกสารอิเล็กทรอนิกส์จะทำการปิดผนึกด้วย Cryptographic SHA-512 และบันทึกลงสู่ Central Audit Log เพื่อเป็นพยานหลักฐานที่เปลี่ยนแปลงไม่ได้
              </p>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex items-center justify-end gap-3">
          <button
            type="button"
            @click="close"
            :disabled="loading"
            class="px-4 py-2.5 border border-gray-300 text-gray-700 hover:bg-gray-100 rounded-xl text-sm font-semibold transition-colors disabled:opacity-50"
          >
            ยกเลิก
          </button>
          <button
            type="button"
            @click="handleSubmit"
            :disabled="loading || !form.target_agency_name || (form.method === 'webhook' && (!form.webhook_url || !form.secret_key))"
            class="px-5 py-2.5 bg-[#800000] hover:bg-[#600000] text-white rounded-xl text-sm font-semibold shadow-sm transition-all flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <svg v-if="loading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span>{{ loading ? 'กำลังประมวลผล…' : (form.method === 'download' ? 'สร้างและดาวน์โหลดแพ็กเกจ' : 'ส่งหนังสือผ่าน Webhook') }}</span>
          </button>
        </div>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { ref, reactive } from 'vue'
import api from '../../axios'

const props = defineProps({
  isOpen: { type: Boolean, default: false },
  document: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['close', 'success'])

const loading = ref(false)

const form = reactive({
  target_agency_name: '',
  method: 'download',
  webhook_url: '',
  secret_key: '',
  note: '',
})

const close = () => {
  if (!loading.value) {
    emit('close')
  }
}

const handleSubmit = async () => {
  if (!props.document?.id) return

  loading.value = true
  try {
    if (form.method === 'download') {
      const response = await api.get(`/saraban/documents/${props.document.id}/dispatch-package`, {
        params: {
          target_agency_name: form.target_agency_name,
          note: form.note || '',
        },
        responseType: 'blob',
      })
      const blobUrl = window.URL.createObjectURL(new Blob([response.data]))
      const link = document.createElement('a')
      link.href = blobUrl
      link.setAttribute('download', `saraban_package_${props.document.id}.zip`)
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(blobUrl)
      emit('success', { method: 'download', message: 'ดาวน์โหลดแพ็กเกจหนังสือสำเร็จ' })
      close()
    } else {
      const response = await api.post(`/saraban/documents/${props.document.id}/dispatch-webhook`, {
        target_agency_name: form.target_agency_name,
        webhook_url: form.webhook_url,
        secret_key: form.secret_key,
        note: form.note,
      })
      emit('success', { method: 'webhook', data: response.data })
      close()
    }
  } catch (err) {
    console.error('Dispatch error:', err)
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.2s ease;
}
.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}
</style>
