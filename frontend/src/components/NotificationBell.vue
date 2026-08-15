<template>
  <div class="relative inline-block text-left" @keydown.escape="isOpen = false">
    <button
      @click="isOpen = !isOpen"
      class="relative p-2.5 min-w-[44px] min-h-[44px] flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[#800000]"
      aria-label="การแจ้งเตือน"
      aria-haspopup="true"
      :aria-expanded="isOpen"
    >
      <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
      </svg>
      <span v-if="unreadCount > 0" class="absolute top-1.5 right-1.5 flex items-center justify-center w-4 h-4 text-[10px] font-bold text-white bg-red-500 border-2 border-white rounded-full" aria-label="มีรายการแจ้งเตือนที่ยังไม่ได้อ่าน">
        {{ unreadCount }}
      </span>
    </button>

    <div
      v-if="isOpen"
      role="region"
      aria-label="รายการแจ้งเตือนล่าสุด"
      class="absolute right-0 z-50 mt-2 w-[calc(100vw-2.5rem)] max-w-sm sm:w-80 bg-white border border-gray-100 rounded-2xl shadow-xl overflow-hidden origin-top-right focus:outline-none"
      tabindex="-1"
    >
      <div class="px-4 py-3 bg-gray-50 border-b border-gray-100 flex justify-between items-center">
        <h3 class="text-sm font-semibold text-gray-900">การแจ้งเตือน</h3>
        <button v-if="unreadCount > 0" @click="markAllAsRead" class="text-xs text-[#800000] hover:underline font-medium focus-visible:ring-1 focus-visible:ring-[#800000] rounded">อ่านทั้งหมด</button>
      </div>
      <div class="max-h-80 overflow-y-auto" role="feed" aria-busy="false">
        <div v-if="notifications.length === 0" class="p-6 text-center text-gray-500 text-sm">
          ไม่มีการแจ้งเตือนใหม่
        </div>
        <div v-for="notif in notifications" :key="notif.id" 
             class="px-4 py-3 border-b border-gray-50 hover:bg-gray-50 cursor-pointer transition-colors focus-visible:bg-gray-100 focus-visible:outline-none"
             :class="{ 'bg-blue-50/50': !notif.read_at }"
             tabindex="0"
             role="button"
             :aria-label="`${notif.read_at ? 'อ่านแล้ว' : 'ยังไม่ได้อ่าน'}: ${notif.data.message || 'มีการแจ้งเตือนใหม่'}`"
             @keydown.enter="markAsRead(notif.id)"
             @keydown.space.prevent="markAsRead(notif.id)"
             @click="markAsRead(notif.id)">
          <div class="flex items-start gap-3">
            <div class="flex-shrink-0 mt-1 text-[#800000]" aria-hidden="true">
              <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
            </div>
            <div>
              <p class="text-sm text-gray-800" :class="{'font-semibold': !notif.read_at}">{{ notif.data.message || 'มีการแจ้งเตือนใหม่' }}</p>
              <p class="text-xs text-gray-400 mt-1">{{ formatDate(notif.created_at) }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Backdrop for closing dropdown -->
    <div v-if="isOpen" @click="isOpen = false" class="fixed inset-0 z-40 bg-transparent" aria-hidden="true"></div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '../axios'
import { useAuthStore } from '../stores/auth'

const authStore = useAuthStore()
const isOpen = ref(false)
const notifications = ref([])

const unreadCount = computed(() => {
  return notifications.value.filter(n => !n.read_at).length
})

const fetchNotifications = async () => {
  if (!authStore.token) return;
  try {
    const res = await api.get('/notifications')
    notifications.value = res.data
  } catch (error) {
    console.error('Failed to load notifications')
  }
}

const markAllAsRead = async () => {
  try {
    await api.post('/notifications/mark-all-read')
    notifications.value.forEach(n => n.read_at = new Date().toISOString())
  } catch (error) {
    console.error('Failed to mark all as read')
  }
}

const markAsRead = async (id) => {
  const notif = notifications.value.find(n => n.id === id)
  if (!notif || notif.read_at) return
  try {
    await api.put(`/notifications/${id}/read`)
    notif.read_at = new Date().toISOString()
  } catch (error) {
    console.error('Failed to mark as read')
  }
}

const formatDate = (dateString) => {
  if (!dateString) return ''
  const d = new Date(dateString)
  return d.toLocaleString('th-TH', { month: 'short', day: 'numeric', hour: '2-digit', minute:'2-digit' })
}

onMounted(() => {
  fetchNotifications()
  // Refresh every 3 mins
  setInterval(fetchNotifications, 180000)
})
</script>
