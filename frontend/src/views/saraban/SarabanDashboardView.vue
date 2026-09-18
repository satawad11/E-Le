<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <!-- แถบต้อนรับ — ใช้สีแบรนด์ของระบบ ไม่ประกาศสีใหม่ -->
    <section class="rounded-2xl bg-[#800000] text-white px-6 py-6 shadow-sm">
      <h1 class="text-xl sm:text-2xl font-bold">สวัสดี {{ auth.user?.name || 'ผู้ใช้งาน' }}</h1>
      <p class="text-sm text-white/80 mt-1">ระบบงานสารบรรณอิเล็กทรอนิกส์</p>
    </section>

    <p v-if="error" role="alert" class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-xl px-4 py-3">
      {{ error }}
    </p>

    <!--
      ปุ่มลัด — แสดงเฉพาะที่ผู้ใช้มีสิทธิ์จริง · ปุ่มที่กดแล้วเด้ง 403
      แย่กว่าการไม่มีปุ่มนั้นเลย
    -->
    <section class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <RouterLink
        v-for="action in actions"
        v-show="auth.hasPermission(action.permission)"
        :key="action.to"
        :to="action.to"
        class="bg-white border border-gray-100 rounded-2xl shadow-sm px-4 py-5 text-center hover:border-[#800000] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#800000]"
      >
        <span class="block text-sm font-medium text-gray-800">{{ action.label }}</span>
      </RouterLink>
    </section>

    <LoadingState v-if="loading" type="skeleton-table" :rows="4" :columns="4" />
    <ErrorState v-else-if="fatal" :message="fatal" @retry="load" />

    <template v-else>
      <!--
        การ์ดสรุป — **ทุกใบกดเข้าไปดูรายการจริงได้**
        การ์ดที่บอกว่ามีงานค้างแต่พาไปดูไม่ได้ คือการทำให้ผู้ใช้กังวลโดยไม่ช่วยอะไร
      -->
      <section class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <RouterLink
          v-for="tile in tiles"
          :key="tile.key"
          :to="tile.route"
          class="bg-white border rounded-2xl shadow-sm px-5 py-4 hover:border-[#800000] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#800000]"
          :class="tile.alert ? 'border-[#800000]' : 'border-gray-100'"
        >
          <p class="text-sm text-gray-500">{{ tile.label }}</p>
          <p class="text-3xl font-bold mt-1" :class="tile.alert ? 'text-[#800000]' : 'text-gray-800'">
            {{ tile.value }}
          </p>
        </RouterLink>
      </section>

      <!-- สถิติการจำแนกตามทิศทางและชั้นความเร็วตามระเบียบสำนักนายกฯ -->
      <div v-if="directionStats" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- สรุปทิศทางหนังสือ -->
        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5 space-y-3">
          <h3 class="font-semibold text-gray-800 text-sm">สัดส่วนหนังสือในเดือนนี้</h3>
          <div class="grid grid-cols-3 gap-2 text-center">
            <div class="bg-blue-50/70 border border-blue-100 rounded-xl p-3">
              <span class="text-xs text-blue-700 block">หนังสือภายใน</span>
              <span class="text-xl font-bold text-blue-900">{{ directionStats.internal || 0 }}</span>
            </div>
            <div class="bg-purple-50/70 border border-purple-100 rounded-xl p-3">
              <span class="text-xs text-purple-700 block">หนังสือส่งออก</span>
              <span class="text-xl font-bold text-purple-900">{{ directionStats.outgoing || 0 }}</span>
            </div>
            <div class="bg-emerald-50/70 border border-emerald-100 rounded-xl p-3">
              <span class="text-xs text-emerald-700 block">หนังสือรับเข้า</span>
              <span class="text-xl font-bold text-emerald-900">{{ directionStats.incoming || 0 }}</span>
            </div>
          </div>
        </div>

        <!-- ชั้นความเร็วตามระเบียบสำนักนายกฯ -->
        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5 space-y-3">
          <h3 class="font-semibold text-gray-800 text-sm">การจำแนกตามชั้นความเร็ว (เดือนนี้)</h3>
          <div class="grid grid-cols-4 gap-2 text-center">
            <div class="bg-red-50/70 border border-red-100 rounded-xl p-2.5">
              <span class="text-[11px] text-red-700 block font-bold">ด่วนที่สุด</span>
              <span class="text-lg font-bold text-red-700">{{ urgencyStats?.most_urgent || 0 }}</span>
            </div>
            <div class="bg-orange-50/70 border border-orange-100 rounded-xl p-2.5">
              <span class="text-[11px] text-orange-700 block font-semibold">ด่วนมาก</span>
              <span class="text-lg font-bold text-orange-800">{{ urgencyStats?.very_urgent || 0 }}</span>
            </div>
            <div class="bg-amber-50/70 border border-amber-100 rounded-xl p-2.5">
              <span class="text-[11px] text-amber-700 block font-medium">ด่วน</span>
              <span class="text-lg font-bold text-amber-800">{{ urgencyStats?.urgent || 0 }}</span>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-2.5">
              <span class="text-[11px] text-gray-600 block">ปกติ</span>
              <span class="text-lg font-bold text-gray-800">{{ urgencyStats?.normal || 0 }}</span>
            </div>
          </div>
        </div>
      </div>

      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
          <h2 class="font-semibold">งานของฉัน</h2>
          <RouterLink to="/saraban/inbox?tab=queue" class="text-sm text-[#800000] hover:underline">
            ดูทั้งหมด
          </RouterLink>
        </header>

        <EmptyState
          v-if="!work.length"
          title="ไม่มีหนังสือที่รอท่านดำเนินการ"
          description="เมื่อมีเรื่องเดินมาถึงท่านตามสายงาน รายการจะปรากฏที่นี่"
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm min-w-[720px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3 font-semibold">เลขที่หนังสือ</th>
                <th class="text-left px-5 py-3 font-semibold">เรื่อง</th>
                <th class="text-left px-5 py-3 font-semibold">หน่วยงานเจ้าของเรื่อง</th>
                <th class="text-left px-5 py-3 font-semibold">ขั้นตอน</th>
                <th class="px-5 py-3" />
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="item in work" :key="item.document_id" class="hover:bg-gray-50">
                <td class="px-5 py-3 whitespace-nowrap">{{ item.document_number || '—' }}</td>
                <td class="px-5 py-3">{{ item.subject }}</td>
                <td class="px-5 py-3">{{ item.owner_org_unit || '—' }}</td>
                <td class="px-5 py-3">{{ item.current_step || '—' }}</td>
                <td class="px-5 py-3 text-end">
                  <AppButton variant="secondary" @click="open(item)">เปิดเรื่อง</AppButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import api from '../../axios'
import { useAuthStore } from '../../stores/auth'
import { getErrorMessage } from '../../utils/errorHandler'
import AppButton from '../../components/ui/AppButton.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'

const auth = useAuthStore()
const router = useRouter()
const tiles = ref([])
const work = ref([])
const directionStats = ref(null)
const urgencyStats = ref(null)
const loading = ref(true)
const error = ref('')
const fatal = ref('')

const actions = [
  { label: 'สร้างหนังสือ', to: '/saraban/documents/new', permission: 'saraban.document.create' },
  { label: 'ลงทะเบียนรับ', to: '/saraban/incoming/new', permission: 'saraban.incoming.register' },
  { label: 'จองเลขหนังสือ', to: '/saraban/registers?tab=reservations', permission: 'saraban.number.reserve' },
  { label: 'สมุดทะเบียน', to: '/saraban/registers', permission: 'saraban.document.view' },
]

const open = (item) => router.push({ name: 'saraban-document-detail', params: { id: item.document_id } })

async function load() {
  loading.value = true
  error.value = ''
  fatal.value = ''

  try {
    const { data } = await api.get('/saraban/dashboard')
    tiles.value = data.tiles || []
    work.value = data.my_work || []
    directionStats.value = data.direction_stats || null
    urgencyStats.value = data.urgency_stats || null
  } catch (e) {
    fatal.value = getErrorMessage(e, 'โหลดหน้าแรกของงานสารบรรณไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
