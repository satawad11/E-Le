<template>
  <nav aria-label="เส้นทางนำทาง (Breadcrumb)" class="mb-4 flex items-center justify-between gap-3 text-sm">
    <!-- Breadcrumb Item Trail -->
    <ol class="flex flex-wrap items-center gap-1.5 text-gray-500">
      <li>
        <router-link
          to="/"
          class="hover:text-[#800000] flex items-center gap-1 transition-colors text-xs sm:text-sm"
          title="หน้าหลัก"
        >
          <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
          </svg>
          <span class="hidden sm:inline">หน้าหลัก</span>
        </router-link>
      </li>

      <li v-for="(item, index) in items" :key="index" class="flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5 text-gray-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>

        <router-link
          v-if="item.to && index < items.length - 1"
          :to="item.to"
          class="hover:text-[#800000] transition-colors text-xs sm:text-sm font-medium"
        >
          {{ item.title }}
        </router-link>
        <span
          v-else
          class="text-xs sm:text-sm font-semibold text-gray-900 truncate max-w-[200px] sm:max-w-none"
          :aria-current="index === items.length - 1 ? 'page' : undefined"
        >
          {{ item.title }}
        </span>
      </li>
    </ol>

    <!-- Contextual Back Button -->
    <button
      v-if="showBack"
      type="button"
      @click="handleBack"
      class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-600 hover:text-gray-900 text-xs font-semibold shadow-xs transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[#800000]"
    >
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
      </svg>
      {{ backLabel }}
    </button>
  </nav>
</template>

<script setup>
import { useRouter } from 'vue-router'

const props = defineProps({
  items: {
    type: Array,
    default: () => [],
  },
  showBack: {
    type: Boolean,
    default: true,
  },
  backTo: {
    type: [String, Object],
    default: null,
  },
  backLabel: {
    type: String,
    default: 'ย้อนกลับ',
  },
})

const router = useRouter()

const handleBack = () => {
  if (props.backTo) {
    router.push(props.backTo)
  } else if (window.history.length > 1) {
    router.back()
  } else {
    router.push('/')
  }
}
</script>
