<template>
  <span :class="classes" role="status">
    <span v-if="dot" class="inline-block w-1.5 h-1.5 rounded-full mr-1" :class="dotColorClass" aria-hidden="true"></span>
    <slot />
  </span>
</template>
<script setup>
import { computed } from "vue";
const props = defineProps({
  variant: { type: String, default: "gray", validator: v => ["success","warning","danger","info","brand","gray"].includes(v) },
  dot:     { type: Boolean, default: false }
});
const variantMap = {
  success: { badge: "bg-emerald-100 text-emerald-800", dot: "bg-emerald-500" },
  warning: { badge: "bg-amber-100 text-amber-800",     dot: "bg-amber-500"   },
  danger:  { badge: "bg-red-100 text-red-800",         dot: "bg-red-500"     },
  info:    { badge: "bg-blue-100 text-blue-800",       dot: "bg-blue-500"    },
  brand:   { badge: "bg-[#800000]/10 text-[#800000]",  dot: "bg-[#800000]"  },
  gray:    { badge: "bg-gray-100 text-gray-700",       dot: "bg-gray-400"   }
};
const classes = computed(() => ["inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold", variantMap[props.variant].badge]);
const dotColorClass = computed(() => variantMap[props.variant].dot);
</script>