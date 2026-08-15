<template>
  <div class="w-full">
    <label v-if="label" :for="textareaId" class="block text-sm font-semibold text-gray-700 mb-1.5">
      {{ label }}<span v-if="required" class="text-red-500 ml-0.5" aria-hidden="true">*</span>
    </label>
    <textarea :id="textareaId" v-bind="$attrs" :value="modelValue" :rows="rows" :disabled="disabled" :required="required"
      :aria-describedby="error ? errorId : hint ? hintId : undefined" :aria-invalid="!!error || undefined"
      :class="textareaClasses" @input="$emit('update:modelValue', $event.target.value)" />
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
  rows:     { type: Number,  default: 3 },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  resize:   { type: String,  default: "none", validator: v => ["none","vertical","both"].includes(v) }
});
defineEmits(["update:modelValue"]);
defineOptions({ inheritAttrs: false });
const textareaId = "app-textarea-"  + uid;
const errorId    = "app-textarea-err-"  + uid;
const hintId     = "app-textarea-hint-" + uid;
const resizeClass = computed(() => ({ none: "resize-none", vertical: "resize-y", both: "resize" }[props.resize]));
const textareaClasses = computed(() => [
  "w-full px-4 py-2.5 rounded-xl border text-sm text-gray-900 bg-white transition-colors focus:outline-none",
  resizeClass.value,
  props.error
    ? "border-red-400 focus:border-red-500 focus:ring-2 focus:ring-red-400/20"
    : "border-gray-200 focus:border-[#800000] focus:ring-2 focus:ring-[#800000]/10",
  props.disabled ? "opacity-60 cursor-not-allowed bg-gray-50" : ""
].filter(Boolean).join(" "));
</script>