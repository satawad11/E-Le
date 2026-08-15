<template>
  <div class="flex flex-col items-center justify-center p-8 sm:p-12 text-center my-4 bg-gray-50/60 rounded-2xl border-2 border-dashed border-gray-200">
    <div class="w-16 h-16 sm:w-20 sm:h-20 bg-gray-100 rounded-full flex items-center justify-center text-gray-400 mb-4 shadow-inner">
      <i :class="[icon, 'text-2xl sm:text-3xl text-gray-400']"></i>
    </div>
    
    <h3 class="text-base sm:text-lg font-bold text-gray-800 mb-1">
      {{ title }}
    </h3>
    
    <p v-if="description" class="text-sm text-gray-500 max-w-sm mb-6 leading-relaxed">
      {{ description }}
    </p>

    <!-- Optional CTA button or slot -->
    <div v-if="actionText || $slots.action" class="mt-2">
      <slot name="action">
        <button
          v-if="actionText"
          @click="$emit('action')"
          class="inline-flex items-center gap-2 px-5 py-2.5 bg-[#800000] hover:bg-[#660000] text-white font-medium rounded-xl text-sm shadow-sm transition-all duration-200"
        >
          <i v-if="actionIcon" :class="actionIcon"></i>
          <span>{{ actionText }}</span>
        </button>
      </slot>
    </div>
  </div>
</template>

<script setup>
defineProps({
  icon: {
    type: String,
    default: 'fas fa-folder-open'
  },
  title: {
    type: String,
    default: 'ไม่พบข้อมูล'
  },
  description: {
    type: String,
    default: 'ยังไม่มีรายการข้อมูลในส่วนนี้ หรือลองปรับเงื่อนไขการค้นหาใหม่'
  },
  actionText: {
    type: String,
    default: ''
  },
  actionIcon: {
    type: String,
    default: 'fas fa-plus'
  }
})

defineEmits(['action'])
</script>
