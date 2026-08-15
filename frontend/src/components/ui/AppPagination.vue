<template>
  <nav v-if="totalPages > 1" class="flex items-center justify-center gap-1 flex-wrap" aria-label="การแบ่งหน้า">
    <button type="button" :disabled="page === 1" @click="goTo(page - 1)"
      class="p-2 w-9 h-9 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#800000] transition-colors"
      aria-label="หน้าก่อนหน้า">
      <svg class="w-4 h-4 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </button>
    <template v-for="p in displayedPages" :key="p">
      <span v-if="p === '...'" class="w-9 h-9 flex items-center justify-center text-gray-400 text-sm select-none" aria-hidden="true">...</span>
      <button v-else type="button" @click="goTo(p)"
        :aria-current="p === page ? 'page' : undefined"
        :class="[p === page ? 'bg-[#800000] text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100', 'w-9 h-9 rounded-lg text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#800000] transition-colors']">
        {{ p }}
      </button>
    </template>
    <button type="button" :disabled="page === totalPages" @click="goTo(page + 1)"
      class="p-2 w-9 h-9 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#800000] transition-colors"
      aria-label="หน้าถัดไป">
      <svg class="w-4 h-4 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    </button>
  </nav>
  <p v-if="showInfo && total > 0" class="text-center text-xs text-gray-400 mt-2">
    แสดง {{ startItem }}-{{ endItem }} จาก {{ total }} รายการ
  </p>
</template>
<script setup>
import { computed } from "vue";
const props = defineProps({
  page:     { type: Number, required: true },
  total:    { type: Number, required: true },
  perPage:  { type: Number, default: 15 },
  maxPages: { type: Number, default: 7 },
  showInfo: { type: Boolean, default: true }
});
const emit = defineEmits(["update:page"]);
const totalPages = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)));
const startItem  = computed(() => Math.min((props.page - 1) * props.perPage + 1, props.total));
const endItem    = computed(() => Math.min(props.page * props.perPage, props.total));
const displayedPages = computed(() => {
  const total = totalPages.value, cur = props.page, max = props.maxPages;
  if (total <= max) return Array.from({ length: total }, (_, i) => i + 1);
  const pages = [], half = Math.floor(max / 2);
  let start = Math.max(1, cur - half), end = Math.min(total, start + max - 1);
  if (end - start < max - 1) start = Math.max(1, end - max + 1);
  if (start > 1) { pages.push(1); if (start > 2) pages.push("..."); }
  for (let i = start; i <= end; i++) pages.push(i);
  if (end < total) { if (end < total - 1) pages.push("..."); pages.push(total); }
  return pages;
});
function goTo(p) {
  if (p < 1 || p > totalPages.value || p === props.page) return;
  emit("update:page", p);
}
</script>