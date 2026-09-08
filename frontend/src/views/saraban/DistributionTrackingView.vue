<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-800">กระจายเอกสาร</h1>
      <p class="text-sm text-gray-500 mt-1">
        หนังสือที่เวียนออกไปแล้ว และจำนวนผู้รับที่ยังไม่แสดงการรับทราบ
      </p>
    </div>

    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
      <header class="px-5 py-4 border-b border-gray-100">
        <h2 class="font-semibold text-gray-800">รายการที่เวียนแล้ว</h2>
        <p class="text-sm text-gray-500 mt-0.5">
          การเวียนหนังสือทำจากหน้าหนังสือแต่ละฉบับ · หน้านี้ใช้ติดตามผลเท่านั้น
        </p>
      </header>

      <LoadingState v-if="loading" type="skeleton-table" :rows="5" :columns="5" />
      <ErrorState v-else-if="error" :message="error" @retry="load" />
      <EmptyState
        v-else-if="!rows.length"
        title="ยังไม่มีหนังสือที่เวียนออกไป"
        description="เมื่อมีการเวียนหนังสือ รายการและสถานะการรับทราบจะแสดงที่นี่"
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm min-w-[760px]">
          <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
              <th class="px-5 py-3 font-medium">เรื่อง</th>
              <th class="px-5 py-3 font-medium">ผู้เวียน</th>
              <th class="px-5 py-3 font-medium">เวียนเมื่อ</th>
              <th class="px-5 py-3 font-medium">ผู้รับ</th>
              <th class="px-5 py-3 font-medium">การรับทราบ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr
              v-for="row in rows"
              :key="row.uuid"
              class="hover:bg-gray-50/60 cursor-pointer"
              @click="open(row)"
            >
              <td class="px-5 py-3 text-gray-900">{{ row.subject || '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ row.sent_by || '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ formatDateTime(row.sent_at) }}</td>
              <td class="px-5 py-3 text-gray-600">{{ row.total }} คน</td>
              <td class="px-5 py-3">
                <!--
                  บอกจำนวนที่ **ยังไม่รับทราบ** เป็นตัวนำ เพราะนั่นคือสิ่งที่ต้อง
                  ตามต่อ · ตัวเลขที่เรียบร้อยแล้วไม่ต้องการการกระทำใด
                -->
                <AppBadge :variant="row.total - row.acknowledged > 0 ? 'warning' : 'success'">
                  {{
                    row.total - row.acknowledged > 0
                      ? `ยังไม่รับทราบ ${row.total - row.acknowledged} คน`
                      : 'รับทราบครบแล้ว'
                  }}
                </AppBadge>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AppPagination
        v-if="!loading && !error && total > PER_PAGE"
        class="px-5 py-4"
        :page="page"
        :total="total"
        :per-page="PER_PAGE"
        @update:page="changePage"
      />
    </section>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import { ThaiDateFormatter } from '../../utils/ThaiDateFormatter'
import AppBadge from '../../components/ui/AppBadge.vue'
import AppPagination from '../../components/ui/AppPagination.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'

/**
 * ติดตามการกระจายเอกสาร (NAV §2 · §4.8)
 *
 * **หน้านี้ไม่ส่งหนังสือ** — การเวียนทำจากหน้าหนังสือแต่ละฉบับซึ่งมีบริบทครบ
 * (ฉบับไหน ชั้นความลับใด ใครมีสิทธิ์รับ) · การมีปุ่มส่งอีกที่หนึ่งที่ไม่มีบริบท
 * เหล่านั้นคือทางที่ผู้ใช้ส่งผิดฉบับ
 *
 * ตัวเลขที่แสดงมาจาก server ทั้งหมด รวมถึงนิยามของคำว่า "รับทราบแล้ว"
 * ซึ่งนับผู้ที่รับไปดำเนินการต่อจนเสร็จด้วย ไม่ใช่เฉพาะผู้ที่กดรับทราบ
 */
const router = useRouter()

// ต้องตรงกับจำนวนต่อหน้าที่ server แบ่งไว้ มิฉะนั้นแถบเลขหน้าจะนับหน้าผิด
const PER_PAGE = 20

const rows = ref([])
const page = ref(1)
const total = ref(0)
const loading = ref(true)
const error = ref('')

const formatDateTime = (value) => ThaiDateFormatter.toThaiDateTime(value)

const load = async () => {
  loading.value = true
  error.value = ''

  try {
    const { data } = await api.get('/saraban/distributions', { params: { page: page.value } })

    rows.value = data.data || []
    total.value = data.meta?.total || 0
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดรายการกระจายเอกสารไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const changePage = (next) => {
  page.value = next
  load()
}

const open = (row) =>
  router.push({ name: 'saraban-document-detail', params: { id: row.document_id } })

onMounted(load)
</script>
