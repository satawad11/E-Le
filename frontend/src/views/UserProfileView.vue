<template>
  <div>
    <div class="mb-8">
      <h1 class="text-2xl font-bold text-gray-900">โปรไฟล์ของฉัน</h1>
      <p class="text-gray-500 mt-1">ดูและแก้ไขข้อมูลส่วนตัวของคุณ</p>
    </div>

    <div v-if="loading" class="flex justify-center py-16">
      <div class="animate-spin h-10 w-10 rounded-full border-4 border-[#800000] border-t-transparent"></div>
    </div>

    <div v-else class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 max-w-4xl">
      <form @submit.prevent="submitProfile">
        <div class="flex flex-col md:flex-row gap-8 mb-8">
          <!-- Profile Picture -->
          <div class="w-full md:w-1/3 flex flex-col items-center">
            <div class="w-40 h-40 bg-gray-200 rounded-full flex items-center justify-center mb-4 overflow-hidden border-4 border-white shadow cursor-pointer group relative" @click="$refs.imageInput.click()">
              <img v-if="imagePreview" :src="imagePreview" class="w-full h-full object-cover" />
              <img v-else-if="form.profile.profile_photo_path" :src="getImageUrl(form.profile.profile_photo_path)" class="w-full h-full object-cover" />
              <svg v-else class="w-20 h-20 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path></svg>
              
              <div class="absolute inset-0 bg-black/40 flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                <i class="fas fa-camera text-white text-2xl mb-2"></i>
                <span class="text-white text-sm">อัปโหลดรูป</span>
              </div>
            </div>
            <input type="file" ref="imageInput" @change="onImageSelected" accept="image/jpeg, image/png, image/jpg" class="hidden" />
            <div class="text-center">
              <h2 class="text-lg font-bold text-gray-900">{{ form.name }}</h2>
              <p class="text-gray-500 text-sm">{{ form.profile.position || form.role?.name }}</p>
              <p class="text-gray-500 text-sm">{{ form.department?.name }}</p>
            </div>
          </div>

          <!-- Basic Info -->
          <div class="w-full md:w-2/3 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2">
              <h3 class="text-lg font-bold text-red-700 mb-2 border-b pb-2">ข้อมูลเบื้องต้น (แก้ไขไม่ได้)</h3>
            </div>
            <div>
              <label class="block text-xs text-gray-700 mb-1">เลขประจำตัวประชาชน</label>
              <input :value="form.profile.id_card_number" type="text" class="w-full px-3 py-2 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500" readonly />
            </div>
            <div>
              <label class="block text-xs text-gray-700 mb-1">อีเมล</label>
              <input :value="form.email" type="text" class="w-full px-3 py-2 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500" readonly />
            </div>
            <div>
              <label class="block text-xs text-gray-700 mb-1">วันที่เริ่มงาน</label>
              <input :value="form.profile.start_date" type="date" class="w-full px-3 py-2 border border-gray-200 bg-gray-50 rounded text-sm text-gray-500" readonly />
            </div>
          </div>
        </div>

        <!-- Editable Info -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="md:col-span-2">
            <h3 class="text-lg font-bold text-red-700 mb-2 border-b pb-2 mt-4">ข้อมูลติดต่อและการสื่อสาร</h3>
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">เบอร์โทรศัพท์มือถือ</label>
            <input v-model="form.phone" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">Telegram Chat ID</label>
            <input v-model="form.telegram_chat_id" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div class="md:col-span-2">
            <h3 class="text-lg font-bold text-red-700 mb-2 border-b pb-2 mt-4">ตั้งค่ารหัสผ่านใหม่</h3>
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs text-gray-700 mb-1">รหัสผ่านใหม่ <span class="text-gray-400 font-normal">(เว้นว่างหากไม่ต้องการเปลี่ยน)</span></label>
            <input v-model="form.password" type="password" placeholder="••••••••" class="w-full md:w-1/2 px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>

          <div class="md:col-span-2">
            <h3 class="text-lg font-bold text-red-700 mb-2 border-b pb-2 mt-4">ข้อมูลที่อยู่</h3>
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">บ้านเลขที่</label>
            <input v-model="form.profile.house_number" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">หมู่ที่</label>
            <input v-model="form.profile.moo" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs text-gray-700 mb-1">ถนน / ซอย</label>
            <input v-model="form.profile.road" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">ตำบล / แขวง</label>
            <input v-model="form.profile.sub_district" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">อำเภอ / เขต</label>
            <input v-model="form.profile.district" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">จังหวัด</label>
            <input v-model="form.profile.province" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1">รหัสไปรษณีย์</label>
            <input v-model="form.profile.zipcode" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
          </div>

          <!-- Signature Section -->
          <div class="md:col-span-2">
            <h3 class="text-lg font-bold text-red-700 mb-2 border-b pb-2 mt-4">ลายเซ็นของฉัน</h3>
          </div>
          <div class="md:col-span-2 flex flex-col md:flex-row gap-4 items-stretch">
            <div class="flex-1 border border-gray-200 p-4 rounded-xl bg-gray-50 flex flex-col items-center justify-center">
              <p class="text-sm font-medium text-gray-700 mb-3">อัปโหลดไฟล์ลายเซ็น (แนะนำ .png โปร่งใส)</p>
              <div class="w-full bg-white border border-gray-200 rounded-lg h-24 flex items-center justify-center mb-3">
                <img v-if="sigPreview" :src="sigPreview" class="max-h-full max-w-full object-contain p-1" />
                <img v-else-if="form.profile.signature_path" :src="getImageUrl(form.profile.signature_path)" class="max-h-full max-w-full object-contain p-1" />
                <span v-else class="text-gray-400 text-sm">ยังไม่มีลายเซ็น</span>
              </div>
              <input type="file" ref="sigInput" @change="onSigFileSelected" accept="image/png, image/jpeg, image/jpg" class="text-xs w-full text-gray-500 file:mr-2 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-red-50 file:text-red-700 hover:file:bg-red-100" />
            </div>

            <div class="flex-1 border border-gray-200 p-4 rounded-xl bg-gray-50 flex flex-col items-center">
              <p class="text-sm font-medium text-gray-700 mb-3">หรือวาดลายเซ็นสด</p>
              <canvas ref="sigCanvas" width="300" height="120" class="border border-gray-300 bg-white rounded shadow-inner cursor-crosshair touch-none"
                @mousedown="startDraw" @mousemove="draw" @mouseup="endDraw" @mouseout="endDraw"
                @touchstart="startDrawTouch" @touchmove="drawTouch" @touchend="endDraw"></canvas>
              <div class="mt-3 flex gap-2">
                <button type="button" @click="clearCanvas" class="px-3 py-1.5 text-xs font-semibold bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg transition-colors">ล้างลายเซ็น</button>
                <button type="button" @click="saveCanvas" class="px-3 py-1.5 text-xs font-semibold bg-gray-800 hover:bg-gray-900 text-white rounded-lg transition-colors">ใช้ลายเซ็นที่วาด</button>
              </div>
              <p v-if="canvasSaved" class="text-xs text-green-600 mt-2 font-medium"><i class="fas fa-check-circle"></i> ภาพวาดพร้อมบันทึก (อย่าลืมกดปุ่มบันทึกด้านล่าง)</p>
            </div>
          </div>

          <!-- Delegation Section -->
          <div v-if="authStore.isManager || authStore.user?.role?.name === 'Super Admin' || authStore.hasPermission('approvals')" class="md:col-span-2">
            <h3 class="text-lg font-bold text-red-700 mb-2 border-b pb-2 mt-4">มอบหมายผู้อนุมัติชั่วคราว</h3>
            <p class="text-xs text-gray-500 mb-4">ในกรณีที่คุณลางานหรือไม่สามารถอนุมัติใบลาได้ คุณสามารถมอบหมายให้ผู้ใช้อื่นอนุมัติแทนชั่วคราวได้</p>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="md:col-span-2">
                <label class="block text-xs text-gray-700 mb-1">เลือกผู้รับมอบหมาย</label>
                <select v-model="delegationForm.delegate_id" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500">
                  <option :value="null">-- ไม่มีการมอบหมาย --</option>
                  <option v-for="u in usersList" :key="u.id" :value="u.id">{{ u.name }} ({{ u.profile?.position || u.role?.name }})</option>
                </select>
              </div>
              <div v-if="delegationForm.delegate_id">
                <label class="block text-xs text-gray-700 mb-1">วันที่เริ่ม</label>
                <input v-model="delegationForm.start_date" type="date" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
              <div v-if="delegationForm.delegate_id">
                <label class="block text-xs text-gray-700 mb-1">วันที่สิ้นสุด</label>
                <input v-model="delegationForm.end_date" type="date" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:outline-none focus:border-red-500" />
              </div>
            </div>
            
            <div v-if="delegationForm.delegate_id" class="mt-3">
              <button type="button" @click="saveDelegation" :disabled="savingDelegation" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl text-xs transition-all shadow-sm flex items-center gap-2">
                <span v-if="savingDelegation" class="animate-spin h-3 w-3 border-2 border-white border-t-transparent rounded-full"></span>
                บันทึกการมอบหมาย
              </button>
            </div>
          </div>
        </div>

        <div class="mt-8 flex justify-end">
          <button type="submit" :disabled="submitting" class="px-6 py-2.5 bg-red-700 hover:bg-red-800 text-white font-semibold rounded-xl text-sm transition-all shadow-md flex items-center gap-2">
            <span v-if="submitting" class="animate-spin h-4 w-4 border-2 border-white border-t-transparent rounded-full"></span>
            <i v-else class="fas fa-save"></i>
            บันทึกการเปลี่ยนแปลง
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useAlertStore } from '../stores/alert'
import api from '../axios'

const authStore = useAuthStore()
const alertStore = useAlertStore()

const loading = ref(true)
const submitting = ref(false)
const savingDelegation = ref(false)
const usersList = ref([])

const imageInput = ref(null)
const imageFile = ref(null)
const imagePreview = ref(null)

const getImageUrl = (path) => {
  if (!path) return '';
  if (path.startsWith('http')) return path;
  const base = api.defaults.baseURL?.replace('/api', '') || '';
  return `${base}${path.startsWith('/') ? '' : '/'}${path}`;
}

const sigInput = ref(null)
const sigFile = ref(null)
const sigPreview = ref(null)
const sigBase64 = ref(null)

// Canvas references
const sigCanvas = ref(null)
let isDrawing = false
let ctx = null
const canvasSaved = ref(false)

const form = reactive({
  name: '',
  email: '',
  phone: '',
  telegram_chat_id: '',
  password: '',
  role: null,
  department: null,
  profile: {}
})

const delegationForm = reactive({
  delegate_id: null,
  start_date: '',
  end_date: '',
  is_active: true
})

onMounted(async () => {
  try {
    const res = await api.get('/profile')
    const user = res.data
    form.name = user.name
    form.email = user.email
    form.phone = user.phone || ''
    form.telegram_chat_id = user.telegram_chat_id || ''
    form.role = user.role
    form.department = user.department
    form.profile = user.profile || {}

    // Load active delegation
    const delRes = await api.get('/profile/delegations')
    if (delRes.data && delRes.data.length > 0) {
      // Find active or future delegation
      const del = delRes.data.find(d => d.is_active)
      if (del) {
        delegationForm.delegate_id = del.delegate_id
        delegationForm.start_date = del.start_date.substring(0, 10)
        delegationForm.end_date = del.end_date.substring(0, 10)
        delegationForm.is_active = del.is_active
      }
    }

    // Load users list for delegation
    if (authStore.isManager || authStore.user?.role?.name === 'Super Admin' || authStore.hasPermission('approvals')) {
      const uRes = await api.get('/admin/users')
      usersList.value = uRes.data.filter(u => u.id !== authStore.user.id)
    }

  } catch (e) {
    console.error(e)
    alertStore.error('ไม่สามารถโหลดข้อมูลโปรไฟล์ได้')
  } finally {
    loading.value = false
    // Init canvas context after render
    setTimeout(() => {
      if (sigCanvas.value) {
        ctx = sigCanvas.value.getContext('2d')
        ctx.lineWidth = 2
        ctx.lineCap = 'round'
        ctx.strokeStyle = '#000000'
      }
    }, 100)
  }
})

// Canvas Methods
const getCanvasPos = (evt) => {
  const rect = sigCanvas.value.getBoundingClientRect()
  const x = (evt.clientX || evt.touches?.[0]?.clientX) - rect.left
  const y = (evt.clientY || evt.touches?.[0]?.clientY) - rect.top
  return { x, y }
}

const startDraw = (e) => {
  isDrawing = true
  canvasSaved.value = false
  sigBase64.value = null // reset if they draw new
  const { x, y } = getCanvasPos(e)
  ctx.beginPath()
  ctx.moveTo(x, y)
}

const startDrawTouch = (e) => {
  e.preventDefault()
  startDraw(e)
}

const draw = (e) => {
  if (!isDrawing) return
  const { x, y } = getCanvasPos(e)
  ctx.lineTo(x, y)
  ctx.stroke()
}

const drawTouch = (e) => {
  e.preventDefault()
  draw(e)
}

const endDraw = () => {
  if (isDrawing) {
    ctx.closePath()
    isDrawing = false
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
    // Save to base64
    sigBase64.value = sigCanvas.value.toDataURL('image/png')
    sigFile.value = null // clear file if they use canvas
    sigPreview.value = sigBase64.value
    canvasSaved.value = true
  }
}

const onSigFileSelected = (e) => {
  const file = e.target.files[0]
  if (!file) return
  
  sigFile.value = file
  sigBase64.value = null // clear canvas base64
  canvasSaved.value = false
  clearCanvas()
  
  const reader = new FileReader()
  reader.onload = (evt) => {
    sigPreview.value = evt.target.result
  }
  reader.readAsDataURL(file)
}

const onImageSelected = (e) => {
  const file = e.target.files[0]
  if (!file) return
  
  imageFile.value = file
  
  const reader = new FileReader()
  reader.onload = (e) => {
    imagePreview.value = e.target.result
  }
  reader.readAsDataURL(file)
}

const submitProfile = async () => {
  submitting.value = true
  try {
    const formData = new FormData()
    formData.append('phone', form.phone || '')
    formData.append('telegram_chat_id', form.telegram_chat_id || '')
    
    // Add profile data as individual fields (Server expects an array)
    for (const key in form.profile) {
      if (form.profile[key] !== null && form.profile[key] !== undefined) {
        formData.append(`profile[${key}]`, form.profile[key])
      }
    }


    if (form.password) {
      formData.append('password', form.password)
    }

    if (imageFile.value) {
      formData.append('profile_photo', imageFile.value)
    }

    if (sigFile.value) {
      formData.append('signature_file', sigFile.value)
    } else if (sigBase64.value) {
      formData.append('signature_base64', sigBase64.value)
    }

    const res = await api.post('/profile', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    
    alertStore.success(res.data.message || 'อัปเดตโปรไฟล์เรียบร้อยแล้ว')
    // Update local auth user state if needed
    authStore.fetchUser()
  } catch (e) {
    console.error(e)
    alertStore.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการอัปเดต')
  } finally {
    submitting.value = false
  }
}

const saveDelegation = async () => {
  savingDelegation.value = true
  try {
    if (!delegationForm.start_date || !delegationForm.end_date) {
      alertStore.error('กรุณาระบุวันที่เริ่มต้นและสิ้นสุด')
      return
    }
    
    await api.post('/profile/delegations', {
      delegate_id: delegationForm.delegate_id,
      start_date: delegationForm.start_date,
      end_date: delegationForm.end_date,
      is_active: true
    })
    
    alertStore.success('บันทึกการมอบหมายผู้อนุมัติเรียบร้อยแล้ว')
  } catch (e) {
    console.error(e)
    alertStore.error(e.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึกการมอบหมาย')
  } finally {
    savingDelegation.value = false
  }
}
</script>
