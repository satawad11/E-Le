<template>
  <div class="flex flex-col items-center justify-center p-8 sm:p-16 text-center my-6 bg-white rounded-3xl border border-gray-100 shadow-sm max-w-xl mx-auto">
    <div class="w-20 h-20 bg-amber-50 rounded-full flex items-center justify-center text-amber-600 mb-6 ring-8 ring-amber-50/50">
      <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="ICONS.shieldWarning"/>
      </svg>
    </div>
    
    <span class="px-3 py-1 bg-amber-100 text-amber-800 text-xs font-semibold rounded-full uppercase tracking-wider mb-3">
      403 Forbidden
    </span>

    <h2 class="text-xl sm:text-2xl font-bold text-gray-800 mb-2">
      {{ title }}
    </h2>
    
    <p class="text-sm text-gray-500 mb-8 leading-relaxed max-w-md">
      {{ message }}
    </p>

    <div class="flex flex-wrap items-center justify-center gap-3">
      <router-link
        v-if="showHomeButton"
        to="/"
        class="inline-flex items-center gap-2 px-6 py-2.5 bg-[#800000] hover:bg-[#660000] text-white font-medium rounded-xl text-sm shadow-sm transition-all duration-200"
      >
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.home"/>
        </svg>
        <span>{{ homeText }}</span>
      </router-link>
      
      <button
        @click="goBack"
        class="inline-flex items-center gap-2 px-5 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium rounded-xl text-sm transition-colors"
      >
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.arrowLeft"/>
        </svg>
        <span>ย้อนกลับ</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router'
import { ICONS } from '../../utils/icons'

const router = useRouter()

defineProps({
  title: {
    type: String,
    default: 'ไม่มีสิทธิ์เข้าถึงหน้านี้'
  },
  message: {
    type: String,
    default: 'คุณไม่มีสิทธิ์ในการเข้าถึงหรือดำเนินการในส่วนนี้ หากต้องการเข้าถึง กรุณาติดต่อผู้ดูแลระบบเพื่อขออนุมัติสิทธิ์การใช้งาน'
  },
  showHomeButton: {
    type: Boolean,
    default: true
  },
  homeText: {
    type: String,
    default: 'กลับสู่หน้าหลัก'
  }
})

const goBack = () => {
  if (window.history.length > 2) {
    router.back()
  } else {
    router.push('/')
  }
}
</script>
