<template>
  <div>
    <div class="mb-8">
      <h1 class="text-2xl font-bold text-gray-900">{{ isCancel ? 'อนุมัติยกเลิกใบลา' : 'อนุมัติใบลา' }}</h1>
      <p class="text-gray-500 mt-1">รายการใบลาที่รอการอนุมัติ{{ isCancel ? 'ยกเลิก' : '' }}จากหน่วยงานของท่าน</p>
    </div>

    <div v-if="loading" class="py-8">
      <LoadingState type="skeleton-card" :rows="3" message="กำลังโหลดรายการรออนุมัติ..." />
    </div>

    <div v-else-if="loadError" class="py-6">
      <ErrorState :message="loadError" @retry="loadApprovals" />
    </div>

    <div v-else>
      <div class="mb-6 flex flex-col md:flex-row gap-4">
        <div class="relative flex-1">
          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          </div>
          <input aria-label="ค้นหาใบลาจากชื่อ เลขประจำตัวประชาชน หรือประเภทการลา" v-model="searchQuery" type="text" placeholder="ค้นหาด้วยชื่อ, รหัสบัตรประชาชน หรือ ประเภทการลา..." class="w-full pl-10 pr-4 py-3 rounded-xl border border-gray-200 focus:outline-none focus:ring-2 focus:ring-[#800000]/20 focus:border-[#800000] transition-colors shadow-sm">
        </div>
      </div>

      <!-- Bulk Action Bar (ปรากฏเมื่อเลือกรายการ) -->
      <transition name="slide-down">
        <div v-if="selectedIds.length > 0" class="mb-4 px-5 py-3 bg-[#800000] rounded-2xl flex items-center justify-between shadow-md">
          <span class="text-white text-sm font-semibold">เลือกแล้ว {{ selectedIds.length }} รายการ</span>
          <div class="flex items-center gap-2">
            <button @click="bulkApprove" class="px-5 py-2 bg-green-500 hover:bg-green-400 text-white text-sm font-bold rounded-xl shadow transition-colors flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
              อนุมัติที่เลือก ({{ selectedIds.length }})
            </button>
            <button @click="selectedIds = []" class="px-4 py-2 bg-white/20 hover:bg-white/30 text-white text-sm font-semibold rounded-xl transition-colors">
              ยกเลิกการเลือก
            </button>
          </div>
        </div>
      </transition>

      <!-- Select All checkbox header -->
      <div v-if="filteredApprovals.length > 0 && !isCancel" class="mb-3 flex items-center gap-3 px-2">
        <label class="flex items-center gap-2 text-sm text-gray-600 font-medium cursor-pointer">
          <input type="checkbox" :indeterminate.prop="selectedIds.length > 0 && selectedIds.length < filteredApprovals.length"
            :checked="selectedIds.length === filteredApprovals.length && filteredApprovals.length > 0"
            @change="toggleSelectAll" class="rounded text-[#800000] focus:ring-[#800000] w-4 h-4 focus-visible:ring-2 focus-visible:ring-[#800000]"
            aria-label="เลือกใบลาทั้งหมดในรายการนี้">
          เลือกทั้งหมด ({{ filteredApprovals.length }} รายการ)
        </label>
      </div>

      <EmptyState
        v-if="filteredApprovals.length === 0"
        :title="isCancel ? 'ไม่มีรายการขอยกเลิกใบลา' : 'ไม่มีรายการรออนุมัติ'"
        :description="isCancel ? 'ขณะนี้ไม่มีคำขอยกเลิกใบลาที่รอการพิจารณา' : 'ขณะนี้ไม่มีรายการใบลาที่รอการอนุมัติในหน่วยงานของคุณ'"
        :icon="ICONS.clipboardCheck"
      />

      <div v-else class="space-y-4">
        <div v-for="leave in paginatedApprovals" :key="leave.id"
          class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 hover:shadow-md transition-shadow">
          <div class="flex flex-col md:flex-row md:items-start gap-4">
            <!-- Checkbox for bulk select -->
            <div v-if="!isCancel" class="flex-shrink-0 pt-1">
              <input type="checkbox" :value="leave.id" v-model="selectedIds" class="w-4 h-4 rounded text-[#800000] focus:ring-[#800000] cursor-pointer focus-visible:ring-2 focus-visible:ring-[#800000]" :aria-label="`เลือกใบลาของ ${leave.user?.name}`">
            </div>
            <!-- Avatar + User info -->
            <div class="flex items-start gap-4 flex-1">
              <div class="w-12 h-12 bg-[#800000] text-white rounded-xl flex items-center justify-center font-bold text-xl flex-shrink-0">
                {{ leave.user?.name?.charAt(0) }}
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                  <h3 class="font-bold text-gray-900 text-base">{{ leave.user?.name }}</h3>
                  <span class="text-xs bg-[#800000]/10 text-[#800000] font-semibold px-2 py-0.5 rounded-full">{{ leave.type?.name }}</span>
                </div>
                <div class="flex flex-wrap gap-3 text-sm text-gray-500">
                  <span class="flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    {{ ThaiDateFormatter.toThaiSlash(leave.start_date) }} — {{ ThaiDateFormatter.toThaiSlash(leave.end_date) }}
                  </span>
                  <span class="flex items-center gap-1 font-semibold text-[#800000]">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    {{ leave.total_days }} วัน
                  </span>
                </div>
                <div class="mt-3 bg-gray-50 border border-gray-100 rounded-xl p-3 text-sm text-gray-700">
                  <span class="font-semibold text-gray-500 text-xs block mb-1">เหตุผล:</span>
                  {{ leave.reason }}
                </div>
                <!-- Cancellation Reason if pending -->
                <div v-if="leave.cancellation_status === 'Pending'" class="mt-2 bg-orange-50 border border-orange-200 rounded-xl p-3 text-sm text-orange-800">
                  <span class="font-semibold text-orange-600 text-xs block mb-1">เหตุผลการขอยกเลิกใบลา:</span>
                  {{ leave.cancellation_reason }}

                  <!-- สายอนุมัติของคำขอยกเลิก -->
                  <div v-if="leave.cancellation_approvals?.length" class="mt-3 pt-3 border-t border-orange-200 space-y-1.5">
                    <span class="font-semibold text-orange-600 text-xs block">ลำดับการอนุมัติยกเลิก:</span>
                    <div
                      v-for="step in [...leave.cancellation_approvals].sort((a, b) => a.step_order - b.step_order)"
                      :key="step.id"
                      class="flex items-center gap-2 text-xs"
                    >
                      <span
                        class="w-5 h-5 rounded-full flex items-center justify-center font-bold flex-shrink-0"
                        :class="cancellationStepClass(leave, step)"
                      >{{ step.step_order }}</span>
                      <span class="font-medium">{{ step.step_name }}</span>
                      <span class="text-orange-700">
                        {{ step.status === 'Approved' ? `— อนุมัติโดย ${step.approver_name || '-'}`
                          : step.status === 'Rejected' ? `— ไม่อนุมัติโดย ${step.approver_name || '-'}`
                          : isCurrentCancellationStep(leave, step) ? '— รอพิจารณาขั้นนี้' : '— ยังไม่ถึงคิว' }}
                      </span>
                    </div>
                  </div>
                </div>
                <div v-if="leave.attachment" class="mt-2">
                  <a href="#" @click.prevent="openSecureFile(leave.attachment)" class="text-xs text-[#800000] hover:underline flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                    ดูเอกสารแนบ
                  </a>
                </div>
              </div>
            </div>
            <!-- Action buttons -->
            <div class="flex flex-wrap sm:flex-nowrap gap-2 md:flex-col xl:flex-row w-full md:w-auto">
              <template v-if="leave.cancellation_status === 'Pending'">
                <button @click="handleApproveCancel(leave.id)" class="flex-1 md:flex-none px-4 py-2.5 bg-orange-500 hover:bg-orange-600 text-white text-xs sm:text-sm font-semibold rounded-xl min-h-[44px] flex items-center justify-center">
                  อนุมัติยกเลิก
                </button>
                <button @click="handleRejectCancel(leave.id)" class="flex-1 md:flex-none px-4 py-2.5 border border-red-200 text-red-600 hover:bg-red-50 text-xs sm:text-sm font-semibold rounded-xl min-h-[44px] flex items-center justify-center">
                  ปฏิเสธการยกเลิก
                </button>
              </template>
              <template v-else>
                <button v-if="authStore.isHRHead && !leave.hr_verified_at" @click="handleHrVerify(leave.id)" class="flex-1 md:flex-none px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-xs sm:text-sm font-semibold rounded-xl min-h-[44px] flex items-center justify-center">
                  ตรวจสอบสถิติแล้ว
                </button>
                <button @click="handleApprove(leave.id)"
                  class="flex-1 md:flex-none flex items-center justify-center gap-1.5 px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white text-sm font-bold rounded-xl transition-colors shadow-sm min-h-[44px]">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                  อนุมัติ
                </button>
                <button @click="handleReject(leave.id)"
                  class="flex-1 md:flex-none flex items-center justify-center gap-1.5 px-4 py-2.5 bg-white hover:bg-red-50 text-red-600 text-sm font-semibold rounded-xl border-2 border-red-200 hover:border-red-400 transition-colors min-h-[44px]">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                  ปฏิเสธ
                </button>
              </template>
            </div>
          </div>

          <!-- Workflow Timeline Toggle for Approvers -->
          <div class="mt-4 pt-3 border-t border-gray-100">
            <button
              type="button"
              @click="toggleTimeline(leave.id)"
              class="text-xs font-semibold text-[#800000] hover:text-[#600000] flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#800000] rounded py-1 px-1.5 transition-colors"
              :aria-expanded="expandedLeaveIds.includes(leave.id)"
            >
              <svg
                class="w-4 h-4 transition-transform duration-200"
                :class="expandedLeaveIds.includes(leave.id) ? 'rotate-180' : ''"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
              </svg>
              <span>{{ expandedLeaveIds.includes(leave.id) ? 'ซ่อนขั้นตอนและประวัติ' : 'ดูขั้นตอนการอนุมัติก่อนหน้าและลำดับถัดไป (Workflow Timeline)' }}</span>
            </button>

            <transition name="slide-down">
              <div v-if="expandedLeaveIds.includes(leave.id)" class="mt-4 pt-4 border-t border-dashed border-gray-200">
                <WorkflowTimeline :leave="leave" />
              </div>
            </transition>
          </div>
        </div>
        
        <!-- Pagination -->
        <div v-if="totalPages > 1" class="flex justify-center items-center gap-2 mt-8 pb-4" role="navigation" aria-label="แถบเปลี่ยนหน้า">
          <button @click="prevPage" :disabled="currentPage === 1" class="p-2 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed focus-visible:ring-2 focus-visible:ring-[#800000]" aria-label="ไปยังหน้าก่อนหน้า">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          </button>
          <div class="flex items-center gap-1">
            <button v-for="p in totalPages" :key="p" @click="currentPage = p" :class="['w-10 h-10 rounded-lg text-sm font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-[#800000]', currentPage === p ? 'bg-[#800000] text-white' : 'text-gray-600 hover:bg-gray-100']" :aria-label="`ไปยังหน้าที่ ${p}`" :aria-current="currentPage === p ? 'page' : undefined">
              {{ p }}
            </button>
          </div>
          <button @click="nextPage" :disabled="currentPage === totalPages" class="p-2 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed focus-visible:ring-2 focus-visible:ring-[#800000]" aria-label="ไปยังหน้าถัดไป">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Comment modal -->
    <div
      v-if="modal.show"
      role="dialog"
      aria-modal="true"
      aria-labelledby="approval-modal-title"
      @keydown.escape="modal.show = false"
      tabindex="-1"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4 focus:outline-none"
    >
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
        <h3 id="approval-modal-title" class="font-bold text-gray-900 text-lg mb-4">
          {{ modal.isCancelAction 
              ? (modal.isApprove ? 'ยืนยันอนุมัติยกเลิก' : 'ปฏิเสธการยกเลิก') 
              : (modal.isApprove ? 'ยืนยันการอนุมัติ' : 'ระบุเหตุผลการปฏิเสธ') }}
        </h3>
        
        <!-- Signature Block — ใช้ทั้งการอนุมัติใบลาและการอนุมัติคำขอยกเลิก -->
        <div v-if="modal.isApprove" class="mb-4">
          <label class="block text-sm font-semibold text-gray-700 mb-1.5">ลายเซ็นผู้อนุมัติ <span class="text-red-500">*</span></label>
          <div class="bg-gray-50 border border-gray-200 rounded-xl p-3 flex flex-col gap-3">
            <div class="flex gap-4 border-b border-gray-200 pb-2">
              <label class="flex items-center gap-2 text-sm cursor-pointer">
                <input type="radio" v-model="sigType" value="profile" class="text-green-600 focus:ring-green-500">
                ลายเซ็นโปรไฟล์
              </label>
              <label class="flex items-center gap-2 text-sm cursor-pointer">
                <input type="radio" v-model="sigType" value="draw" class="text-green-600 focus:ring-green-500">
                วาดใหม่
              </label>
            </div>
            
            <div class="flex justify-center">
              <div v-if="sigType === 'profile'" class="w-full h-20 bg-white border border-gray-200 rounded-lg flex items-center justify-center">
                <img v-if="authStore.user?.profile?.signature_path" v-secure-src="authStore.user.profile.signature_path" alt="รูปลายเซ็นจากโปรไฟล์ผู้อนุมัติ" class="max-h-full object-contain p-1" />
                <span v-else class="text-gray-400 text-xs">ไม่พบลายเซ็นในโปรไฟล์</span>
              </div>
              <div v-else class="w-full flex flex-col items-center">
                <canvas ref="sigCanvas" width="300" height="100" role="img" aria-label="กระดานวาดลายเซ็นผู้อนุมัติ" class="border border-gray-300 bg-white rounded shadow-inner cursor-crosshair touch-none w-full"
                  @mousedown="startDraw" @mousemove="draw" @mouseup="endDraw" @mouseout="endDraw"
                  @touchstart="startDrawTouch" @touchmove="drawTouch" @touchend="endDraw"></canvas>
                <div class="mt-2 flex gap-2 w-full justify-between items-center">
                  <button type="button" @click="clearCanvas" class="px-2 py-1 text-xs font-semibold bg-gray-200 hover:bg-gray-300 text-gray-700 rounded transition-colors focus-visible:ring-2 focus-visible:ring-[#800000]">ล้างลายเซ็น</button>
                  <span v-if="canvasSaved" class="text-xs text-green-600 flex items-center gap-1">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.check"/>
                    </svg>
                    วาดเสร็จแล้ว
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Quick Rejection Presets (แสดงเฉพาะกรณีปฏิเสธ) -->
        <div v-if="!modal.isApprove" class="mb-3">
          <p class="text-xs font-semibold text-gray-500 mb-2">เหตุผลด่วน:</p>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="preset in rejectionPresets"
              :key="preset"
              type="button"
              @click="modal.comment = preset"
              :class="['px-3 py-1.5 text-xs font-medium rounded-full border transition-colors focus-visible:ring-2 focus-visible:ring-red-500', modal.comment === preset ? 'bg-red-600 text-white border-red-600' : 'bg-white text-gray-600 border-gray-300 hover:border-red-400 hover:text-red-600']"
            >
              {{ preset }}
            </button>
          </div>
        </div>

        <textarea v-model="modal.comment" rows="3"
          :placeholder="modal.isApprove ? 'ความเห็น (ไม่บังคับ)' : 'ระบุเหตุผลที่ปฏิเสธ (ไม่บังคับ)'"
          aria-label="ความคิดเห็นหรือเหตุผลการพิจารณา"
          class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:outline-none focus:border-[#800000] transition-colors text-sm resize-none">
        </textarea>

        <div class="flex gap-3 mt-4">
          <button @click="modal.show = false" :disabled="isProcessingAction" class="flex-1 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl text-sm transition-colors disabled:opacity-50 focus-visible:ring-2 focus-visible:ring-gray-400">ยกเลิก</button>
          <button @click="confirmAction" :disabled="isProcessingAction" :class="['flex-1 py-2.5 text-white font-semibold rounded-xl text-sm transition-colors disabled:opacity-50 flex items-center justify-center gap-2 focus-visible:ring-2', modal.isApprove ? 'bg-green-600 hover:bg-green-700 focus-visible:ring-green-600' : 'bg-red-600 hover:bg-red-700 focus-visible:ring-red-600']">
            <span v-if="isProcessingAction" class="animate-spin rounded-full h-4 w-4 border-b-2 border-white" aria-hidden="true"></span>
            {{ modal.isApprove ? (isProcessingAction ? 'กำลังอนุมัติ...' : 'อนุมัติ') : (isProcessingAction ? 'กำลังปฏิเสธ...' : 'ปฏิเสธ') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useLeaveStore } from '../stores/leave'
import { useAuthStore } from '../stores/auth'
import { useAlertStore } from '../stores/alert'
import { useConfirm } from '../composables/useConfirm'
import { openSecureFile } from '../utils/secureFile'
import { ThaiDateFormatter } from '../utils/ThaiDateFormatter'
import { getErrorMessage } from '../utils/errorHandler'
import { ICONS } from '../utils/icons'
import LoadingState from '../components/ui/LoadingState.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import ErrorState from '../components/ui/ErrorState.vue'
import WorkflowTimeline from '../components/ui/WorkflowTimeline.vue'

const leaveStore = useLeaveStore()
const authStore = useAuthStore()
const alertStore = useAlertStore()
const { confirmAction: askToConfirm } = useConfirm()
const loading = ref(true)
const loadError = ref('')
const isProcessingAction = ref(false)
const modal = reactive({ show: false, isApprove: false, isCancelAction: false, leaveId: null, comment: '' })
const expandedLeaveIds = ref([])

function toggleTimeline(leaveId) {
  const index = expandedLeaveIds.value.indexOf(leaveId)
  if (index > -1) {
    expandedLeaveIds.value.splice(index, 1)
  } else {
    expandedLeaveIds.value.push(leaveId)
  }
}

const sigType = ref('profile')
const sigCanvas = ref(null)
const sigBase64 = ref(null)
let isDrawing = false
let ctx = null
const canvasSaved = ref(false)

const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = 10
const selectedIds = ref([])

const rejectionPresets = [
  'เอกสารแนบไม่สมบูรณ์/ไม่ชัดเจน',
  'ภารกิจด่วนในช่วงเวลาดังกล่าว',
  'ข้อมูลการลาซ้ำซ้อน',
  'พ้นกำหนดเวลาการยื่นลาตามระเบียบ'
]

const toggleSelectAll = (e) => {
  if (e.target.checked) {
    selectedIds.value = filteredApprovals.value.map(l => l.id)
  } else {
    selectedIds.value = []
  }
}

const bulkApprove = async () => {
  if (selectedIds.value.length === 0) return
  const confirmed = await askToConfirm({
    title: 'อนุมัติใบลาหลายรายการ',
    message: `คุณต้องการอนุมัติใบลาจำนวน ${selectedIds.value.length} รายการที่เลือกใช่หรือไม่?`,
    detail: 'ใบลาที่อนุมัติแล้วจะเดินต่อไปยังขั้นถัดไปของสายอนุมัติทันที',
    confirmText: 'อนุมัติทั้งหมด',
  })

  if (!confirmed) return
  
  loading.value = true
  let successCount = 0
  let failCount = 0
  
  for (const id of selectedIds.value) {
    try {
      await leaveStore.approveLeave(id, 'อนุมัติแบบกลุ่ม')
      successCount++
    } catch {
      failCount++
    }
  }
  
  selectedIds.value = []
  alertStore.success(`อนุมัติสำเร็จ ${successCount} รายการ${failCount > 0 ? ` (ล้มเหลว ${failCount} รายการ)` : ''}`)
  await loadApprovals()
}


const props = defineProps({
  isCancel: {
    type: Boolean,
    default: false
  }
})

const isCurrentCancellationStep = (leave, step) => {
  const level = leave.cancellation_approval_level || leave.approval_level
  return step.status === 'Pending' && step.step_order === level
}

const cancellationStepClass = (leave, step) => {
  if (step.status === 'Approved') return 'bg-green-600 text-white'
  if (step.status === 'Rejected') return 'bg-red-600 text-white'
  if (isCurrentCancellationStep(leave, step)) return 'bg-orange-500 text-white'
  return 'bg-orange-200 text-orange-700'
}

const displayApprovals = computed(() => {
  if (props.isCancel) {
    return leaveStore.approvals.filter(l => l.cancellation_status === 'Pending')
  }
  return leaveStore.approvals.filter(l => l.cancellation_status !== 'Pending')
})

const filteredApprovals = computed(() => {
  if (!searchQuery.value) return displayApprovals.value
  const query = searchQuery.value.toLowerCase()
  return displayApprovals.value.filter(leave => {
    const nameMatch = leave.user?.name?.toLowerCase().includes(query)
    const typeMatch = leave.type?.name?.toLowerCase().includes(query)
    const idCardMatch = leave.user?.profile?.id_card_number?.includes(query)
    return nameMatch || typeMatch || idCardMatch
  })
})

const totalPages = computed(() => Math.ceil(filteredApprovals.value.length / itemsPerPage) || 1)

const paginatedApprovals = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage
  return filteredApprovals.value.slice(start, start + itemsPerPage)
})

watch(searchQuery, () => {
  currentPage.value = 1
})

watch(sigType, (val) => {
  if (val === 'draw') {
    setTimeout(setupCanvas, 50)
  }
})

const setupCanvas = () => {
  if (sigCanvas.value) {
    ctx = sigCanvas.value.getContext('2d')
    ctx.lineWidth = 2
    ctx.lineCap = 'round'
    ctx.strokeStyle = '#000000'
  }
}

// Canvas Methods
const getCanvasPos = (evt) => {
  if (!sigCanvas.value) return { x: 0, y: 0 }
  const rect = sigCanvas.value.getBoundingClientRect()
  const x = (evt.clientX || evt.touches?.[0]?.clientX) - rect.left
  const y = (evt.clientY || evt.touches?.[0]?.clientY) - rect.top
  return { x, y }
}

const startDraw = (e) => {
  isDrawing = true
  canvasSaved.value = false
  sigBase64.value = null
  const { x, y } = getCanvasPos(e)
  if (ctx) {
    ctx.beginPath()
    ctx.moveTo(x, y)
  }
}

const startDrawTouch = (e) => {
  e.preventDefault()
  startDraw(e)
}

const draw = (e) => {
  if (!isDrawing || !ctx) return
  const { x, y } = getCanvasPos(e)
  ctx.lineTo(x, y)
  ctx.stroke()
}

const drawTouch = (e) => {
  e.preventDefault()
  draw(e)
}

const endDraw = () => {
  if (isDrawing && ctx) {
    ctx.closePath()
    isDrawing = false
    saveCanvas()
  }
}

const clearCanvas = () => {
  if (ctx && sigCanvas.value) {
    ctx.clearRect(0, 0, sigCanvas.value.width, sigCanvas.value.height)
    canvasSaved.value = false
    sigBase64.value = null
  }
}

const saveCanvas = () => {
  if (sigCanvas.value) {
    sigBase64.value = sigCanvas.value.toDataURL('image/png')
    canvasSaved.value = true
  }
}

const prevPage = () => { if (currentPage.value > 1) currentPage.value-- }
const nextPage = () => { if (currentPage.value < totalPages.value) currentPage.value++ }

onMounted(() => loadApprovals())

const loadApprovals = async () => {
  loading.value = true
  loadError.value = ''
  try {
    await leaveStore.fetchApprovals(authStore.user?.id)
  } catch (e) {
    console.error(e)
    loadError.value = getErrorMessage(e, 'ไม่สามารถโหลดรายการรออนุมัติได้')
  } finally {
    loading.value = false
  }
}

const handleApprove = (id) => {
  modal.leaveId = id; modal.isApprove = true; modal.comment = ''; modal.isCancelAction = false; modal.show = true
}
const handleReject = (id) => {
  modal.leaveId = id; modal.isApprove = false; modal.comment = ''; modal.isCancelAction = false; modal.show = true
}

const confirmAction = async () => {
  if (modal.isApprove && sigType.value === 'draw' && !sigBase64.value && !canvasSaved.value) {
    alertStore.error('กรุณาวาดลายเซ็นก่อนอนุมัติ')
    return
  }

  isProcessingAction.value = true
  try {
    if (modal.isCancelAction) {
      if (modal.isApprove) {
        const signature = sigType.value === 'draw' && sigBase64.value ? sigBase64.value : null
        await leaveStore.approveCancellation(modal.leaveId, modal.comment, signature)
        alertStore.success('อนุมัติยกเลิกใบลาแล้ว')
      } else {
        await leaveStore.rejectCancellation(modal.leaveId, modal.comment)
        alertStore.success('ปฏิเสธการยกเลิกใบลาแล้ว')
      }
    } else {
      if (modal.isApprove) {
        const payload = { comment: modal.comment }
        if (sigType.value === 'draw' && sigBase64.value) {
          payload.signature_base64 = sigBase64.value
        }
        await leaveStore.approveLeave(modal.leaveId, payload.comment, payload.signature_base64)
        alertStore.success('อนุมัติใบลาเรียบร้อยแล้ว')
      } else {
        await leaveStore.rejectLeave(modal.leaveId, modal.comment)
        alertStore.success('ปฏิเสธใบลาเรียบร้อยแล้ว')
      }
    }
    // Remove the approved/rejected leave from the list locally
    leaveStore.approvals = leaveStore.approvals.filter(
      l => l.id !== modal.leaveId
    )
    modal.show = false
  } catch (error) {
    const errors = error.response?.data?.errors
    if (errors) {
      alertStore.error(Object.values(errors).flat().join(' | '))
    } else {
      alertStore.error(error.response?.data?.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่')
    }
  } finally {
    isProcessingAction.value = false
  }
}

const handleHrVerify = async (id) => {
  try {
    await leaveStore.hrVerify(id)
    alertStore.success('บันทึกการตรวจสอบสถิติแล้ว')
    await loadApprovals()
  } catch {
    alertStore.error('เกิดข้อผิดพลาด')
  }
}

const handleApproveCancel = (id) => {
  modal.leaveId = id; modal.isApprove = true; modal.comment = ''; modal.isCancelAction = true; modal.show = true
}

const handleRejectCancel = (id) => {
  modal.leaveId = id; modal.isApprove = false; modal.comment = ''; modal.isCancelAction = true; modal.show = true
}
</script>
