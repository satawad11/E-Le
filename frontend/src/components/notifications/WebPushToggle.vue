<template>
  <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/60">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <p class="font-semibold text-gray-800 text-sm">การแจ้งเตือนผ่านเบราว์เซอร์</p>
        <p class="text-xs text-gray-500 mt-0.5">{{ statusText }}</p>
      </div>

      <AppButton
        v-if="available"
        size="sm"
        :variant="subscribed ? 'secondary' : 'primary'"
        :loading="busy"
        :disabled="busy"
        @click="toggle"
      >
        {{ subscribed ? 'ปิดบนเครื่องนี้' : 'เปิดบนเครื่องนี้' }}
      </AppButton>
    </div>

    <p v-if="error" class="text-xs text-red-600 mt-2">{{ error }}</p>
    <AppButton variant="ghost" size="sm" :disabled="busy" @click="listDevices">ดูอุปกรณ์ที่ลงทะเบียนไว้</AppButton>
    <ul v-if="devices !== null" class="text-xs text-gray-600 mt-2 space-y-2"><li v-if="!devices.length">ยังไม่มีอุปกรณ์ที่ลงทะเบียน</li><li v-for="device in devices" :key="device.uuid" class="break-words">{{ device.user_agent || 'อุปกรณ์ที่ไม่ระบุชื่อ' }} · ใช้ล่าสุด {{ device.last_used_at || 'ยังไม่มีการส่ง' }}</li></ul>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../axios'
import { getErrorMessage } from '../../utils/errorHandler'
import AppButton from '../ui/AppButton.vue'

/**
 * เปิด-ปิดการแจ้งเตือนผ่านเบราว์เซอร์บนเครื่องนี้ (SoT §16 · INT-14)
 *
 * **เป็นการตั้งค่าต่อเครื่อง ไม่ใช่ต่อคน** — ผู้ใช้คนเดียวเปิดที่เครื่องทำงาน
 * แต่ปิดที่มือถือได้ ปุ่มจึงเขียนว่า "บนเครื่องนี้" เสมอ ไม่ให้เข้าใจผิดว่า
 * เป็นสวิตช์รวมของบัญชี
 *
 * ต้องใช้ HTTPS หรือ localhost — เบราว์เซอร์ไม่ให้สมัครรับ push บน http
 * ที่ไม่ใช่ localhost คอมโพเนนต์จึงบอกเหตุผลตรง ๆ แทนที่จะแสดงปุ่มที่กดแล้วพัง
 */
const available = ref(false)
const subscribed = ref(false)
const busy = ref(false)
const error = ref('')
const reason = ref('')
const devices = ref(null)
async function listDevices() { busy.value = true; error.value = ''; try { devices.value = (await api.get('/notifications/web-push/subscriptions')).data.subscriptions } catch(e) { error.value = getErrorMessage(e, 'โหลดรายการอุปกรณ์ไม่สำเร็จ') } finally { busy.value = false } }

const statusText = computed(() => {
  if (!available.value) return reason.value
  if (subscribed.value) return 'เครื่องนี้จะได้รับการแจ้งเตือนแม้ปิดหน้าเว็บไว้'

  return 'เปิดเพื่อรับการแจ้งเตือนบนเครื่องนี้แม้ไม่ได้เปิดหน้าเว็บค้างไว้'
})

const supported = () =>
  'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

/** แปลงกุญแจ base64url ของ VAPID เป็น Uint8Array ตามที่ Push API ต้องการ */
const urlBase64ToUint8Array = (base64String) => {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = window.atob(base64)

  return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)))
}

const boot = async () => {
  if (!supported()) {
    reason.value = 'เบราว์เซอร์นี้ไม่รองรับการแจ้งเตือนผ่านเบราว์เซอร์'

    return
  }

  if (!window.isSecureContext) {
    reason.value = 'ต้องเปิดผ่าน HTTPS จึงจะใช้การแจ้งเตือนผ่านเบราว์เซอร์ได้'

    return
  }

  try {
    const { data } = await api.get('/notifications/web-push/key')

    if (!data.enabled) {
      reason.value = 'ผู้ดูแลระบบยังไม่ได้ตั้งค่ากุญแจสำหรับการแจ้งเตือนผ่านเบราว์เซอร์'

      return
    }

    available.value = true

    const registration = await navigator.serviceWorker.getRegistration('/sw-push.js')
    subscribed.value = !!(await registration?.pushManager.getSubscription())
  } catch (err) {
    reason.value = getErrorMessage(err, 'ตรวจสอบสถานะการแจ้งเตือนไม่สำเร็จ')
  }
}

const subscribe = async () => {
  if ((await Notification.requestPermission()) !== 'granted') {
    error.value = 'ท่านยังไม่ได้อนุญาตให้เบราว์เซอร์แสดงการแจ้งเตือน'

    return
  }

  const { data } = await api.get('/notifications/web-push/key')
  const registration = await navigator.serviceWorker.register('/sw-push.js')

  const subscription = await registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(data.public_key),
  })

  const payload = subscription.toJSON()

  await api.post('/notifications/web-push/subscribe', {
    endpoint: payload.endpoint,
    keys: payload.keys,
  })

  subscribed.value = true
}

const unsubscribe = async () => {
  const registration = await navigator.serviceWorker.getRegistration('/sw-push.js')
  const subscription = await registration?.pushManager.getSubscription()

  if (subscription) {
    /*
     * แจ้ง server ก่อนถอนที่เบราว์เซอร์ — ถ้าถอนที่เบราว์เซอร์ก่อนแล้วคำขอล้มเหลว
     * แถวใน server จะค้างและระบบจะพยายามส่งไปยังปลายทางที่ตายแล้วต่อไป
     */
    await api.post('/notifications/web-push/unsubscribe', { endpoint: subscription.endpoint })
    await subscription.unsubscribe()
  }

  subscribed.value = false
}

const toggle = async () => {
  busy.value = true
  error.value = ''

  try {
    await (subscribed.value ? unsubscribe() : subscribe())
  } catch (err) {
    error.value = getErrorMessage(err, 'เปลี่ยนการตั้งค่าไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

onMounted(boot)
</script>
