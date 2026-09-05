<template>
  <section class="bg-white border rounded-2xl p-5 space-y-4">
    <h2 class="font-semibold">ตรวจระบบและจัดการการแจ้งเตือน</h2>
    <p v-if="error" class="text-red-700" role="alert">{{ error }}</p>
    <details v-if="auth.hasPermission('saraban.operations.view')">
      <summary class="cursor-pointer">สุขภาพระบบ</summary>
      <AppButton class="my-3" :loading="busy" @click="loadHealth">ตรวจสุขภาพระบบ</AppButton>
      <p v-if="health">ตรวจเมื่อ {{ health.checked_at }} · {{ healthLabel(health.summary?.status) }}</p>
      <ul><li v-for="(check, key) in health?.checks || {}" :key="key" class="text-sm py-1">{{ check.label || check.name || key }}: {{ healthLabel(check.status) }} {{ check.message || '' }}</li></ul>
    </details>
    <details v-if="auth.hasAnyPermission(['saraban.operations.view', 'saraban.feature_flags.manage'])">
      <summary class="cursor-pointer">การเปิดใช้ความสามารถ</summary>
      <AppButton class="my-3" :loading="busy" @click="loadFlags">ตรวจการเปิดใช้</AppButton>
      <ul class="space-y-3"><li v-for="flag in flags" :key="flag.key" class="flex flex-wrap gap-3 items-center"><span>{{ flag.label || flag.name || flag.key }} · {{ flag.enabled ? 'เปิดอยู่' : 'ปิดอยู่' }}</span><AppButton v-can="'saraban.feature_flags.manage'" variant="secondary" @click="toggleFlag(flag)">{{ flag.enabled ? 'ปิดความสามารถ' : 'เปิดความสามารถ' }}</AppButton></li></ul>
    </details>
    <details v-if="auth.hasPermission('system.setting.manage')">
      <summary class="cursor-pointer">การแจ้งเตือนที่ส่งไม่สำเร็จ</summary>
      <AppButton class="my-3" :loading="busy" @click="loadNotifications(1)">ตรวจช่องทางและข้อความค้าง</AppButton>
      <ul class="text-sm"><li v-for="c in channels" :key="c.channel">{{ channelLabel(c.channel) }}: ส่งแล้ว {{ c.sent }} · ไม่สำเร็จ {{ c.failed }} · รอส่ง {{ c.pending }}</li></ul>
      <p v-if="deliveries && !deliveries.length" class="my-3">ไม่มีข้อความที่ส่งไม่สำเร็จจนหมดรอบ</p>
      <ul class="divide-y"><li v-for="d in deliveries || []" :key="d.id" class="py-3 space-y-2"><p>{{ d.recipient || 'ผู้รับ' }} · {{ channelLabel(d.channel) }} · ลองแล้ว {{ d.attempt_count }} ครั้ง</p><p class="text-sm text-red-700">{{ d.last_error }}</p><AppButton variant="secondary" @click="requeue(d)">ส่งข้อความนี้ใหม่</AppButton></li></ul>
      <div v-if="meta" class="flex gap-3 items-center"><AppButton :disabled="busy || meta.current_page <= 1" @click="loadNotifications(meta.current_page - 1)">ก่อนหน้า</AppButton><span>หน้า {{ meta.current_page }} / {{ meta.last_page }}</span><AppButton :disabled="busy || meta.current_page >= meta.last_page" @click="loadNotifications(meta.current_page + 1)">ถัดไป</AppButton></div>
    </details>
    <ConfirmOperation v-if="operation" v-bind="operation" @close="operation = null" @done="operation = null" />
  </section>
</template>
<script setup>
import { ref } from 'vue'
import api from '../../axios'
import { useAuthStore } from '../../stores/auth'
import { getErrorMessage } from '../../utils/errorHandler'
import AppButton from '../ui/AppButton.vue'
import ConfirmOperation from './ConfirmOperation.vue'
const auth = useAuthStore()
const health = ref(null), flags = ref([]), channels = ref([]), deliveries = ref(null), meta = ref(null), operation = ref(null), error = ref(''), busy = ref(false)
const healthLabel = v => ({ ok: 'ปกติ', degraded: 'ควรตรวจสอบ', down: 'ขัดข้อง', disabled: 'ปิดอยู่' }[v] || 'ไม่ทราบสถานะ')
const channelLabel = v => ({ in_app: 'ในระบบ', email: 'อีเมล', web_push: 'เบราว์เซอร์', line: 'ไลน์', telegram: 'เทเลแกรม' }[v] || v)
async function read(fn) { busy.value = true; error.value = ''; try { await fn() } catch (e) { error.value = getErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ') } finally { busy.value = false } }
const loadHealth = () => read(async () => { health.value = (await api.get('/saraban/operations/health')).data })
const loadFlags = () => read(async () => { flags.value = (await api.get('/saraban/feature-flags')).data.flags })
const loadNotifications = (page) => read(async () => {
  const [summary, queue] = await Promise.all([api.get('/admin/notifications/channel-health'), api.get('/admin/notifications/dead-letters', { params: { page } })])
  channels.value = summary.data.channels; deliveries.value = queue.data.data; meta.value = queue.data.meta
})
function toggleFlag(flag) { operation.value = { title: 'เปลี่ยนการเปิดใช้', description: `${flag.enabled ? 'ปิด' : 'เปิด'} ${flag.label || flag.name || flag.key}`, action: async () => { flags.value = (await api.put(`/saraban/feature-flags/${flag.key}`, { enabled: !flag.enabled })).data.flags } } }
function requeue(d) { operation.value = { title: 'ส่งข้อความใหม่', description: `ส่งการแจ้งเตือนถึง ${d.recipient || 'ผู้รับเดิม'} อีกครั้งหลังแก้ต้นเหตุแล้ว`, action: async () => { await api.post(`/admin/notifications/dead-letters/${d.id}/requeue`); await loadNotifications(meta.value?.current_page || 1) } } }
</script>
