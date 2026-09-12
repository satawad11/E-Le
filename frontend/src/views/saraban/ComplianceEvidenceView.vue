<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-800">หลักฐานตามระเบียบ</h1>
      <p class="text-sm text-gray-500 mt-1">
        สถานะทุกช่องคำนวณสดจากการตรวจจริง ณ เวลาที่เปิดหน้านี้
        <strong class="text-gray-700">ไม่มีช่องให้กรอกสถานะเอง</strong>
      </p>
    </div>

    <LoadingState v-if="loading" type="skeleton-card" />
    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <template v-else>
      <section class="grid sm:grid-cols-3 lg:grid-cols-5 gap-4">
        <div v-for="(count, status) in summary" :key="status" class="bg-white border border-gray-100 rounded-2xl shadow-sm p-4">
          <p class="text-xs text-gray-500">{{ statusLabel(status) }}</p>
          <p class="text-2xl font-bold mt-1" :class="statusTextClass(status)">{{ count }}</p>
        </div>
      </section>

      <!--
        หัวข้อที่ต้องมีคนลงมือทำอยู่รวมกันด้านบน เพราะเป็นงานที่ค้างอยู่จริง
        ไม่ใช่ข้อบกพร่องของระบบ
      -->
      <section
        v-if="needsHuman.length"
        class="rounded-2xl border border-amber-200 bg-amber-50 p-5"
        role="status"
      >
        <p class="font-semibold text-amber-900">หัวข้อที่ต้องมีคนลงมือทำและบันทึกหลักฐาน</p>
        <p class="text-sm text-amber-800 mt-1">
          ระบบตรวจแทนไม่ได้ และจะไม่มีทางกลายเป็น PASS ด้วยการกดปุ่มในระบบ
        </p>
        <ul class="text-sm text-amber-800 mt-2 space-y-1">
          <li v-for="topic in needsHuman" :key="topic.key">
            <strong>{{ topic.requirement }}</strong> — {{ topic.method }}
          </li>
        </ul>
      </section>

      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100">
          <h2 class="font-semibold text-gray-800">รายการทั้งหมด</h2>
          <p class="text-sm text-gray-500 mt-0.5">
            ตรวจเมื่อ {{ formatDateTime(generatedAt) }}
          </p>
        </header>

        <ul class="divide-y divide-gray-50">
          <li v-for="topic in topics" :key="topic.key" class="px-5 py-4">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
              <div class="min-w-0">
                <p class="font-medium text-gray-900">{{ topic.requirement }}</p>
                <p class="text-xs text-gray-400 font-mono mt-0.5">{{ topic.key }}</p>
                <p class="text-sm text-gray-500 mt-1">วิธีตรวจ: {{ topic.method }}</p>
              </div>
              <AppBadge :variant="statusVariant(topic.status)" class="shrink-0">
                {{ statusLabel(topic.status) }}
              </AppBadge>
            </div>

            <!-- หลักฐานดิบ — ผู้ตรวจสอบต้องเห็นตัวเลขที่ระบบใช้ตัดสิน ไม่ใช่แค่ผล -->
            <pre
              v-if="Object.keys(topic.evidence || {}).length"
              class="text-xs bg-gray-50 border border-gray-100 rounded-lg p-2 mt-2 overflow-x-auto"
            >{{ JSON.stringify(topic.evidence, null, 2) }}</pre>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import AppBadge from '../../components/ui/AppBadge.vue'
import ErrorState from '../../components/ui/ErrorState.vue'
import LoadingState from '../../components/ui/LoadingState.vue'

/**
 * ทะเบียนหลักฐานการปฏิบัติตามข้อกำหนด (SoT §23 · INT-20)
 *
 * **หน้าจอนี้ไม่มีปุ่มเปลี่ยนสถานะเลย** — เป็นการบังคับข้อ "ห้าม claim PASS
 * โดยไม่มี evidence จริง" ด้วยการออกแบบ · และแสดงหลักฐานดิบที่ระบบใช้ตัดสิน
 * ให้ผู้ตรวจสอบเห็น ไม่ใช่แค่ผลสรุป
 */
const STATUS_LABELS = {
  PASS: 'ผ่าน',
  PARTIAL: 'ผ่านบางส่วน',
  FAIL: 'ไม่ผ่าน',
  NO_EVIDENCE: 'ยังไม่มีหลักฐาน',
  'N/A': 'ไม่เกี่ยวข้อง',
}

const topics = ref([])
const summary = ref({})
const generatedAt = ref(null)
const loading = ref(true)
const error = ref('')

const statusLabel = (status) => STATUS_LABELS[status] || status

const statusVariant = (status) =>
  ({ PASS: 'success', PARTIAL: 'warning', FAIL: 'danger', NO_EVIDENCE: 'gray', 'N/A': 'gray' })[status] || 'gray'

const statusTextClass = (status) =>
  status === 'FAIL' ? 'text-red-700' : status === 'PASS' ? 'text-emerald-700' : 'text-gray-900'

const needsHuman = computed(() => topics.value.filter((topic) => topic.status === 'NO_EVIDENCE'))

const formatDateTime = (value) => {
  if (!value) return '—'

  const date = new Date(value)

  return `${String(date.getDate()).padStart(2, '0')}/${String(date.getMonth() + 1).padStart(2, '0')}/${date.getFullYear() + 543} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')} น.`
}

const load = async () => {
  loading.value = true
  error.value = ''

  try {
    const { data } = await api.get('/saraban/operations/compliance')
    topics.value = data.topics
    summary.value = data.summary
    generatedAt.value = data.generated_at
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดทะเบียนหลักฐานไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
