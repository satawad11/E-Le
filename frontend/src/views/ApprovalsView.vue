<template>
  <div>
    <div class="mb-8">
      <h1 class="text-2xl font-bold text-gray-900">{{ isCancel ? 'อนุมัติยกเลิกใบลา' : 'อนุมัติใบลา' }}</h1>
      <p class="text-gray-500 mt-1">รายการใบลาที่รอการอนุมัติ{{ isCancel ? 'ยกเลิก' : '' }}จากหน่วยงานของท่าน</p>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <div v-else>
      <div class="mb-6 flex flex-col md:flex-row gap-4">
        <div class="relative flex-1">
          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          </div>
          <input v-model="searchQuery" type="text" placeholder="ค้นหาด้วยชื่อ, รหัสบัตรประชาชน หรือ ประเภทการลา..." class="w-full pl-10 pr-4 py-3 rounded-xl border border-gray-200 focus:outline-none focus:ring-2 focus:ring-[#800000]/20 focus:border-[#800000] transition-colors shadow-sm">
        </div>
      </div>

      <div v-if="filteredApprovals.length === 0" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-12 text-center">
        <svg class="mx-auto h-14 w-14 text-gray-200 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <h3 class="text-gray-700 font-semibold text-lg">ไม่พบรายการใบลา</h3>
        <p class="text-gray-400 text-sm mt-1">ไม่มีข้อมูลใบลาที่ตรงกับคำค้นหาของคุณ หรือยังไม่มีใบลาที่รอการอนุมัติ</p>
      </div>

      <div v-else class="space-y-4">
        <div v-for="leave in paginatedApprovals" :key="leave.id"
          class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 hover:shadow-md transition-shadow">
          <div class="flex flex-col md:flex-row md:items-start gap-4">
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
                </div>
                <div v-if="leave.attachment" class="mt-2">
                  <a :href="attachmentUrl(leave.attachment)" target="_blank" class="text-xs text-[#800000] hover:underline flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                    ดูเอกสารแนบ
                  </a>
                </div>
              </div>
            </div>
            <!-- Action buttons -->
            <div class="flex gap-2 md:flex-col xl:flex-row">
              <template v-if="leave.cancellation_status === 'Pending'">
                <button @click="handleApproveCancel(leave.id)" class="flex-1 md:flex-none px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white text-xs font-semibold rounded-xl">
                  อนุมัติยกเลิก
                </button>
                <button @click="handleRejectCancel(leave.id)" class="flex-1 md:flex-none px-4 py-2 border border-red-200 text-red-600 hover:bg-red-50 text-xs font-semibold rounded-xl">
                  ปฏิเสธการยกเลิก
                </button>
              </template>
              <template v-else>
                <button v-if="authStore.isHRHead && !leave.hr_verified_at" @click="handleHrVerify(leave.id)" class="flex-1 md:flex-none px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold rounded-xl">
                  ตรวจสอบสถิติแล้ว
                </button>
                <button @click="handleApprove(leave.id)"
                  class="flex-1 md:flex-none flex items-center justify-center gap-1.5 px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                  อนุมัติ
                </button>
                <button @click="handleReject(leave.id)"
                  class="flex-1 md:flex-none flex items-center justify-center gap-1.5 px-4 py-2 bg-white hover:bg-red-50 text-red-600 text-sm font-semibold rounded-xl border-2 border-red-200 hover:border-red-400 transition-colors">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                  ปฏิเสธ
                </button>
              </template>
            </div>
          </div>
        </div>
        
        <!-- Pagination -->
        <div v-if="totalPages > 1" class="flex justify-center items-center gap-2 mt-8 pb-4">
          <button @click="prevPage" :disabled="currentPage === 1" class="p-2 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          </button>
          <div class="flex items-center gap-1">
            <button v-for="p in totalPages" :key="p" @click="currentPage = p" :class="['w-10 h-10 rounded-lg text-sm font-semibold transition-colors', currentPage === p ? 'bg-[#800000] text-white' : 'text-gray-600 hover:bg-gray-100']">
              {{ p }}
            </button>
          </div>
          <button @click="nextPage" :disabled="currentPage === totalPages" class="p-2 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Comment modal -->
    <div v-if="modal.show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
        <h3 class="font-bold text-gray-900 text-lg mb-4">
          {{ modal.isCancelAction 
              ? (modal.isApprove ? 'ยืนยันอนุมัติยกเลิก' : 'ปฏิเสธการยกเลิก') 
              : (modal.isApprove ? 'ยืนยันการอนุมัติ' : 'ระบุเหตุผลการปฏิเสธ') }}
        </h3>
        
        <!-- Signature Block -->
        <div v-if="modal.isApprove && !modal.isCancelAction" class="mb-4">
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
                <img v-if="authStore.user?.profile?.signature_path" :src="getImageUrl(authStore.user.profile.signature_path)" class="max-h-full object-contain p-1" />
                <span v-else class="text-gray-400 text-xs">ไม่พบลายเซ็นในโปรไฟล์</span>
              </div>
              <div v-else class="w-full flex flex-col items-center">
                <canvas ref="sigCanvas" width="300" height="100" class="border border-gray-300 bg-white rounded shadow-inner cursor-crosshair touch-none w-full"
                  @mousedown="startDraw" @mousemove="draw" @mouseup="endDraw" @mouseout="endDraw"
                  @touchstart="startDrawTouch" @touchmove="drawTouch" @touchend="endDraw"></canvas>
                <div class="mt-2 flex gap-2 w-full justify-between items-center">
                  <button type="button" @click="clearCanvas" class="px-2 py-1 text-xs font-semibold bg-gray-200 hover:bg-gray-300 text-gray-700 rounded transition-colors">ล้างลายเซ็น</button>
                  <span v-if="canvasSaved" class="text-xs text-green-600 flex items-center gap-1"><i class="fas fa-check-circle"></i> วาดเสร็จแล้ว</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <textarea v-model="modal.comment" rows="3"
          :placeholder="modal.isApprove ? 'ความเห็น (ไม่บังคับ)' : 'ระบุเหตุผลที่ปฏิเสธ (ไม่บังคับ)'"
          class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:outline-none focus:border-[#800000] transition-colors text-sm resize-none">
        </textarea>
        <div class="flex gap-3 mt-4">
          <button @click="modal.show = false" class="flex-1 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl text-sm transition-colors">ยกเลิก</button>
          <button @click="confirmAction" :class="['flex-1 py-2.5 text-white font-semibold rounded-xl text-sm transition-colors', modal.isApprove ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700']">
            {{ modal.isApprove ? 'อนุมัติ' : 'ปฏิเสธ' }}
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
import { ThaiDateFormatter } from '../utils/ThaiDateFormatter'
import { useAlertStore } from '../stores/alert'
import api from '../axios'

const leaveStore = useLeaveStore()
const authStore = useAuthStore()
const alertStore = useAlertStore()
const loading = ref(true)
const modal = reactive({ show: false, isApprove: false, isCancelAction: false, leaveId: null, comment: '' })

const sigType = ref('profile')
const sigCanvas = ref(null)
const sigBase64 = ref(null)
let isDrawing = false
let ctx = null
const canvasSaved = ref(false)

const getImageUrl = (path) => {
  if (!path) return '';
  if (path.startsWith('http')) return path;
  const base = api.defaults.baseURL?.replace('/api', '') || '';
  return `${base}${path.startsWith('/') ? '' : '/'}${path}`;
}

const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = 10

const props = defineProps({
  isCancel: {
    type: Boolean,
    default: false
  }
})

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

// สร้าง URL เต็มสำหรับไฟล์แนบ
const attachmentUrl = (path) => {
  const base = api.defaults.baseURL?.replace('/api', '') || ''
  return `${base}/storage/${path}`
}

onMounted(() => loadApprovals())

const loadApprovals = async () => {
  loading.value = true
  try { await leaveStore.fetchApprovals(authStore.user?.id) }
  catch (e) { console.error(e) }
  finally { loading.value = false }
}

const handleApprove = (id) => {
  modal.leaveId = id; modal.isApprove = true; modal.comment = ''; modal.isCancelAction = false; modal.show = true
}
const handleReject = (id) => {
  modal.leaveId = id; modal.isApprove = false; modal.comment = ''; modal.isCancelAction = false; modal.show = true
}

const confirmAction = async () => {
  modal.show = false
  try {
    if (modal.isCancelAction) {
      if (modal.isApprove) {
        await leaveStore.approveCancellation(modal.leaveId, modal.comment)
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
  } catch (error) {
    alertStore.error(error.response?.data?.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่')
  }
}

const handleHrVerify = async (id) => {
  try {
    await leaveStore.hrVerify(id)
    alertStore.success('บันทึกการตรวจสอบสถิติแล้ว')
    await loadApprovals()
  } catch (error) {
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
