<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-800">รายการที่ต้องตามแก้</h1>
      <p class="text-sm text-gray-500 mt-1">
        รวมสิ่งที่ค้างและสิ่งที่หยุดทำงาน · ตัวเลขทุกตัวมาจากตารางที่ระบบใช้ทำงานจริง
      </p>
    </div>

    <LoadingState v-if="loading" type="skeleton-card" />
    <ErrorState v-else-if="error" :message="error" @retry="load" />

    <template v-else>
      <OperationsTools />
      <!-- งานตามเวลาที่หยุดทำงานคือเรื่องที่ต้องเห็นก่อนอย่างอื่น -->
      <section
        v-if="center.scheduler?.stale?.length"
        class="rounded-2xl border border-red-200 bg-red-50 p-5"
        role="alert"
      >
        <p class="font-semibold text-red-900">งานตามเวลาหยุดทำงาน</p>
        <p class="text-sm text-red-800 mt-1">
          งานเหล่านี้เงียบเกินกำหนด · ข้อความและ webhook อาจค้างอยู่โดยไม่ถูกส่ง
          กรุณาตรวจสอบว่า <code>schedule:run</code> ยังทำงานอยู่
        </p>
        <ul class="text-sm text-red-800 mt-2 list-disc list-inside">
          <li v-for="task in center.scheduler.stale" :key="task">{{ task }}</li>
        </ul>
      </section>

      <section class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div v-for="tile in tiles" :key="tile.label" class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5">
          <p class="text-sm text-gray-500">{{ tile.label }}</p>
          <p class="text-2xl font-bold mt-1" :class="tile.alert ? 'text-red-700' : 'text-gray-900'">
            {{ tile.value }}
          </p>
          <p v-if="tile.hint" class="text-xs text-gray-400 mt-1">{{ tile.hint }}</p>
        </div>
      </section>

      <!-- คิวข้อผิดพลาด -->
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100">
          <h2 class="font-semibold text-gray-800">รายการที่ต้องตามแก้</h2>
          <p class="text-sm text-gray-500 mt-0.5">เรียงตามความรุนแรงก่อนเวลา</p>
        </header>

        <EmptyState
          v-if="!exceptions.length"
          title="ไม่มีรายการค้าง"
          description="ระบบไม่พบข้อผิดพลาดที่ต้องตามแก้ในขณะนี้"
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm min-w-[820px]">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
              <tr>
                <th class="text-left px-5 py-3 font-semibold">เรื่อง</th>
                <th class="text-left px-5 py-3 font-semibold">ความรุนแรง</th>
                <th class="text-left px-5 py-3 font-semibold">เกิดซ้ำ</th>
                <th class="text-left px-5 py-3 font-semibold">ผู้รับผิดชอบ</th>
                <th class="px-5 py-3" />
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="item in exceptions" :key="item.id" class="hover:bg-gray-50/60">
                <td class="px-5 py-3">
                  <p class="font-medium text-gray-900">{{ item.title }}</p>
                  <p class="text-xs text-gray-400 font-mono">{{ item.type }}</p>
                </td>
                <td class="px-5 py-3">
                  <AppBadge :variant="severityVariant(item.severity)">{{ severityLabel(item.severity) }}</AppBadge>
                </td>
                <td class="px-5 py-3 text-gray-600">{{ item.occurrence_count }} ครั้ง</td>
                <td class="px-5 py-3 text-gray-600">{{ item.assigned_to || 'ยังไม่มอบหมาย' }}</td>
                <td class="px-5 py-3 text-right whitespace-nowrap">
                  <AppButton
                    v-if="item.can_retry"
                    v-can="'system.operations.retry'"
                    size="sm"
                    variant="secondary"
                    @click="act(item, 'retry')"
                  >
                    สั่งซ้ำ
                  </AppButton>
                  <AppButton
                    v-can="'system.operations.retry'"
                    size="sm"
                    variant="secondary"
                    class="ml-1"
                    @click="act(item, 'acknowledge')"
                  >
                    รับทราบ
                  </AppButton>
                  <AppButton
                    v-can="'system.operations.retry'"
                    size="sm"
                    class="ml-1"
                    @click="act(item, 'resolve')"
                  >
                    ปิดเรื่อง
                  </AppButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ธงความสามารถ -->
      <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <header class="px-5 py-4 border-b border-gray-100">
          <h2 class="font-semibold text-gray-800">ธงเปิด-ปิดความสามารถ</h2>
          <p class="text-sm text-gray-500 mt-0.5">
            ธงไม่ใช่สิทธิ์ — ปิดแล้วปิดกับทุกคน และเปิดแล้วไม่ได้ให้สิทธิ์ใครเพิ่ม
          </p>
        </header>

        <ul class="divide-y divide-gray-50">
          <li v-for="flag in center.feature_flags" :key="flag.key" class="px-5 py-4 flex items-start justify-between gap-4">
            <div class="min-w-0">
              <p class="font-medium text-gray-900">{{ flag.name }}</p>
              <p class="text-sm text-gray-500 mt-0.5">{{ flag.description }}</p>
              <p class="text-xs text-gray-400 mt-0.5 font-mono">{{ flag.key }}</p>
            </div>

            <div class="flex items-center gap-2 shrink-0">
              <AppBadge :variant="flag.enabled ? 'success' : 'gray'">
                {{ flag.enabled ? 'เปิด' : 'ปิด' }}
              </AppBadge>
              <AppButton
                v-can="'system.feature_flags.manage'"
                size="sm"
                variant="secondary"
                :disabled="busy"
                @click="toggleFlag(flag)"
              >
                {{ flag.enabled ? 'ปิด' : 'เปิด' }}
              </AppButton>
            </div>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../../axios'
import OperationsTools from '../../../components/admin/OperationsTools.vue'
import { getErrorMessage } from '../../../utils/errorHandler'
import AppBadge from '../../../components/ui/AppBadge.vue'
import AppButton from '../../../components/ui/AppButton.vue'
import EmptyState from '../../../components/ui/EmptyState.vue'
import ErrorState from '../../../components/ui/ErrorState.vue'
import LoadingState from '../../../components/ui/LoadingState.vue'

/**
 * ศูนย์เฝ้าระวังการปฏิบัติการ (SoT §19, §20, §21 · INT-17)
 *
 * **งานตามเวลาที่หยุดทำงานอยู่บนสุดโดยตั้งใจ** — เป็นความผิดปกติชนิดเดียว
 * ที่ไม่มีอะไรพัง ไม่มี error ให้จับ และเงียบสนิทจนกว่าจะมีคนโทรมาถามว่า
 * ทำไมไม่ได้รับการแจ้งเตือน · ถ้ามันอยู่ท้ายหน้า มันจะไม่ถูกเห็น
 */
const SEVERITY_LABELS = { info: 'ทั่วไป', warning: 'ควรตรวจสอบ', critical: 'วิกฤต' }

const center = ref({})
const exceptions = ref([])
const loading = ref(true)
const error = ref('')
const busy = ref(false)

const severityLabel = (severity) => SEVERITY_LABELS[severity] || severity
const severityVariant = (severity) =>
  severity === 'critical' ? 'danger' : severity === 'warning' ? 'warning' : 'gray'

const tiles = computed(() => [
  {
    label: 'รายการที่ต้องตามแก้',
    value: center.value.exceptions?.open ?? 0,
    alert: (center.value.exceptions?.open ?? 0) > 0,
    hint: `วิกฤต ${center.value.exceptions?.critical_open ?? 0} รายการ`,
  },
  {
    label: 'ยังไม่มอบหมาย',
    value: center.value.exceptions?.unassigned ?? 0,
    alert: (center.value.exceptions?.unassigned ?? 0) > 0,
  },
  {
    label: 'แจ้งเตือนส่งไม่สำเร็จ',
    value: center.value.notifications?.dead_letters ?? 0,
    alert: (center.value.notifications?.dead_letters ?? 0) > 0,
  },
  {
    label: 'webhook ส่งไม่สำเร็จ',
    value: center.value.webhooks?.dead_letters ?? 0,
    alert: (center.value.webhooks?.dead_letters ?? 0) > 0,
    hint: `รอส่ง ${center.value.webhooks?.pending ?? 0} รายการ`,
  },
])

const load = async () => {
  loading.value = true
  error.value = ''

  try {
    const [summary, queue] = await Promise.all([
      api.get('/admin/operations/command-center'),
      api.get('/admin/operations/exceptions'),
    ])
    center.value = summary.data
    exceptions.value = queue.data.data
  } catch (err) {
    error.value = getErrorMessage(err, 'โหลดศูนย์เฝ้าระวังไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

const act = async (item, action) => {
  busy.value = true

  try {
    await api.post(`/admin/operations/exceptions/${item.id}/${action}`)
    await load()
  } catch (err) {
    error.value = getErrorMessage(err, 'ดำเนินการไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

const toggleFlag = async (flag) => {
  busy.value = true

  try {
    await api.put(`/admin/feature-flags/${flag.key}`, { enabled: !flag.enabled })
    await load()
  } catch (err) {
    error.value = getErrorMessage(err, 'เปลี่ยนธงไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>
