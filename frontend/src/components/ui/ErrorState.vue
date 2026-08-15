<template>
  <div class="flex flex-col items-center justify-center p-8 sm:p-12 text-center my-4 bg-red-50/50 rounded-2xl border border-red-100">
    <div class="w-16 h-16 sm:w-20 sm:h-20 bg-red-100 rounded-full flex items-center justify-center text-red-500 mb-4 shadow-sm">
      <i :class="[icon, 'text-2xl sm:text-3xl text-red-500']"></i>
    </div>
    
    <h3 class="text-base sm:text-lg font-bold text-gray-800 mb-1">
      {{ title }}
    </h3>
    
    <p class="text-sm text-gray-600 max-w-md mb-6 leading-relaxed">
      {{ message }}
    </p>

    <div class="flex flex-wrap items-center justify-center gap-3">
      <button
        v-if="showRetry"
        @click="$emit('retry')"
        class="inline-flex items-center gap-2 px-5 py-2.5 bg-[#800000] hover:bg-[#660000] text-white font-medium rounded-xl text-sm shadow-sm transition-all duration-200"
      >
        <i class="fas fa-redo-alt"></i>
        <span>{{ retryText }}</span>
      </button>

      <slot name="extra"></slot>
    </div>
  </div>
</template>

<script setup>
defineProps({
  icon: {
    type: String,
    default: 'fas fa-exclamation-triangle'
  },
  title: {
    type: String,
    default: 'เกิดข้อผิดพลาดในการประมวลผล'
  },
  message: {
    type: String,
    default: 'ไม่สามารถโหลดข้อมูลได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง หรือตรวจสอบการเชื่อมต่ออินเทอร์เน็ต'
  },
  showRetry: {
    type: Boolean,
    default: true
  },
  retryText: {
    type: String,
    default: 'ลองใหม่อีกครั้ง'
  }
})

defineEmits(['retry'])
</script>
