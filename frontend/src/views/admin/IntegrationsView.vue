<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-800">การเชื่อมต่อระบบภายนอก</h1>
      <p class="text-sm text-gray-500 mt-1">
        ระบบจะส่งเหตุการณ์ไปยังปลายทางที่ตั้งไว้ · ส่งเฉพาะข้อเท็จจริงและตัวชี้
        ไม่ส่งสาระสำคัญของหนังสือหรือไฟล์แนบออกนอกระบบ
      </p>
    </div>

    <!-- ความลับสำหรับเซ็นแสดงครั้งเดียว จึงต้องเด่นและคัดลอกได้ทันที -->
    <section
      v-if="newSecret"
      class="rounded-2xl border border-amber-200 bg-amber-50 p-5 space-y-2"
      role="status"
    >
      <p class="font-semibold text-amber-900">คัดลอกความลับสำหรับตรวจลายเซ็นเก็บไว้ทันที</p>
      <p class="text-sm text-amber-800">
        ค่านี้แสดงเพียงครั้งเดียว ระบบไม่เก็บไว้ให้อ่านซ้ำ หากทำหายต้องสร้างปลายทางใหม่
      </p>
      <code class="block bg-white border border-amber-200 rounded-lg px-3 py-2 text-sm break-all">{{ newSecret }}</code>
      <AppButton size="sm" variant="secondary" @click="newSecret = ''">รับทราบและซ่อน</AppButton>
    </section>

    <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
      <header class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h2 class="font-semibold text-gray-800">ปลายทางที่ตั้งไว้</h2>
          <p class="text-sm text-gray-500 mt-0.5">ปลายทางต้องเป็น HTTPS เท่านั้น</p>
        </div>
        <AppButton size="sm" @click="creating = true">+ เพิ่มปลายทาง</AppButton>
      </header>

      <LoadingState v-if="loading" type="skeleton-table" :rows="3" :columns="4" />
      <ErrorState v-else-if="error" :message="error" @retry="load" />
      <EmptyState
        v-else-if="!endpoints.length"
        title="ยังไม่มีปลายทาง"
        description="เพิ่มปลายทางเพื่อให้ระบบภายนอกรับเหตุการณ์จากระบบนี้ได้"
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm min-w-[760px]">
          <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
              <th class="text-left px-5 py-3 font-semibold">ชื่อ</th>
              <th class="text-left px-5 py-3 font-semibold">ปลายทาง</th>
              <th class="text-left px-5 py-3 font-semibold">เหตุการณ์</th>
              <th class="text-left px-5 py-3 font-semibold">สถานะ</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="endpoint in endpoints" :key="endpoint.id" class="hover:bg-gray-50/60">
              <td class="px-5 py-3 font-medium text-gray-900">{{ endpoint.name }}</td>
              <td class="px-5 py-3 text-gray-500 break-all">{{ endpoint.url }}</td>
              <td class="px-5 py-3 text-gray-600">
                <span v-for="event in endpoint.events" :key="event" class="block text-xs">
                  {{ eventLabel(event) }}
                </span>
              </td>
              <td class="px-5 py-3">
                <AppBadge :variant="endpoint.is_active ? 'success' : 'gray'">
                  {{ endpoint.is_active ? 'ใช้งาน' : 'ปิดอยู่' }}
                </AppBadge>
                <AppBadge v-if="endpoint.dead_letter_count" variant="danger" class="ml-1">
                  ส่งไม่สำเร็จ {{ endpoint.dead_letter_count }}
                </AppBadge>
              </td>
              <td class="px-5 py-3 text-right whitespace-nowrap">
                <AppButton size="sm" variant="secondary" @click="toggleActive(endpoint)">
                  {{ endpoint.is_active ? 'ปิด' : 'เปิด' }}
                </AppButton>
                <AppButton size="sm" variant="secondary" class="ml-1" @click="openDeliveries(endpoint)">
                  ประวัติ
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
      title="เพิ่มปลายทาง"
      @update:model-value="creating = false"
    >
      <div class="space-y-4">
        <AppInput v-model="form.name" label="ชื่อปลายทาง" required />
        <AppInput v-model="form.url" label="URL (ต้องเป็น https)" placeholder="https://..." required />

        <fieldset>
          <legend class="block text-sm font-semibold text-gray-700 mb-1.5">เหตุการณ์ที่ต้องการรับ</legend>
          <label
            v-for="event in availableEvents"
            :key="event"
            class="flex items-center gap-2 text-sm text-gray-700 py-1"
          >
            <input v-model="form.events" type="checkbox" :value="event" class="rounded border-gray-300">
            {{ eventLabel(event) }}
          </label>
        </fieldset>

        <p v-if="formError" class="text-sm text-red-600">{{ formError }}</p>
      </div>

      <template #footer>
        <AppButton variant="secondary" @click="creating = false">ยกเลิก</AppButton>
        <AppButton :loading="saving" :disabled="saving" @click="create">บันทึก</AppButton>
      </template>
    </AppModal>

    <AppModal
      v-if="deliveriesOf"
      :model-value="true"
      :title="`ประวัติการส่ง — ${deliveriesOf.name}`"
      size="lg"
      @update:model-value="deliveriesOf = null"
    >
      <EmptyState v-if="!deliveries.length" title="ยังไม่มีการส่ง" description="เมื่อมีเหตุการณ์เกิดขึ้น รายการจะปรากฏที่นี่" />

      <ul v-else class="divide-y divide-gray-100">
        <li v-for="delivery in deliveries" :key="delivery.id" class="py-3 flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-sm font-medium text-gray-900">{{ eventLabel(delivery.event) }}</p>
            <p class="text-xs text-gray-500">
              พยายาม {{ delivery.attempt_count }} ครั้ง
              <span v-if="delivery.response_status"> · ตอบกลับ {{ delivery.response_status }}</span>
            </p>
            <p v-if="delivery.last_error" class="text-xs text-red-600 mt-0.5 break-all">{{ delivery.last_error }}</p>
          </div>

          <div class="flex items-center gap-2 shrink-0">
            <AppBadge :variant="delivery.status === 'sent' ? 'success' : delivery.dead_lettered_at ? 'danger' : 'warning'">
              {{ statusLabel(delivery.status) }}
            </AppBadge>
            <AppButton
              v-if="delivery.dead_lettered_at"
              size="sm"
              variant="secondary"
              @click="requeue(delivery)"
            >
              ส่งใหม่
            </AppButton>
          </div>
        </li>
      </ul>

      <template #footer>
        <AppButton variant="secondary" @click="deliveriesOf = null">ปิด</AppButton>
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
import EmptyState from '../../components/ui/EmptyState.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'

/**
 * ตั้งค่า webhook ขาออก (SoT §17 · INT-15)
 *
 * **ความลับสำหรับเซ็นแสดงครั้งเดียว** — หน้าจอจึงต้องทำให้เห็นชัดและคัดลอกง่าย
 * ที่สุดในจังหวะนั้น ไม่ใช่แสดงเป็นข้อความเล็ก ๆ ที่ผู้ใช้กดผ่านไปแล้วกู้ไม่ได้
 */
const EVENT_LABELS = {
  'saraban.document.finalized': 'ออกหนังสือฉบับสมบูรณ์',
  'saraban.document.distributed': 'เวียนหนังสือ',
  'saraban.document.acknowledged': 'ผู้รับรับทราบหนังสือ',
}

const STATUS_LABELS = {
  pending: 'รอส่ง',
  sending: 'กำลังส่ง',
  sent: 'ส่งแล้ว',
  retrying: 'กำลังลองใหม่',
  failed: 'ส่งไม่สำเร็จ',
}

const endpoints = ref([])
const availableEvents = ref([])
const deliveries = ref([])
const deliveriesOf = ref(null)
const loading = ref(true)
const error = ref('')
const formError = ref('')
const saving = ref(false)
const creating = ref(false)
const newSecret = ref('')

const form = reactive({ name: '', url: '', events: [] })

const eventLabel = (event) => EVENT_LABELS[event] || event
const statusLabel = (status) => STATUS_LABELS[status] || status

const load = async () => {
  loading.value = true
  error.value = ''

  try {
    const { data } = await api.get('/admin/webhooks')
    endpoints.value = data.endpoints
    availableEvents.value = data.available_events
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดรายการปลายทางไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const create = async () => {
  saving.value = true
  formError.value = ''

  try {
    const { data } = await api.post('/admin/webhooks', { ...form })
    newSecret.value = data.secret
    creating.value = false
    Object.assign(form, { name: '', url: '', events: [] })
    await load()
  } catch (err) {
    formError.value = getErrorMessage(err, 'บันทึกปลายทางไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

const toggleActive = async (endpoint) => {
  try {
    await api.put(`/admin/webhooks/${endpoint.id}`, { is_active: !endpoint.is_active })
    await load()
  } catch (err) {
    error.value = getErrorMessage(err, 'เปลี่ยนสถานะไม่สำเร็จ')
  }
}

const openDeliveries = async (endpoint) => {
  deliveriesOf.value = endpoint
  deliveries.value = []

  try {
    const { data } = await api.get(`/admin/webhooks/${endpoint.id}/deliveries`)
    deliveries.value = data.data
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดประวัติการส่งไม่สำเร็จ')
  }
}

const requeue = async (delivery) => {
  try {
    await api.post(`/admin/webhooks/deliveries/${delivery.id}/requeue`)
    await openDeliveries(deliveriesOf.value)
    await load()
  } catch (err) {
    error.value = getErrorMessage(err, 'สั่งส่งใหม่ไม่สำเร็จ')
  }
}

onMounted(load)
</script>
