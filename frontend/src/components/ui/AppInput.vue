<template>
  <div class="w-full">
    <label v-if="label" :for="inputId" class="block text-sm font-semibold text-gray-700 mb-1.5">
      {{ label }}<span v-if="required" class="text-red-500 ml-0.5" aria-hidden="true">*</span>
    </label>
    <div class="relative">
      <div v-if="$slots.prepend" class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
        <slot name="prepend" />
      </div>
      <input :id="inputId" v-bind="$attrs" :value="modelValue" :disabled="disabled" :required="required"
        :aria-describedby="ariaDescribedBy" :aria-invalid="!!error || undefined" :class="inputClasses"
        @input="$emit('update:modelValue', $event.target.value)" />
      <div v-if="$slots.append" class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-400">
        <slot name="append" />
      </div>
    </div>
    <p v-if="error" :id="errorId" class="mt-1 text-xs text-red-600" role="alert">{{ error }}</p>
    <p v-else-if="hint" :id="hintId" class="mt-1 text-xs text-gray-400">{{ hint }}</p>
  </div>
</template>
<script setup>
import { computed } from "vue";
const uid = Math.random().toString(36).slice(2);
const props = defineProps({
  modelValue: { default: "" },
  label:    { type: String,  default: "" },
  error:    { type: String,  default: "" },
  hint:     { type: String,  default: "" },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false }
});
defineEmits(["update:modelValue"]);
defineOptions({ inheritAttrs: false });
const inputId  = "app-input-"  + uid;
const errorId  = "app-input-err-"  + uid;
const hintId   = "app-input-hint-" + uid;
const ariaDescribedBy = computed(() => props.error ? errorId : props.hint ? hintId : undefined);
const hasPrepend = computed(() => false);
const inputClasses = computed(() => [
  "w-full px-4 py-2.5 rounded-xl border text-sm text-gray-900 bg-white transition-colors focus:outline-none",
  props.error
    ? "border-red-400 focus:border-red-500 focus:ring-2 focus:ring-red-400/20"
    : "border-gray-200 focus:border-[#800000] focus:ring-2 focus:ring-[#800000]/10",
  props.disabled ? "opacity-60 cursor-not-allowed bg-gray-50" : ""
].filter(Boolean).join(" "));
</script>