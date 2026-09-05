<template>
  <div class="min-h-[70vh] flex flex-col items-center justify-center p-6 text-center">
    <div class="w-24 h-24 bg-rose-50 rounded-3xl flex items-center justify-center text-[#800000] mb-6 ring-8 ring-rose-50/50 shadow-sm">
      <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="ICONS.globe"/>
      </svg>
    </div>

    <span class="px-3.5 py-1 bg-gray-100 text-gray-700 text-xs font-bold rounded-full uppercase tracking-wider mb-3">
      404 ไม่พบหน้า
    </span>

    <h1 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mb-2">
      ไม่พบหน้าที่คุณต้องการ
    </h1>

    <p class="text-sm sm:text-base text-gray-500 max-w-md mb-8 leading-relaxed">
      หน้าที่คุณกำลังพยายามเข้าถึงอาจถูกลบ ย้ายที่อยู่ หรือไม่มีอยู่ในระบบ กรุณาตรวจสอบที่อยู่ URL อีกครั้ง
    </p>

    <div class="flex flex-wrap items-center justify-center gap-3">
      <router-link
        to="/"
        class="inline-flex items-center gap-2 px-6 py-2.5 bg-[#800000] hover:bg-[#660000] text-white font-semibold rounded-xl text-sm shadow-md transition-all duration-200"
      >
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.home"/>
        </svg>
        <span>กลับสู่หน้าหลัก</span>
      </router-link>

      <button
        @click="goBack"
        class="inline-flex items-center gap-2 px-5 py-2.5 bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 font-medium rounded-xl text-sm shadow-sm transition-colors"
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
import { ICONS } from '../utils/icons'

const router = useRouter()

const goBack = () => {
  if (window.history.length > 2) {
    router.back()
  } else {
    router.push('/')
  }
}
</script>
