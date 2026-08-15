<template>
  <!-- 1. Spinner Mode -->
  <div v-if="type === 'spinner'" class="flex flex-col items-center justify-center p-8 text-center min-h-[160px]">
    <div class="inline-block animate-spin rounded-full h-10 w-10 border-4 border-gray-200 border-t-[#800000] mb-3"></div>
    <p class="text-sm font-medium text-gray-500">{{ message }}</p>
  </div>

  <!-- 2. Skeleton Table Mode -->
  <div v-else-if="type === 'skeleton-table'" class="w-full animate-pulse p-4 space-y-4">
    <!-- Header skeleton -->
    <div class="h-10 bg-gray-200 rounded-lg w-full"></div>
    <!-- Rows skeleton -->
    <div v-for="r in rows" :key="r" class="space-y-2 py-2 border-b border-gray-100">
      <div class="grid gap-4" :style="{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }">
        <div v-for="c in cols" :key="c" class="h-6 bg-gray-100 rounded"></div>
      </div>
    </div>
  </div>

  <!-- 3. Skeleton Card Mode -->
  <div v-else-if="type === 'skeleton-card'" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 animate-pulse p-2">
    <div v-for="n in rows" :key="n" class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm space-y-3">
      <div class="flex items-center justify-between">
        <div class="h-4 bg-gray-200 rounded w-1/3"></div>
        <div class="h-8 w-8 bg-gray-200 rounded-full"></div>
      </div>
      <div class="h-8 bg-gray-100 rounded w-1/2"></div>
      <div class="h-3 bg-gray-100 rounded w-3/4"></div>
    </div>
  </div>

  <!-- 4. Skeleton Form Mode -->
  <div v-else-if="type === 'skeleton-form'" class="space-y-4 animate-pulse p-4 max-w-2xl">
    <div v-for="n in rows" :key="n" class="space-y-1.5">
      <div class="h-4 bg-gray-200 rounded w-1/4"></div>
      <div class="h-10 bg-gray-100 rounded-xl w-full"></div>
    </div>
    <div class="h-11 bg-gray-200 rounded-xl w-32 mt-6"></div>
  </div>

  <!-- 5. Inline Small Spinner -->
  <div v-else-if="type === 'inline'" class="inline-flex items-center gap-2 text-sm text-gray-500">
    <div class="animate-spin rounded-full h-4 w-4 border-2 border-gray-200 border-t-[#800000]"></div>
    <span>{{ message }}</span>
  </div>
</template>

<script setup>
defineProps({
  type: {
    type: String,
    default: 'spinner',
    validator: (v) => ['spinner', 'skeleton-table', 'skeleton-card', 'skeleton-form', 'inline'].includes(v)
  },
  message: {
    type: String,
    default: 'กำลังโหลดข้อมูล...'
  },
  rows: {
    type: Number,
    default: 4
  },
  cols: {
    type: Number,
    default: 4
  }
})
</script>
