<template>
  <div class="max-w-4xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">ชุดเลขหนังสือ</h1>
        <p class="text-sm text-gray-500 mt-1">
          ต้องมีชุดเลขของหมวดนั้นก่อน จึงจะออกเลขหนังสือและลงนามได้
        </p>
      </div>
      <AppButton size="sm" @click="creating = true">+ เพิ่มชุดเลข</AppButton>
    </div>

    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
      <LoadingState v-if="loading" type="skeleton-table" :rows="4" :columns="5" />
      <ErrorState v-else-if="error" :message="error" @retry="load" />
      <EmptyState
        v-else-if="!series.length"
        title="ยังไม่มีชุดเลขหนังสือ"
        description="เพิ่มชุดเลขอย่างน้อยหนึ่งชุดก่อนเริ่มออกหนังสือ"
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm min-w-[720px]">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left px-5 py-3 font-semibold">ชื่อชุด</th>
              <th class="text-left px-5 py-3 font-semibold">หมวด</th>
              <th class="text-left px-5 py-3 font-semibold">คำนำหน้า</th>
              <th class="text-left px-5 py-3 font-semibold">ออกเลขแล้ว</th>
              <th class="text-left px-5 py-3 font-semibold">สถานะ</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="row in series" :key="row.id" class="hover:bg-gray-50/60">
              <td class="px-5 py-3">
                <p class="font-medium text-gray-900">{{ row.name }}</p>
                <p class="text-xs text-gray-400 font-mono">{{ row.code }}</p>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ categoryLabel(row.category) }}</td>
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ row.prefix }}</td>
              <td class="px-5 py-3 text-gray-600">{{ row.issued_count }}</td>
              <td class="px-5 py-3">
                <AppBadge :variant="row.is_active ? 'success' : 'gray'">
                  {{ row.is_active ? 'ใช้งาน' : 'ปิดอยู่' }}
                </AppBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <AppButton size="sm" variant="secondary" @click="toggleActive(row)">
                  {{ row.is_active ? 'ปิด' : 'เปิด' }}
                </AppButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <AppModal
      v-if="creating"
      :model-value="true"
      title="เพิ่มชุดเลขหนังสือ"
      @update:model-value="creating = false"
    >
      <div class="space-y-4">
        <AppInput v-model="form.code" label="รหัสชุด (a-z 0-9 และ -)" placeholder="outgoing-main" required />
        <AppInput v-model="form.name" label="ชื่อชุด" required />

        <AppSelect v-model="form.category" label="หมวดหนังสือ" required>
          <option value="">— เลือกหมวด —</option>
          <option v-for="category in categories" :key="category" :value="category">
            {{ categoryLabel(category) }}
          </option>
        </AppSelect>

        <AppInput v-model="form.prefix" label="คำนำหน้าเลขหนังสือ" placeholder="ศธ 04001" required />

        <p class="text-xs text-gray-500">
          คำนำหน้าเปลี่ยนไม่ได้หลังออกเลขไปแล้ว เพราะเลขที่ออกไปแล้วอ้างชุดนี้อยู่
        </p>

        <p v-if="formError" class="text-sm text-red-600">{{ formError }}</p>
      </div>

      <template #footer>
        <AppButton variant="secondary" @click="creating = false">ยกเลิก</AppButton>
        <AppButton :loading="saving" :disabled="saving" @click="create">บันทึก</AppButton>
      </template>
    </AppModal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import AppBadge from '../../components/ui/AppBadge.vue'
import AppButton from '../../components/ui/AppButton.vue'
import AppInput from '../../components/ui/AppInput.vue'
import AppModal from '../../components/ui/AppModal.vue'
import AppSelect from '../../components/ui/AppSelect.vue'
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'

/**
 * ตั้งค่าชุดเลขหนังสือ (SoT §9 · INT-16 · ปิด RISK-21)
 *
 * ก่อนหน้านี้ชุดเลขต้องสร้างด้วย seeder หรือ tinker เท่านั้น ผู้ดูแลจึงเปิดใช้งาน
 * ระบบเองไม่ได้ และการลงนามครั้งแรกจะล้มเหลวโดยไม่มีทางแก้จากหน้าจอ
 */
const CATEGORY_LABELS = {
  incoming: 'หนังสือรับ',
  outgoing: 'หนังสือส่ง',
  internal: 'หนังสือภายใน',
  order: 'คำสั่ง',
  announcement: 'ประกาศ',
}

const series = ref([])
const categories = ref([])
const loading = ref(true)
const error = ref('')
const formError = ref('')
const saving = ref(false)
const creating = ref(false)

const form = reactive({ code: '', name: '', category: '', prefix: '' })

const categoryLabel = (category) => CATEGORY_LABELS[category] || category

const load = async () => {
  loading.value = true
  error.value = ''

  try {
    const { data } = await api.get('/saraban/number-series')
    series.value = data.series
    categories.value = data.categories
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดชุดเลขหนังสือไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const create = async () => {
  saving.value = true
  formError.value = ''

  try {
    await api.post('/saraban/number-series', { ...form })
    creating.value = false
    Object.assign(form, { code: '', name: '', category: '', prefix: '' })
    await load()
  } catch (err) {
    formError.value = getErrorMessage(err, 'บันทึกชุดเลขไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

const toggleActive = async (row) => {
  try {
    await api.put(`/saraban/number-series/${row.id}`, { is_active: !row.is_active })
    await load()
  } catch (err) {
    error.value = getErrorMessage(err, 'เปลี่ยนสถานะไม่สำเร็จ')
  }
}

onMounted(load)
</script>
