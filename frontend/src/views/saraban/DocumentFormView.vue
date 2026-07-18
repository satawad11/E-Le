<template>
  <div class="p-6">
    <div class="mb-6">
      <router-link to="/saraban" class="text-gray-500 hover:text-gray-800 mb-2 inline-block">← กลับไปหน้ารายการ</router-link>
      <h1 class="text-2xl font-bold text-gray-800">{{ isEdit ? 'แก้ไขหนังสือราชการ' : 'ลงทะเบียนหนังสือใหม่' }}</h1>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 max-w-3xl">
      <form @submit.prevent="submitForm" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">หมวดหมู่เอกสาร <span class="text-red-500">*</span></label>
            <select v-model="form.type_id" required class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-[#800000] outline-none">
              <option v-for="type in documentTypes" :key="type.id" :value="type.id">{{ type.name }} {{ type.code ? `(${type.code})` : '' }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">เรื่อง <span class="text-red-500">*</span></label>
            <input v-model="form.title" type="text" required class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-[#800000] outline-none" />
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">ลงวันที่</label>
            <ThaiDatePicker v-model="form.document_date" id="document-date" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">เรียน</label>
            <input v-model="form.to" type="text" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-[#800000] outline-none" />
          </div>
        </div>



        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">อ้างถึง</label>
          <input v-model="form.reference" type="text" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-[#800000] outline-none" />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">สิ่งที่ส่งมาด้วย</label>
          <textarea v-model="form.attachments_note" rows="2" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-[#800000] outline-none"></textarea>
        </div>
        
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">ชั้นความลับ</label>
            <select v-model="form.secrecy" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-[#800000] outline-none">
              <option value="normal">ปกติ</option>
              <option value="secret">ลับ</option>
              <option value="top_secret">ลับมาก</option>
              <option value="most_secret">ลับที่สุด</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">ความเร่งด่วน</label>
            <select v-model="form.priority" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-[#800000] outline-none">
              <option value="normal">ปกติ</option>
              <option value="urgent">ด่วน</option>
              <option value="very_urgent">ด่วนมาก</option>
              <option value="most_urgent">ด่วนที่สุด</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">หมายเหตุ</label>
          <textarea v-model="form.remark" rows="2" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-[#800000] outline-none"></textarea>
        </div>

        <div class="border-t pt-4">
          <h3 class="text-lg font-medium text-gray-800 mb-3">รายละเอียดของหนังสือที่รับเข้ามา</h3>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">เลขที่หนังสือเข้า</label>
              <input v-model="form.incoming_document_number" type="text" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-[#800000] outline-none" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">วันที่รับเข้ามา</label>
              <ThaiDatePicker v-model="form.incoming_date" id="incoming-date" />
            </div>
          </div>
        </div>

        <div class="border-t pt-4">
          <h3 class="text-lg font-medium text-gray-800 mb-3">ผู้รับเอกสาร</h3>
          <div class="grid grid-cols-2 gap-6 mb-4">
            <!-- Departments -->
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
              <label class="block text-sm font-bold text-gray-700 mb-2">กลุ่ม/แผนก (ส่งทั้งแผนก)</label>
              
              <input type="text" v-model="searchDept" placeholder="ค้นหาแผนก..." class="w-full px-3 py-2 mb-3 border rounded focus:ring-2 focus:ring-[#800000] outline-none text-sm" />
              
              <div class="flex flex-wrap gap-1 mb-3" v-if="form.receiver_departments.length > 0">
                <span v-for="id in form.receiver_departments" :key="'chip-dept-'+id" class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full flex items-center">
                  {{ departments.find(d => d.id === id)?.name || id }}
                  <button type="button" @click="form.receiver_departments = form.receiver_departments.filter(d => d !== id)" class="ml-1 text-blue-500 hover:text-blue-700">&times;</button>
                </span>
              </div>
              
              <div class="max-h-40 overflow-y-auto bg-white border border-gray-200 rounded p-2">
                <label v-for="dept in filteredDepartments" :key="dept.id" class="flex items-center p-2 hover:bg-gray-50 cursor-pointer rounded">
                  <input type="checkbox" :value="dept.id" v-model="form.receiver_departments" class="mr-3 w-4 h-4 text-[#800000] focus:ring-[#800000] rounded" />
                  <span class="text-sm text-gray-700">{{ dept.name }}</span>
                </label>
                <div v-if="filteredDepartments.length === 0" class="text-center text-sm text-gray-500 py-2">ไม่พบแผนกที่ค้นหา</div>
              </div>
            </div>

            <!-- Users -->
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
              <label class="block text-sm font-bold text-gray-700 mb-2">บุคคล (ส่งรายคน)</label>
              
              <input type="text" v-model="searchUser" placeholder="ค้นหารายชื่อบุคคล..." class="w-full px-3 py-2 mb-3 border rounded focus:ring-2 focus:ring-[#800000] outline-none text-sm" />
              
              <div class="flex flex-wrap gap-1 mb-3" v-if="form.receivers.length > 0">
                <span v-for="id in form.receivers" :key="'chip-user-'+id" class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded-full flex items-center">
                  {{ users.find(u => u.id === id)?.name || id }}
                  <button type="button" @click="form.receivers = form.receivers.filter(u => u !== id)" class="ml-1 text-green-500 hover:text-green-700">&times;</button>
                </span>
              </div>
              
              <div class="max-h-40 overflow-y-auto bg-white border border-gray-200 rounded p-2">
                <label v-for="user in filteredUsers" :key="user.id" class="flex items-center p-2 hover:bg-gray-50 cursor-pointer rounded">
                  <input type="checkbox" :value="user.id" v-model="form.receivers" class="mr-3 w-4 h-4 text-[#800000] focus:ring-[#800000] rounded" />
                  <span class="text-sm text-gray-700">{{ user.name }}</span>
                </label>
                <div v-if="filteredUsers.length === 0" class="text-center text-sm text-gray-500 py-2">ไม่พบรายชื่อที่ค้นหา</div>
              </div>
            </div>
          </div>
        </div>

        <div class="border-t pt-4">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-lg font-medium text-gray-800">รูปแบบเอกสาร</h3>
          </div>
          <div class="flex gap-4 mb-4">
            <label class="flex items-center cursor-pointer p-3 border rounded-lg hover:bg-gray-50 flex-1" :class="{'border-[#800000] bg-red-50': form.creation_mode === 'physical'}">
              <input type="radio" v-model="form.creation_mode" value="physical" class="w-4 h-4 text-[#800000] focus:ring-[#800000]" />
              <div class="ml-3">
                <span class="block font-medium text-gray-900">อัปโหลดเอกสาร (Scanned PDF)</span>
                <span class="block text-sm text-gray-500">สำหรับเอกสารกระดาษที่สแกนเข้าระบบ</span>
              </div>
            </label>
            <label class="flex items-center cursor-pointer p-3 border rounded-lg hover:bg-gray-50 flex-1" :class="{'border-[#800000] bg-red-50': form.creation_mode === 'digital'}">
              <input type="radio" v-model="form.creation_mode" value="digital" class="w-4 h-4 text-[#800000] focus:ring-[#800000]" />
              <div class="ml-3">
                <span class="block font-medium text-gray-900">สร้างเอกสารอิเล็กทรอนิกส์ (E-Doc)</span>
                <span class="block text-sm text-gray-500">พิมพ์เนื้อหาและสร้าง PDF โดยอัตโนมัติ</span>
              </div>
            </label>
          </div>

          <!-- Digital Mode: Quill Editor -->
          <div v-if="form.creation_mode === 'digital'" class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">เนื้อหาเอกสาร (ส่วนข้อความ) <span class="text-red-500">*</span></label>
            <div class="border rounded-lg bg-white">
              <QuillEditor theme="snow" v-model:content="form.content" contentType="html" style="min-height: 300px;" />
            </div>
            <p class="text-xs text-gray-500 mt-1">* ระบบจะนำเนื้อหานี้ไปสร้างเป็นไฟล์ PDF อัตโนมัติ (พร้อมตราครุฑ ถ้ามีการตั้งค่าไว้)</p>
          </div>

          <!-- Physical Mode: File Upload -->
          <div v-else class="mb-4">
            <div class="flex items-center justify-between mb-3">
              <h3 class="text-lg font-medium text-gray-800">เอกสารแนบ</h3>
              <label class="flex items-center cursor-pointer">
                <div class="relative">
                  <input type="checkbox" v-model="form.allow_download" class="sr-only">
                  <div class="block bg-gray-600 w-10 h-6 rounded-full transition-colors duration-300" :class="{'bg-green-500': form.allow_download}"></div>
                  <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition transform duration-300" :class="{'translate-x-4': form.allow_download}"></div>
                </div>
                <span class="ml-3 text-sm font-medium text-gray-700">อนุญาตให้ดาวน์โหลดเอกสารแนบ</span>
              </label>
            </div>
            <label class="block text-sm font-medium text-gray-700 mb-1">แนบไฟล์ PDF (หลายไฟล์ได้) <span class="text-red-500">*</span></label>
            <input type="file" multiple accept=".pdf" @change="handleFileUpload" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-[#800000] outline-none" />
            <p class="text-xs text-gray-500 mt-1">* รองรับเฉพาะไฟล์ .pdf เท่านั้น สำหรับการลงนามอิเล็กทรอนิกส์</p>
            <div class="mt-2 text-sm text-gray-600" v-if="form.files.length > 0">
              เลือกแล้ว {{ form.files.length }} ไฟล์
            </div>
          </div>

          <div>
            <div class="flex items-center justify-between mb-2">
              <label class="block text-sm font-medium text-gray-700">แนบลิงก์</label>
              <button type="button" @click="addLink" class="text-sm text-[#800000] hover:underline">+ เพิ่มลิงก์</button>
            </div>
            <div v-for="(link, index) in form.links" :key="index" class="flex gap-2 mb-2">
              <input v-model="link.title" placeholder="ชื่อลิงก์" type="text" class="w-1/3 px-3 py-1 border rounded-lg focus:ring-2 focus:ring-[#800000] outline-none" />
              <input v-model="link.url" placeholder="URL (เช่น https://...)" type="url" class="flex-1 px-3 py-1 border rounded-lg focus:ring-2 focus:ring-[#800000] outline-none" />
              <button type="button" @click="removeLink(index)" class="px-2 text-red-500 hover:text-red-700">ลบ</button>
            </div>
          </div>
        </div>

        <div class="pt-4 flex justify-end gap-2">
          <router-link to="/saraban" class="px-4 py-2 text-gray-600 bg-gray-100 rounded hover:bg-gray-200">ยกเลิก</router-link>
          <button type="submit" class="px-4 py-2 bg-[#800000] text-white rounded hover:bg-red-800" :disabled="loading">
            {{ loading ? 'กำลังบันทึก...' : (isEdit ? 'อัปเดตข้อมูล' : 'บันทึกข้อมูล') }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import api from '../../axios'
import { useRouter, useRoute } from 'vue-router'
import { useSarabanDocumentStore } from '../../stores/useSarabanDocumentStore'
import { useAuthStore } from '../../stores/auth'
import Swal from 'sweetalert2'
import ThaiDatePicker from '../../components/ThaiDatePicker.vue'
import { QuillEditor } from '@vueup/vue-quill'
import '@vueup/vue-quill/dist/vue-quill.snow.css'

const router = useRouter()
const route = useRoute()
const store = useSarabanDocumentStore()
const authStore = useAuthStore()
const loading = ref(false)

// แสดงวันที่ในรูปแบบไทย (dd ก.ค. yyyyพ.ศ.)
// รับได้ทั้ง Date object และ string
const thaiShortMonths = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const formatThaiDate = (date) => {
  if (!date) return '';
  const d = date instanceof Date ? date : new Date(date);
  if (isNaN(d.getTime())) return '';
  const day = d.getDate();
  const month = thaiShortMonths[d.getMonth()];
  const year = d.getFullYear() + 543;
  return `${day} ${month} ${year}`;
}

const thaiMonths = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

// แปลง Date เป็น yyyy-mm-dd โดยใช้ local date components (หลีก timezone shift)
const toLocalDateString = (date) => {
  if (!date) return ''
  const d = date instanceof Date ? date : new Date(date)
  const year = d.getFullYear()
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

// แปลง yyyy-mm-dd string เป็น Date object โดยใช้ local constructor (หลีก UTC midnight shift)
const parseDateString = (str) => {
  if (!str) return null
  const datePart = str.split('T')[0].split(' ')[0] // รองรับ timestamp
  const [year, month, day] = datePart.split('-').map(Number)
  if (!year || !month || !day) return null
  return new Date(year, month - 1, day) // Local date ไม่ใช่ UTC
}

const isEdit = computed(() => !!route.params.id)
const docId = computed(() => route.params.id)

const form = ref({
  title: '',
  document_date: '',
  to: '',
  reference: '',
  attachments_note: '',
  remark: '',
  incoming_document_number: '',
  incoming_date: '',
  creation_mode: 'physical',
  content: '',
  allow_download: true,
  receivers: [],
  receiver_departments: [],
  files: [],
  links: [],
  secrecy: 'normal',
  priority: 'normal',
  type_id: 1, // จะถูก set อีกครั้งเมื่อโหลด types สำเร็จ
  department_id: null // จะถูก set จาก authStore.user
})

// ลบ watch ของ documentDateObj และ incomingDateObj ทิ้งไป เนื่องจาก ThaiDatePicker ทำงานกับ string โดยตรง
// รวมถึงฟังก์ชันแปลกๆ ของ Date object ที่ไม่จำเป็นแล้ว

const departments = ref([])
const users = ref([])
const documentTypes = ref([])

const searchDept = ref('')
const searchUser = ref('')

const filteredDepartments = computed(() => {
  if (!searchDept.value) return departments.value
  return departments.value.filter(d => d.name.toLowerCase().includes(searchDept.value.toLowerCase()))
})

const filteredUsers = computed(() => {
  if (!searchUser.value) return users.value
  return users.value.filter(u => u.name.toLowerCase().includes(searchUser.value.toLowerCase()))
})

onMounted(async () => {
  // ดึง department_id จาก user ที่ login
  if (authStore.user?.department_id) {
    form.value.department_id = authStore.user.department_id
  }

  try {
    const typeRes = await api.get('/saraban/types')
    documentTypes.value = typeRes.data
    if (!isEdit.value && documentTypes.value.length > 0) {
      form.value.type_id = documentTypes.value[0].id
    }
  } catch(e) { console.warn('Could not fetch types', e) }

  try {
    const deptRes = await api.get('/admin/departments-list')
    departments.value = deptRes.data
  } catch(e) { console.warn('Could not fetch departments', e) }
  
  try {
    const userRes = await api.get('/users/list')
    users.value = userRes.data.data || userRes.data
  } catch(e) { console.warn('Could not fetch users', e) }

  if (isEdit.value) {
    try {
      loading.value = true
      const docRes = await api.get(`/saraban/documents/${docId.value}`)
      const doc = docRes.data
      form.value = {
        title: doc.title,
        document_date: doc.document_date ? doc.document_date.split(' ')[0] : '',
        to: doc.to || '',
        reference: doc.reference || '',
        attachments_note: doc.attachments_note || '',
        remark: doc.remark || '',
        incoming_document_number: doc.incoming_document_number || '',
        incoming_date: doc.incoming_date ? doc.incoming_date.split(' ')[0] : '',
        allow_download: !!doc.allow_download,
        receivers: doc.receivers ? doc.receivers.map(r => r.id) : [],
        receiver_departments: [],
        files: [],
        links: doc.attachments ? doc.attachments.filter(a => a.type === 'link').map(a => ({ title: a.name, url: a.path })) : [],
        secrecy: doc.secrecy || 'normal',
        priority: doc.priority || 'normal',
        type_id: doc.type_id || (documentTypes.value.length > 0 ? documentTypes.value[0].id : 1),
        department_id: doc.department_id || authStore.user?.department_id || null
      }
      
      // เราใช้ string ตรงกับ form ได้เลย ไม่ต้องแปลงกลับไปมาให้ documentDateObj
      // ข้อมูลจะอยู่ใน form.value.document_date อยู่แล้ว
    } catch(e) {
      console.error('Failed to load document', e)
      Swal.fire({ icon: 'error', title: 'ผิดพลาด', text: 'ไม่สามารถโหลดข้อมูลเอกสารได้' })
    } finally {
      loading.value = false
    }
  }
})

const handleFileUpload = (e) => {
  form.value.files = Array.from(e.target.files)
}

const addLink = () => {
  form.value.links.push({ title: '', url: '' })
}

const removeLink = (index) => {
  form.value.links.splice(index, 1)
}

const submitForm = async () => {
  loading.value = true
  try {
    if (isEdit.value) {
      await store.updateDocument(docId.value, form.value)
    } else {
      await store.createDocument(form.value)
    }
    Swal.fire({
      icon: 'success',
      title: 'สำเร็จ!',
      text: isEdit.value ? 'อัปเดตข้อมูลสำเร็จ' : 'บันทึกข้อมูลสำเร็จ',
      confirmButtonColor: '#800000'
    })
    router.push('/saraban')
  } catch (err) {
    let errorMsg = err.response?.data?.message || err.message
    if (err.response?.data?.errors) {
      const errors = err.response.data.errors
      const firstError = Object.values(errors)[0][0]
      errorMsg += '\nสาเหตุ: ' + firstError
    }
    Swal.fire({
      icon: 'error',
      title: 'เกิดข้อผิดพลาดในการบันทึก',
      text: errorMsg,
      confirmButtonColor: '#800000'
    })
  } finally {
    loading.value = false
  }
}
</script>

<style>
/* นำ CSS ของ VueDatePicker เดิมออกไป เพราะใช้ ThaiDatePicker แทนแล้ว */
</style>
