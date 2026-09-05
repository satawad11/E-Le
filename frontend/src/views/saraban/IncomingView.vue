<template>
  <div class="max-w-7xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">ทะเบียนรับหนังสือ</h1>
        <p class="text-sm text-gray-500 mt-1">
          เลขทะเบียนรับและวันเวลาที่รับเป็นของหน่วยงานเรา แยกจากเลขที่และวันที่ของหนังสือต้นทาง
        </p>
      </div>
      <AppButton v-can="'saraban.incoming.register'" @click="goToRegister">+ ลงทะเบียนรับ</AppButton>
    </div>

    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
      <header class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="flex-1">
          <h2 class="font-semibold text-gray-800">หนังสือที่รับเข้า</h2>
          <p class="text-sm text-gray-500 mt-0.5">เรียงจากที่รับล่าสุด</p>
        </div>
        <div class="w-full sm:w-72">
          <AppInput
            v-model="search"
            label="ค้นหา"
            placeholder="เรื่อง ผู้ส่ง หรือเลขที่ต้นทาง"
            @keyup.enter="applySearch"
          />
        </div>
      </header>

      <LoadingState v-if="loading" type="skeleton-table" :rows="5" :columns="5" />
      <ErrorState v-else-if="error" :message="error" @retry="load" />
      <EmptyState
        v-else-if="!registrations.length"
        title="ยังไม่มีหนังสือในทะเบียนรับ"
        description="เริ่มต้นด้วยการลงทะเบียนรับหนังสือฉบับแรก"
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm min-w-[820px]">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left px-5 py-3 font-semibold">เลขทะเบียนรับ</th>
              <th class="text-left px-5 py-3 font-semibold">เรื่อง</th>
              <th class="text-left px-5 py-3 font-semibold">จาก</th>
              <th class="text-left px-5 py-3 font-semibold">เลขที่ต้นทาง</th>
              <th class="text-left px-5 py-3 font-semibold">รับเมื่อ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="row in registrations" :key="row.id" class="hover:bg-gray-50/60">
              <td class="px-5 py-3 font-mono text-xs text-gray-900">
                {{ row.register_number?.formatted || '—' }}
              </td>
              <td class="px-5 py-3 font-medium text-gray-900"><AppButton variant="ghost" @click="openRegistration(row)">{{ row.document?.subject }}</AppButton></td>
              <td class="px-5 py-3 text-gray-600">{{ row.sender_name }}</td>
              <td class="px-5 py-3 text-gray-500">
                {{ row.source_number || '—' }}
                <span v-if="row.source_date" class="text-xs text-gray-400 block">
                  ลงวันที่ {{ row.source_date }}
                </span>
              </td>
              <td class="px-5 py-3 text-gray-500">{{ row.received_at }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="lastPage > 1" class="px-5 py-4 border-t border-gray-100">
        <AppPagination :page="page" :last-page="lastPage" @update:page="changePage" />
      </div>
    </section>
    <AppModal v-if="selected" :model-value="true" title="รายละเอียดทะเบียนรับ" @update:model-value="selected = null">
      <div class="space-y-3"><p>{{ selected.document?.subject }}</p><p>เลขทะเบียนรับ {{ selected.register_number?.formatted || '—' }}</p><p>จาก {{ selected.sender_name }} · เลขที่ต้นทาง {{ selected.source_number || '—' }}</p><p>รับเมื่อ {{ selected.received_at }} โดย {{ selected.registrar?.name || '—' }}</p><router-link v-if="selected.document" :to="`/saraban/documents/${selected.document.id}`" class="underline text-[#800000]">เปิดหนังสือ</router-link></div>
    </AppModal>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../axios'
import AppModal from '../../components/ui/AppModal.vue'
import { getErrorMessage } from '../../utils/errorHandler'
import AppButton from '../../components/ui/AppButton.vue'
import AppInput from '../../components/ui/AppInput.vue'
import AppPagination from '../../components/ui/AppPagination.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'

const router = useRouter()
const registrations = ref([])
const loading = ref(true)
const error = ref('')
const page = ref(1)
const lastPage = ref(1)
const search = ref('')
const selected = ref(null)
async function openRegistration(row) { try { selected.value = (await api.get(`/saraban/incoming/${row.id}`)).data } catch(e) { error.value = getErrorMessage(e, 'เปิดทะเบียนรับไม่สำเร็จ') } }

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/saraban/incoming', {
      params: { page: page.value, q: search.value || undefined },
    })
    registrations.value = data.data
    lastPage.value = data.last_page
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดทะเบียนรับไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const applySearch = () => {
  page.value = 1
  load()
}

const changePage = (next) => {
  page.value = next
  load()
}

const goToRegister = () => router.push({ name: 'saraban-incoming-register' })

onMounted(load)
</script>
