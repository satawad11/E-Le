<template>
  <div class="p-6">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold text-gray-800">ทะเบียนรับ-ส่ง หนังสือราชการ</h1>
      <router-link to="/saraban/create" class="bg-[#800000] text-white px-4 py-2 rounded shadow hover:bg-red-800">
        ลงทะเบียนหนังสือ
      </router-link>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
      <div
        class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex items-center justify-between cursor-pointer transition hover:shadow-md"
        :class="{ 'ring-2 ring-[#800000]': activeTab === 'all' }"
        @click="setTab('all')"
      >
        <div>
          <p class="text-sm text-gray-500 font-medium">เอกสารทั้งหมด</p>
          <p class="text-2xl font-bold text-gray-800">{{ stats.total }}</p>
        </div>
        <div class="p-3 bg-blue-50 text-blue-600 rounded-lg">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        </div>
      </div>
      <div
        class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex items-center justify-between cursor-pointer transition hover:shadow-md"
        :class="{ 'ring-2 ring-red-500': activeTab === 'unread' }"
        @click="setTab('unread')"
      >
        <div>
          <p class="text-sm text-gray-500 font-medium">ยังไม่ได้อ่าน</p>
          <p class="text-2xl font-bold text-red-600">{{ stats.unread }}</p>
        </div>
        <div class="p-3 bg-red-50 text-red-600 rounded-lg">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        </div>
      </div>
      <div
        class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex items-center justify-between cursor-pointer transition hover:shadow-md"
        :class="{ 'ring-2 ring-orange-500': activeTab === 'urgent' }"
        @click="setTab('urgent')"
      >
        <div>
          <p class="text-sm text-gray-500 font-medium">หนังสือด่วน</p>
          <p class="text-2xl font-bold text-orange-600">{{ stats.urgent }}</p>
        </div>
        <div class="p-3 bg-orange-50 text-orange-600 rounded-lg">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        </div>
      </div>
      <div
        class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex items-center justify-between cursor-pointer transition hover:shadow-md"
        :class="{ 'ring-2 ring-yellow-500': activeTab === 'pinned' }"
        @click="setTab('pinned')"
      >
        <div>
          <p class="text-sm text-gray-500 font-medium">ปักหมุดไว้</p>
          <p class="text-2xl font-bold text-yellow-600">{{ stats.pinned }}</p>
        </div>
        <div class="p-3 bg-yellow-50 text-yellow-600 rounded-lg">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/></svg>
        </div>
      </div>
    </div>
    
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="p-4 border-b flex flex-wrap gap-3 justify-between items-center">
        <!-- Search + Filter -->
        <div class="flex flex-wrap items-center gap-2 flex-1">
          <!-- ช่องค้นหา -->
          <div class="relative">
            <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input
              v-model="searchQuery"
              type="text"
              placeholder="ค้นหาเรื่อง, เลขที่เอกสาร..."
              class="pl-9 pr-4 py-2 border rounded-lg focus:ring-2 focus:ring-[#800000] outline-none w-64"
            />
          </div>

          <!-- Filter หมวดหมู่ -->
          <select
            v-model="filterTypeId"
            @change="applyFilters"
            class="border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-[#800000] text-sm"
          >
            <option value="">ทุกหมวดหมู่</option>
            <option v-for="t in documentTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select>

          <!-- จำนวนต่อหน้า -->
          <select v-model="perPage" @change="changePerPage" class="border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-[#800000] text-sm">
            <option :value="10">10 รายการ</option>
            <option :value="20">20 รายการ</option>
            <option :value="50">50 รายการ</option>
            <option :value="75">75 รายการ</option>
            <option :value="100">100 รายการ</option>
          </select>

          <!-- แสดง active tab -->
          <span v-if="activeTab !== 'all'" class="flex items-center gap-1 px-3 py-1 rounded-full text-sm font-medium"
            :class="{
              'bg-red-100 text-red-700': activeTab === 'unread',
              'bg-orange-100 text-orange-700': activeTab === 'urgent',
              'bg-yellow-100 text-yellow-700': activeTab === 'pinned',
            }"
          >
            {{ tabLabel }}
            <button @click="setTab('all')" class="ml-1 hover:opacity-70">&times;</button>
          </span>
        </div>

        <!-- ปุ่มลบหลายรายการ -->
        <button v-if="canManage && selectedIds.length > 0" @click="confirmDeleteMultiple" class="bg-red-500 text-white px-4 py-2 rounded shadow hover:bg-red-600 transition">
          ลบที่เลือก ({{ selectedIds.length }})
        </button>
      </div>
      
      <!-- Loading State -->
      <div v-if="store.loading" class="text-center py-12 text-gray-500">
        <svg class="animate-spin h-8 w-8 mx-auto mb-2 text-[#800000]" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        กำลังโหลดข้อมูล...
      </div>

      <table v-else class="w-full text-left border-collapse">
        <thead>
          <tr class="bg-gray-50 text-gray-600 text-sm">
            <th v-if="canManage" class="p-4 border-b font-medium w-12 text-center">
              <input type="checkbox" :checked="selectAll" @change="toggleSelectAll" class="rounded text-[#800000] focus:ring-[#800000]">
            </th>
            <th class="p-4 border-b font-medium">เลขที่เอกสาร</th>
            <th class="p-4 border-b font-medium">เรื่อง</th>
            <th class="p-4 border-b font-medium">ชั้นความลับ</th>
            <th class="p-4 border-b font-medium">สถานะ</th>
            <th class="p-4 border-b font-medium">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="doc in documents" :key="doc.id" class="hover:bg-gray-50">
            <td v-if="canManage" class="p-4 border-b text-center">
              <input type="checkbox" :value="doc.id" v-model="selectedIds" class="rounded text-[#800000] focus:ring-[#800000]">
            </td>
            <td class="p-4 border-b text-sm">{{ doc.document_number || '-' }}</td>
            <td class="p-4 border-b">
              <div class="flex flex-col gap-1">
                <span class="font-medium flex items-center gap-2">
                  <svg v-if="doc.is_pinned" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-orange-500" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M5 4a2 2 0 012-2h6a2 2 0 012 2v14l-5-2.5L5 18V4z" />
                  </svg>
                  {{ doc.title }}
                </span>
                <div class="flex gap-2 flex-wrap">
                  <span v-if="doc.type" class="px-2 py-0.5 text-[10px] rounded text-white font-medium" :style="{ backgroundColor: doc.type.color || '#6b7280' }">
                    {{ doc.type.name }}
                  </span>
                  <span v-if="!doc.is_read" class="px-2 py-0.5 text-[10px] rounded bg-red-500 text-white font-bold">ใหม่</span>
                  <span v-if="['urgent', 'very_urgent', 'most_urgent'].includes(doc.priority)" class="px-2 py-0.5 text-[10px] rounded bg-orange-100 text-orange-800">
                    {{ doc.priority === 'urgent' ? 'ด่วน' : doc.priority === 'very_urgent' ? 'ด่วนมาก' : 'ด่วนที่สุด' }}
                  </span>
                </div>
              </div>
            </td>
            <td class="p-4 border-b">
              <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">{{ secrecyLabel(doc.secrecy) }}</span>
            </td>
            <td class="p-4 border-b">
              <span class="px-2 py-1 text-xs rounded-full border" :class="statusConfig(doc.status).class">{{ statusConfig(doc.status).label }}</span>
            </td>
            <td class="p-4 border-b">
              <div class="flex space-x-3">
                <router-link :to="`/saraban/${doc.id}`" class="text-gray-600 hover:text-[#800000]" title="ดูรายละเอียด">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                  </svg>
                </router-link>
                
                <button @click="togglePin(doc)" class="text-gray-400 hover:text-orange-500" :class="{'text-orange-500': doc.is_pinned}" title="ปักหมุด/เลิกปักหมุด">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
                  </svg>
                </button>

                <router-link v-if="canManage" :to="`/saraban/${doc.id}/edit`" class="text-blue-500 hover:text-blue-700" title="แก้ไข">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                  </svg>
                </router-link>
                
                <!-- แอดมินลบถาวร -->
                <button v-if="canManage" @click="confirmDelete(doc.id)" class="text-red-500 hover:text-red-700" title="ลบถาวร (ส่วนกลาง)">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
                
                <!-- ผู้ใช้ลบออกจากรายการตัวเอง -->
                <button v-if="!canManage" @click="hideDocument(doc.id)" class="text-gray-400 hover:text-red-500" title="ลบออกจากรายการของฉัน">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="documents.length === 0 && !store.loading">
            <td :colspan="canManage ? 6 : 5" class="p-8 text-center text-gray-500 border-b">
              <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
              ไม่พบข้อมูลเอกสาร
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Pagination -->
      <div v-if="store.pagination.total > 0" class="p-4 border-t flex items-center justify-between flex-wrap gap-2">
        <span class="text-sm text-gray-600">
          แสดง {{ (store.pagination.current_page - 1) * store.pagination.per_page + 1 }} ถึง 
          {{ Math.min(store.pagination.current_page * store.pagination.per_page, store.pagination.total) }} 
          จากทั้งหมด {{ store.pagination.total }} รายการ
        </span>
        <div class="flex space-x-1">
          <button
            @click="changePage(store.pagination.current_page - 1)"
            :disabled="store.pagination.current_page === 1"
            class="px-3 py-1 border rounded text-gray-600 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
          >ก่อนหน้า</button>
          <template v-for="page in pageNumbers" :key="page">
            <span v-if="page === '...'" class="px-2 py-1 text-gray-400">...</span>
            <button
              v-else
              @click="changePage(page)"
              :class="{'bg-[#800000] text-white border-[#800000]': page === store.pagination.current_page, 'text-gray-600 hover:bg-gray-50': page !== store.pagination.current_page}"
              class="px-3 py-1 border rounded"
            >{{ page }}</button>
          </template>
          <button
            @click="changePage(store.pagination.current_page + 1)"
            :disabled="store.pagination.current_page === store.pagination.last_page"
            class="px-3 py-1 border rounded text-gray-600 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
          >ถัดไป</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useSarabanDocumentStore } from '../../stores/useSarabanDocumentStore'
import { useAuthStore } from '../../stores/auth'
import Swal from 'sweetalert2'
import api from '../../axios'
import { sarabanStatusConfig } from '../../utils/statusHelper'

const store = useSarabanDocumentStore()
const authStore = useAuthStore()
const route = useRoute()
const documents = computed(() => store.documents)

const stats = ref({ total: 0, unread: 0, urgent: 0, pinned: 0 })
const documentTypes = ref([])

// Filters
const searchQuery = ref('')
const filterTypeId = ref('')
const activeTab = ref('all')
const perPage = ref(10)
const selectedIds = ref([])

// Debounce timer
let searchTimer = null

const tabLabel = computed(() => {
  if (activeTab.value === 'unread') return 'ยังไม่อ่าน'
  if (activeTab.value === 'urgent') return 'ด่วน'
  if (activeTab.value === 'pinned') return 'ปักหมุด'
  return 'ทั้งหมด'
})

const secrecyLabel = (val) => {
  const map = { normal: 'ปกติ', secret: 'ลับ', top_secret: 'ลับมาก', most_secret: 'ลับที่สุด' }
  return map[val] || val
}

const statusConfig = (status) => sarabanStatusConfig(status)

// Pagination helper — แสดงเลขหน้าแบบ smart (ไม่ overflow เมื่อมีหลายหน้า)
const pageNumbers = computed(() => {
  const total = store.pagination.last_page
  const current = store.pagination.current_page
  if (total <= 7) {
    return Array.from({ length: total }, (_, i) => i + 1)
  }
  const pages = []
  if (current <= 4) {
    for (let i = 1; i <= 5; i++) pages.push(i)
    pages.push('...')
    pages.push(total)
  } else if (current >= total - 3) {
    pages.push(1)
    pages.push('...')
    for (let i = total - 4; i <= total; i++) pages.push(i)
  } else {
    pages.push(1)
    pages.push('...')
    pages.push(current - 1)
    pages.push(current)
    pages.push(current + 1)
    pages.push('...')
    pages.push(total)
  }
  return pages
})

const canManage = computed(() => {
  return authStore.isSuperAdmin || authStore.isAdmin || authStore.isSarabanOfficer
})

const selectAll = computed(() => {
  return documents.value.length > 0 && selectedIds.value.length === documents.value.length
})

const toggleSelectAll = (e) => {
  if (e.target.checked) {
    selectedIds.value = documents.value.map(d => d.id)
  } else {
    selectedIds.value = []
  }
}

// ดึงข้อมูลโดย apply filter ปัจจุบัน
const applyFilters = (page = 1) => {
  selectedIds.value = []
  
  let direction = undefined
  if (route.path.includes('/incoming')) direction = 'incoming'
  if (route.path.includes('/outgoing')) direction = 'outgoing'

  store.fetchDocuments(page, perPage.value, {
    search: searchQuery.value,
    tab: activeTab.value,
    type_id: filterTypeId.value || undefined,
    direction: direction
  })
}

// Watch for route changes to reload data
watch(() => route.path, () => {
  applyFilters(1)
})

const setTab = (tab) => {
  activeTab.value = tab
  applyFilters(1)
}

const changePage = (page) => {
  if (page >= 1 && page <= store.pagination.last_page) {
    applyFilters(page)
  }
}

const changePerPage = () => {
  applyFilters(1)
}

// Debounce search
watch(searchQuery, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    applyFilters(1)
  }, 400)
})

const fetchStats = async () => {
  try {
    const res = await api.get('/saraban/documents/stats')
    stats.value = res.data
  } catch (e) {
    console.error('Failed to load stats', e)
  }
}

const fetchTypes = async () => {
  try {
    const res = await api.get('/saraban/types')
    documentTypes.value = res.data
  } catch (e) {
    console.warn('Could not fetch types', e)
  }
}

const confirmDelete = (id) => {
  Swal.fire({
    title: 'ยืนยันการลบ?',
    text: "คุณต้องการลบเอกสารนี้ใช่หรือไม่? (ไม่สามารถกู้คืนได้)",
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d33',
    cancelButtonColor: '#9ca3af',
    confirmButtonText: 'ลบข้อมูล',
    cancelButtonText: 'ยกเลิก'
  }).then(async (result) => {
    if (result.isConfirmed) {
      try {
        await store.deleteDocument(id)
        fetchStats()
        Swal.fire({ title: 'ลบสำเร็จ!', text: 'เอกสารถูกลบออกจากระบบแล้ว', icon: 'success', confirmButtonColor: '#800000' })
      } catch (err) {
        Swal.fire({ title: 'เกิดข้อผิดพลาด', text: 'ไม่สามารถลบเอกสารได้: ' + (err.response?.data?.message || err.message), icon: 'error', confirmButtonColor: '#800000' })
      }
    }
  })
}

const confirmDeleteMultiple = () => {
  Swal.fire({
    title: `ยืนยันการลบ ${selectedIds.value.length} รายการ?`,
    text: "คุณต้องการลบเอกสารที่เลือกทั้งหมดใช่หรือไม่? (ไม่สามารถกู้คืนได้)",
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d33',
    cancelButtonColor: '#9ca3af',
    confirmButtonText: 'ลบข้อมูล',
    cancelButtonText: 'ยกเลิก'
  }).then(async (result) => {
    if (result.isConfirmed) {
      try {
        await store.deleteMultipleDocuments(selectedIds.value)
        selectedIds.value = []
        fetchStats()
        Swal.fire({ title: 'ลบสำเร็จ!', text: 'เอกสารที่เลือกถูกลบออกจากระบบแล้ว', icon: 'success', confirmButtonColor: '#800000' })
      } catch (err) {
        Swal.fire({ title: 'เกิดข้อผิดพลาด', text: 'ไม่สามารถลบเอกสารได้: ' + (err.response?.data?.message || err.message), icon: 'error', confirmButtonColor: '#800000' })
      }
    }
  })
}

const togglePin = async (doc) => {
  try {
    await store.updateDocumentState(doc.id, { is_pinned: !doc.is_pinned })
    fetchStats()
  } catch (err) {
    Swal.fire('ผิดพลาด', 'ไม่สามารถเปลี่ยนสถานะปักหมุดได้', 'error')
  }
}

const hideDocument = (id) => {
  Swal.fire({
    title: 'ลบออกจากรายการ?',
    text: "รายการนี้จะถูกซ่อนจากมุมมองของคุณเท่านั้น (คนอื่นยังเห็นปกติ)",
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#d33',
    cancelButtonColor: '#9ca3af',
    confirmButtonText: 'ลบออก',
    cancelButtonText: 'ยกเลิก'
  }).then(async (result) => {
    if (result.isConfirmed) {
      try {
        await store.updateDocumentState(id, { is_hidden: true })
        fetchStats()
        Swal.fire('ลบสำเร็จ', 'ลบออกจากรายการของคุณแล้ว', 'success')
      } catch (err) {
        Swal.fire('ผิดพลาด', 'ไม่สามารถลบรายการได้', 'error')
      }
    }
  })
}

onMounted(() => {
  applyFilters(1)
  fetchStats()
  fetchTypes()
})
</script>
