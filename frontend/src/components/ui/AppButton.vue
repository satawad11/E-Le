<template>
  <component
    :is="tag"
    :type="tag === 'button' ? type : undefined"
    :disabled="tag === 'button' ? (disabled || loading) : undefined"
    :aria-disabled="disabled || loading || undefined"
    :aria-busy="loading || undefined"
    :class="classes"
    v-bind="$attrs"
  >
    <span v-if="loading" class="inline-flex items-center gap-2">
      <svg class="animate-spin" :class="spinnerSize" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
      </svg>
      <slot>กอบแตรเกี่ด...</slot>
    </span>
    <slot v-else />
  </component>
</template>

<script setup>
import { computed } from 'vue';
const props = defineProps({
  tag:      { type: String,  default: 'button' },
  type:     { type: String,  default: 'button' },
  variant:  { type: String,  default: 'primary', validator: v => ['primary','danger','ghost','outline','secondary'].includes(v) },
  size:     { type: String,  default: 'md',      validator: v => ['sm','md','lg'].includes(v) },
  loading:  { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  block:    { type: Boolean, default: false }
});
const variantClasses = {
  primary:   'bg-[#800000] hover:bg-[#600000] active:bg-[#500000] text-white shadow-sm border border-transparent',
  danger:    'bg-red-600 hover:bg-red-700 active:bg-red-800 text-white shadow-sm border border-transparent',
  ghost:     'bg-transparent hover:bg-[#800000]/10 active:bg-[#800000]/20 text-[#800000] border border-transparent',
  outline:   'bg-transparent hover:bg-gray-50 active:bg-gray-100 text-gray-700 border border-gray-300',
  secondary: 'bg-gray-100 hover:bg-gray-200 active:bg-gray-300 text-gray-700 border border-transparent'
};
const sizeClasses = {
  sm: 'px-3 py-1.5 text-xs font-semibold rounded-lg min-h-[32px]',
  md: 'px-4 py-2.5 text-sm font-semibold rounded-xl min-h-[44px]',
  lg: 'px-6 py-3 text-base font-semibold rounded-xl min-h-[52px]'
};
const spinnerSize = computed(() => {
  const map = { sm: 'w-3.5 h-3.5', md: 'w-4 h-4', lg: 'w-5 h-5' };
  return map[props.size];
});
const classes = computed(() => [
  'inline-flex items-center justify-center gap-2 transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#800000] focus-visible:ring-offset-1 select-none',
  variantClasses[props.variant],
  sizeClasses[props.size],
  (props.disabled || props.loading) ? 'opacity-50 cursor-not-allowed pointer-events-none' : 'cursor-pointer',
  props.block ? "w-full' : ''
]);
</script>
