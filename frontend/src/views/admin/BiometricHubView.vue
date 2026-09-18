<template>
  <div class="max-w-5xl mx-auto space-y-6" aria-labelledby="biometric-hub-title">
    <!-- Header -->
    <header class="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-3">
      <div>
        <h1 id="biometric-hub-title" class="text-2xl font-bold text-gray-900">นำเข้าข้อมูลเครื่องสแกนนิ้วและใบหน้า (Biometric Hub)</h1>
        <p class="text-sm text-gray-500 mt-1">
          อัปโหลดไฟล์บันทึกเวลาจากเครื่องสแกน (ZKTeco, HIP, Hikvision) เพื่อประมวลผลเวลาเข้า-ออกงานอัตโนมัติ
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <router-link
          to="/admin/attendances/summary"
          class="inline-flex items-center gap-2 px-4 py-2 border border-gray-200 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-xl transition-colors shadow-sm"
        >
          สรุปเวลาปฏิบัติงาน
        </router-link>
        <router-link
          to="/admin/attendances"
          class="inline-flex items-center gap-2 px-4 py-2 bg-[#800000] hover:bg-[#660000] text-white text-sm font-semibold rounded-xl transition-colors shadow-sm"
        >
          การลงเวลาบุคลากร
        </router-link>
      </div>
    </header>

    <!-- Upload & Drag-and-Drop Card -->
    <section class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm space-y-6">
      <div class="flex items-center justify-between border-b border-gray-100 pb-4">
        <div>
          <h2 class="text-base font-semibold text-gray-800">อัปโหลดไฟล์บันทึกเวลาสแกน</h2>
          <p class="text-xs text-gray-500">รองรับไฟล์รูปแบบ .DAT, .TXT, .CSV, .XLSX จาก Flash Drive หรือโปรแกรมสแกน</p>
        </div>
        <div class="flex items-center gap-2 text-xs text-gray-500">
          <span>เขตเวลา:</span>
          <select v-model="timezone" class="border-gray-200 rounded-lg text-xs py-1 px-2 focus:ring-[#800000] focus:border-[#800000]">
            <option value="Asia/Bangkok">Asia/Bangkok (UTC+7)</option>
            <option value="UTC">UTC (สากล)</option>
          </select>
        </div>
      </div>

      <!-- Drag & Drop Zone -->
      <div
        class="border-2 border-dashed rounded-2xl p-8 text-center transition-all cursor-pointer"
        :class="isDragging
          ? 'border-[#800000] bg-red-50/40'
          : selectedFile
            ? 'border-emerald-400 bg-emerald-50/20'
            : 'border-gray-300 hover:border-gray-400 bg-gray-50/50'"
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop.prevent="handleDrop"
        @click="triggerFileInput"
      >
        <input
          ref="fileInput"
          type="file"
          accept=".dat,.txt,.csv,.xlsx,.xls"
          class="hidden"
          @change="handleFileSelect"
        >

        <div v-if="!selectedFile" class="space-y-3">
          <div class="w-12 h-12 rounded-full bg-red-100 text-[#800000] flex items-center justify-center mx-auto">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
            </svg>
          </div>
          <div>
            <p class="text-sm font-semibold text-gray-800">ลากไฟล์มาวางที่นี่ หรือคลิกเพื่อเลือกไฟล์</p>
            <p class="text-xs text-gray-500 mt-1">ไฟล์ Log จากเครื่อง ZKTeco (เช่น attendance.dat), HIP หรือไฟล์ Excel สรุปเวลา</p>
          </div>
        </div>

        <div v-else class="space-y-3">
          <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center mx-auto">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </div>
          <div>
            <p class="text-sm font-bold text-gray-900">{{ selectedFile.name }}</p>
            <p class="text-xs text-gray-500 mt-0.5">ขนาดไฟล์: {{ formatFileSize(selectedFile.size) }}</p>
          </div>
          <button
            type="button"
            class="text-xs text-red-600 hover:text-red-800 underline font-medium"
            @click.stop="clearSelectedFile"
          >
            เลือกไฟล์อื่น
          </button>
        </div>
      </div>

      <!-- Action Button & Upload Progress -->
      <div class="flex flex-col sm:flex-row items-center justify-between gap-4 pt-2">
        <div class="text-xs text-gray-500">
          <span class="font-medium text-gray-700">คำแนะนำ:</span>
          ระบบจะตัดข้อมูลสแกนซ้ำซ้อน (Smart De-duplication) และนำเข้าเวลาเข้า-ออกงานลงสู่บัญชีลงเวลาทันที
        </div>
        <button
          type="button"
          :disabled="!selectedFile || isUploading"
          class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-2.5 bg-[#800000] hover:bg-[#660000] disabled:bg-gray-300 disabled:cursor-not-allowed text-white text-sm font-semibold rounded-xl transition-all shadow-sm"
          @click="uploadFile"
        >
          <svg v-if="isUploading" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          {{ isUploading ? 'กำลังประมวลผลข้อมูล...' : 'เริ่มประมวลผลและนำเข้าเวลา' }}
        </button>
      </div>
    </section>

    <!-- Import Result Banner & Analytics -->
    <section v-if="importResult" class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm space-y-6">
      <header class="flex items-center justify-between border-b border-gray-100 pb-4">
        <div>
          <h3 class="text-base font-bold text-gray-900">ผลการนำเข้าและประมวลผลข้อมูลเวลา</h3>
          <p class="text-xs text-gray-500 mt-0.5">ไฟล์: {{ importResult.filename }}</p>
        </div>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800">
          ประมวลผลสำเร็จ
        </span>
      </header>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <article class="rounded-xl border border-gray-100 bg-gray-50/50 p-4">
          <p class="text-xs text-gray-500 font-medium">บันทึกเวลาทั้งหมดในไฟล์</p>
          <p class="mt-1 text-2xl font-bold text-gray-900">{{ importResult.total_events || 0 }} <span class="text-xs font-normal text-gray-500">รายการ</span></p>
        </article>

        <article class="rounded-xl border border-emerald-100 bg-emerald-50/40 p-4">
          <p class="text-xs text-emerald-700 font-medium">นำเข้าและคำนวณเวลาสำเร็จ</p>
          <p class="mt-1 text-2xl font-bold text-emerald-700">{{ importResult.imported || 0 }} <span class="text-xs font-normal text-emerald-600">รายการ</span></p>
        </article>

        <article class="rounded-xl border border-amber-100 bg-amber-50/40 p-4">
          <p class="text-xs text-amber-700 font-medium">ข้าม / ไม่พบบุคลากรตรงรหัส</p>
          <p class="mt-1 text-2xl font-bold text-amber-700">{{ importResult.skipped || 0 }} <span class="text-xs font-normal text-amber-600">รายการ</span></p>
        </article>
      </div>

      <div class="rounded-xl bg-blue-50 border border-blue-100 p-4 flex items-start gap-3 text-xs text-blue-900">
        <svg class="w-5 h-5 text-blue-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <div>
          <p class="font-semibold">ระบบได้คำนวณการเข้า-ออกงานเรียบร้อยแล้ว</p>
          <p class="mt-0.5 text-blue-800">
            ข้อมูลเวลาถูกส่งต่อไปยังบัญชีลงเวลาปฏิบัติราชการ และรายงานประเมินเลื่อนขั้นเงินเดือน ก.ค.ศ. ทันที
          </p>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { getErrorMessage } from '../../utils/errorHandler'

const alerts = useAlertStore()

const fileInput = ref(null)
const selectedFile = ref(null)
const isDragging = ref(false)
const isUploading = ref(false)
const timezone = ref('Asia/Bangkok')
const importResult = ref(null)

const triggerFileInput = () => {
  fileInput.value?.click()
}

const handleFileSelect = (event) => {
  const files = event.target.files
  if (files && files[0]) {
    selectedFile.value = files[0]
  }
}

const handleDrop = (event) => {
  isDragging.value = false
  const files = event.dataTransfer?.files
  if (files && files[0]) {
    selectedFile.value = files[0]
  }
}

const clearSelectedFile = () => {
  selectedFile.value = null
  if (fileInput.value) {
    fileInput.value.value = ''
  }
}

const formatFileSize = (bytes) => {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

const uploadFile = async () => {
  if (!selectedFile.value) return

  isUploading.value = true
  importResult.value = null

  try {
    const formData = new FormData()
    formData.append('file', selectedFile.value)
    formData.append('timezone', timezone.value)

    const { data } = await api.post('/v1/attendance/biometric/import-file', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })

    importResult.value = data.data || data
    alerts.success('นำเข้าไฟล์บันทึกเวลาสำเร็จเรียบร้อยแล้ว')
  } catch (err) {
    alerts.error(getErrorMessage(err, 'ไม่สามารถนำเข้าไฟล์บันทึกเวลาได้'))
  } finally {
    isUploading.value = false
  }
}
</script>
