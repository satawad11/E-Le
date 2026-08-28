<template>
  <div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <!-- Header -->
      <div class="bg-[#800000] px-4 sm:px-6 py-6 sm:py-8 text-center text-white relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-b from-white/10 to-transparent"></div>
        <div class="relative z-10">
          <h2 class="text-2xl sm:text-3xl font-bold mb-2">ลงเวลาปฏิบัติงาน</h2>
          <p class="text-white/80 text-sm sm:text-base">ระบบลงเวลาเข้า-ออกงานออนไลน์</p>
        </div>
      </div>

      <!-- Main Content -->
      <div class="p-4 sm:p-6 md:p-8">
        <!-- Current Time Display -->
        <div class="text-center mb-8 sm:mb-10">
          <div class="text-4xl sm:text-5xl md:text-6xl font-bold text-gray-800 tracking-tight font-mono">
            {{ currentTime }}
          </div>
          <div class="text-gray-500 mt-2 text-sm sm:text-base md:text-lg">
            {{ currentDate }}
          </div>
        </div>

        <!-- Location Status -->
        <div class="bg-gray-50 rounded-xl p-4 mb-8 flex items-center gap-4">
          <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0"
               :class="locationStatusClass">
            <svg v-if="location" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </div>
          <div>
            <p class="font-medium text-gray-900">{{ locationStatusText }}</p>
            <p v-if="location" class="text-sm text-gray-500 text-xs mt-0.5">{{ location }}</p>
          </div>
          <button @click="getLocation" v-if="!location && !gettingLocation" 
                  class="ml-auto text-sm text-[#800000] hover:text-[#600000] font-medium">
            ดึงพิกัด
          </button>
        </div>

        <!-- Notes Input -->
        <div class="mb-8">
          <label for="checkin-notes" class="block text-sm font-medium text-gray-700 mb-2">หมายเหตุ (ถ้ามี)</label>
          <textarea id="checkin-notes" v-model="notes" rows="2" 
                    class="w-full border-gray-300 rounded-xl shadow-sm focus:border-[#800000] focus:ring-[#800000] text-sm focus-visible:ring-2 focus-visible:ring-[#800000]"
                    placeholder="เช่น ทำงานนอกสถานที่, ลืมลงเวลาเมื่อเช้า"></textarea>
        </div>

        <!-- Camera Preview -->
        <div v-if="requireSelfie" class="mb-8">
          <label class="block text-sm font-medium text-gray-700 mb-2">ถ่ายรูปยืนยันตัวตน (บังคับ)</label>
          <div class="relative bg-black rounded-xl overflow-hidden aspect-[4/3] flex items-center justify-center">
            <video ref="videoRef" class="w-full h-full object-cover" autoplay playsinline muted aria-label="การแสดงภาพสดจากกล้อง"></video>
            <div v-if="!cameraStream && !cameraError" class="absolute text-white animate-pulse">กำลังเปิดกล้อง...</div>
            <div v-if="cameraError" class="absolute text-red-500 bg-black/50 p-4 text-center">{{ cameraError }}</div>
          </div>
          <canvas ref="canvasRef" class="hidden" aria-hidden="true"></canvas>
        </div>

        <!-- Action Buttons -->
        <div class="grid grid-cols-2 gap-4 mb-4">
          <button @click="handleCheckIn" 
                  :disabled="loading || !location || hasCheckedIn"
                  aria-label="ลงเวลาเข้างานปกติ"
                  class="flex flex-col items-center justify-center p-6 rounded-2xl border-2 transition-all duration-200 focus-visible:ring-2 focus-visible:ring-green-600 focus:outline-none"
                  :class="loading || !location || hasCheckedIn ? 'opacity-50 cursor-not-allowed border-gray-200 bg-gray-50 text-gray-400' : 'border-green-100 bg-green-50 text-green-700 hover:bg-green-100 hover:border-green-200'">
            <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mb-3">
              <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
              </svg>
            </div>
            <span class="font-bold text-lg">เข้างาน</span>
          </button>

          <button @click="handleCheckOut" 
                  :disabled="loading || !location || !hasCheckedIn || hasCheckedOut"
                  aria-label="ลงเวลาออกงานปกติ"
                  class="flex flex-col items-center justify-center p-6 rounded-2xl border-2 transition-all duration-200 focus-visible:ring-2 focus-visible:ring-orange-600 focus:outline-none"
                  :class="loading || !location || !hasCheckedIn || hasCheckedOut ? 'opacity-50 cursor-not-allowed border-gray-200 bg-gray-50 text-gray-400' : 'border-orange-100 bg-orange-50 text-orange-700 hover:bg-orange-100 hover:border-orange-200'">
            <div class="w-16 h-16 rounded-full bg-orange-100 flex items-center justify-center mb-3">
              <svg class="w-8 h-8 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
              </svg>
            </div>
            <span class="font-bold text-lg">ออกงาน</span>
          </button>
        </div>

        <!--
          สถานะคำสั่ง OT (CLEANUP-02)
          หลังบ้านปฏิเสธการลงเวลา OT เมื่อไม่มีคำสั่งที่อนุมัติครอบเวลานี้
          เดิมผู้ใช้รู้ก็ต่อเมื่อกดปุ่มแล้วเจอข้อความปฏิเสธ
        -->
        <div
          v-if="otStatus"
          class="mb-4 rounded-xl border px-4 py-3 text-sm"
          :class="otStatus.can_check_in ? 'border-blue-100 bg-blue-50 text-blue-800' : 'border-gray-200 bg-gray-50 text-gray-600'"
        >
          <template v-if="otStatus.can_check_in">
            <span class="font-semibold">มีคำสั่งปฏิบัติงานล่วงเวลาในช่วงนี้</span>
            <span v-if="otStatus.order?.title"> — {{ otStatus.order.title }}</span>
            <span v-if="otStatus.order?.approved_start" class="block text-xs mt-0.5">
              ช่วงที่อนุมัติ {{ formatOtRange(otStatus.order) }}
            </span>
          </template>
          <template v-else>
            ยังไม่มีคำสั่งปฏิบัติงานล่วงเวลาที่อนุมัติแล้วครอบเวลานี้ — ลงเวลา OT ไม่ได้จนกว่าจะได้รับมอบหมาย
          </template>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <button @click="handleOtCheckIn"
                  :disabled="loading || !location || hasOtCheckedIn || otStatus?.can_check_in === false"
                  aria-label="ลงเวลาเข้างานล่วงเวลา (OT)"
                  class="flex flex-col items-center justify-center p-4 rounded-2xl border-2 transition-all duration-200 focus-visible:ring-2 focus-visible:ring-blue-600 focus:outline-none"
                  :class="loading || !location || hasOtCheckedIn || otStatus?.can_check_in === false ? 'opacity-50 cursor-not-allowed border-gray-200 bg-gray-50 text-gray-400' : 'border-blue-100 bg-blue-50 text-blue-700 hover:bg-blue-100 hover:border-blue-200'">
            <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center mb-2">
              <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <span class="font-bold">เข้างาน OT</span>
          </button>

          <button @click="handleOtCheckOut" 
                  :disabled="loading || !location || !hasOtCheckedIn || hasOtCheckedOut"
                  aria-label="ลงเวลาออกงานล่วงเวลา (OT)"
                  class="flex flex-col items-center justify-center p-4 rounded-2xl border-2 transition-all duration-200 focus-visible:ring-2 focus-visible:ring-purple-600 focus:outline-none"
                  :class="loading || !location || !hasOtCheckedIn || hasOtCheckedOut ? 'opacity-50 cursor-not-allowed border-gray-200 bg-gray-50 text-gray-400' : 'border-purple-100 bg-purple-50 text-purple-700 hover:bg-purple-100 hover:border-purple-200'">
            <div class="w-12 h-12 rounded-full bg-purple-100 flex items-center justify-center mb-2">
              <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <span class="font-bold">ออกงาน OT</span>
          </button>
        </div>

      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { getErrorMessage } from '../../utils/errorHandler'

const alertStore = useAlertStore()

const currentTime = ref('')
const currentDate = ref('')
let timer = null

const loading = ref(false)
const gettingLocation = ref(false)
const location = ref('')
const notes = ref('')

const todayAttendance = ref(null)
const requireSelfie = ref(false)
const videoRef = ref(null)
const canvasRef = ref(null)
const cameraStream = ref(null)
const cameraError = ref('')

const hasCheckedIn = computed(() => !!todayAttendance.value?.check_in_time)
const hasCheckedOut = computed(() => !!todayAttendance.value?.check_out_time)
const hasOtCheckedIn = computed(() => !!todayAttendance.value?.ot_check_in_time)
const hasOtCheckedOut = computed(() => !!todayAttendance.value?.ot_check_out_time)

const fetchTodayAttendance = async () => {
  try {
    const res = await api.get('/attendances/today')
    todayAttendance.value = res.data
  } catch (e) {
    console.error('Failed to fetch today attendance:', e)
  }
}

/*
 * สถานะคำสั่ง OT ของฉันตอนนี้ (CLEANUP-02)
 *
 * ผู้ใช้ที่ไม่มีสิทธิ์ OT จะถูกปฏิเสธที่ endpoint — ถือว่าไม่มีสถานะให้แสดง ไม่ใช่ข้อผิดพลาด
 */
const otStatus = ref(null)

const fetchOtStatus = async () => {
  try {
    const res = await api.get('/overtime/my-active')
    otStatus.value = res.data
  } catch {
    otStatus.value = null
  }
}

const formatOtRange = (order) => {
  if (!order?.approved_start) return ''
  const time = (value) => new Date(value).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })
  return `${time(order.approved_start)} – ${time(order.approved_end)} น.`
}

const fetchSettings = async () => {
  try {
    const res = await api.get('/settings')
    if (res.data.require_attendance_selfie === '1') {
      requireSelfie.value = true
      startCamera()
    }
  } catch (e) {
    console.error('Failed to fetch settings:', e)
  }
}

const startCamera = async () => {
  try {
    cameraError.value = ''
    const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
    cameraStream.value = stream
    if (videoRef.value) {
      videoRef.value.srcObject = stream
    }
  } catch (err) {
    console.error('Camera error:', err)
    cameraError.value = 'ไม่สามารถเปิดกล้องได้ กรุณาอนุญาตให้ใช้งานกล้อง'
  }
}

const stopCamera = () => {
  if (cameraStream.value) {
    cameraStream.value.getTracks().forEach(track => track.stop())
    cameraStream.value = null
  }
}

const captureImage = () => {
  if (!videoRef.value || !canvasRef.value) return null
  const video = videoRef.value
  const canvas = canvasRef.value
  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  const ctx = canvas.getContext('2d')
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
  return canvas.toDataURL('image/jpeg', 0.8)
}

const updateTime = () => {
  const now = new Date()
  currentTime.value = now.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
  currentDate.value = now.toLocaleDateString('th-TH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
}

const locationStatusClass = computed(() => {
  if (gettingLocation.value) return 'bg-yellow-100 text-yellow-600 animate-pulse'
  if (location.value) return 'bg-green-100 text-green-600'
  return 'bg-gray-200 text-gray-500'
})

const locationStatusText = computed(() => {
  if (gettingLocation.value) return 'กำลังดึงพิกัด...'
  if (location.value) return 'ได้รับพิกัดแล้ว'
  return 'ยังไม่ได้รับพิกัด'
})

const getLocation = () => {
  if (!navigator.geolocation) {
    alertStore.addAlert('เบราว์เซอร์ของคุณไม่รองรับการดึงพิกัด GPS', 'error')
    return
  }

  gettingLocation.value = true
  navigator.geolocation.getCurrentPosition(
    (position) => {
      location.value = `${position.coords.latitude},${position.coords.longitude}`
      gettingLocation.value = false
    },
    (error) => {
      console.error(error)
      gettingLocation.value = false
      alertStore.addAlert('ไม่สามารถดึงพิกัด GPS ได้ กรุณาอนุญาตการเข้าถึงตำแหน่งที่ตั้ง', 'error')
    },
    { enableHighAccuracy: true }
  )
}

const handleCheckIn = async () => {
  if (!location.value) {
    alertStore.addAlert('กรุณาดึงพิกัดก่อนลงเวลา', 'warning')
    return
  }

  let imageData = null
  if (requireSelfie.value) {
    imageData = captureImage()
    if (!imageData) {
      alertStore.addAlert('กรุณาเปิดกล้องเพื่อถ่ายรูปยืนยันตัวตน', 'warning')
      return
    }
  }

  loading.value = true
  try {
    const res = await api.post('/attendances/check-in', {
      location: location.value,
      notes: notes.value,
      image: imageData
    })
    const att = res.data.attendance
    let msg = res.data.message || 'ลงเวลาเข้างานสำเร็จ'
    if (att && att.late_minutes > 0) {
      msg += ` (คุณมาสาย ${att.late_minutes} นาที)`
      alertStore.addAlert(msg, 'warning')
    } else {
      alertStore.addAlert(msg, 'success')
    }
    notes.value = ''
    await fetchTodayAttendance()
  } catch (error) {
    alertStore.addAlert(getErrorMessage(error, 'เกิดข้อผิดพลาดในการลงเวลา'), 'error')
  } finally {
    loading.value = false
  }
}

const handleCheckOut = async () => {
  if (!location.value) {
    alertStore.addAlert('กรุณาดึงพิกัดก่อนลงเวลา', 'warning')
    return
  }

  let imageData = null
  if (requireSelfie.value) {
    imageData = captureImage()
    if (!imageData) {
      alertStore.addAlert('กรุณาเปิดกล้องเพื่อถ่ายรูปยืนยันตัวตน', 'warning')
      return
    }
  }

  loading.value = true
  try {
    const res = await api.post('/attendances/check-out', {
      location: location.value,
      notes: notes.value,
      image: imageData
    })
    const att = res.data.attendance
    let msg = res.data.message || 'ลงเวลาออกงานสำเร็จ'
    if (att && att.early_leave_minutes > 0) {
      msg += ` (คุณออกก่อนเวลา ${att.early_leave_minutes} นาที)`
      alertStore.addAlert(msg, 'warning')
    } else {
      alertStore.addAlert(msg, 'success')
    }
    notes.value = ''
    await fetchTodayAttendance()
  } catch (error) {
    alertStore.addAlert(getErrorMessage(error, 'เกิดข้อผิดพลาดในการลงเวลา'), 'error')
  } finally {
    loading.value = false
  }
}

const handleOtCheckIn = async () => {
  if (!location.value) {
    alertStore.addAlert('กรุณาดึงพิกัดก่อนลงเวลา', 'warning')
    return
  }

  loading.value = true
  try {
    const res = await api.post('/attendances/ot-check-in', {
      notes: notes.value
    })
    alertStore.addAlert(res.data.message || 'ลงเวลาเข้า OT สำเร็จ', 'success')
    notes.value = ''
    await fetchTodayAttendance()
  } catch (error) {
    alertStore.addAlert(getErrorMessage(error, 'เกิดข้อผิดพลาดในการลงเวลา OT'), 'error')
  } finally {
    loading.value = false
  }
}

const handleOtCheckOut = async () => {
  if (!location.value) {
    alertStore.addAlert('กรุณาดึงพิกัดก่อนลงเวลา', 'warning')
    return
  }

  loading.value = true
  try {
    const res = await api.post('/attendances/ot-check-out', {
      notes: notes.value
    })
    const att = res.data.attendance
    let msg = res.data.message || 'ลงเวลาออก OT สำเร็จ'
    if (att && att.ot_minutes > 0) {
      const hours = Math.floor(att.ot_minutes / 60)
      const mins = att.ot_minutes % 60
      msg += ` (ทำ OT ไป ${hours} ชั่วโมง ${mins} นาที)`
    }
    alertStore.addAlert(msg, 'success')
    notes.value = ''
    await fetchTodayAttendance()
  } catch (error) {
    alertStore.addAlert(getErrorMessage(error, 'เกิดข้อผิดพลาดในการลงเวลา OT'), 'error')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchTodayAttendance()
  fetchOtStatus()
  fetchSettings()
  updateTime()
  timer = setInterval(updateTime, 1000)
  getLocation() // Auto request location on load
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
  stopCamera()
})
</script>
