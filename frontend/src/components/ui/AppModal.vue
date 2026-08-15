<template>
  <teleport to="body">
    <transition name="modal-fade">
      <div
        v-if="modelValue"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 overflow-y-auto"
        :aria-modal="true"
        role="dialog"
        :aria-labelledby="titleId"
        @click.self="onBackdropClick"
      >
        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" aria-hidden="true"></div>
        <div
          ref="panelRef"
          :class="['relative bg-white rounded-2xl shadow-2xl w-full my-8 flex flex-col max-h-[90vh]', sizeClass]"
          @keydown.escape="onClose"
          tabindex="-1"
        >
          <!-- Header -->
          <div v-if="title || closable" class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0">
            <slot name="header">
              <h2 v-if="title" :id="titleId" class="text-lg font-bold text-gray-900">{{ title }}</h2>
            </slot>
            <button
              v-if="closable"
              type="button"
              @click="onClose"
              class="ml-auto p-2 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#800000]"
              aria-label="ปิด"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
          <!-- Body -->
          <div class="overflow-y-auto flex-1 px-6 py-5">
            <slot />
          </div>
          <!-- Footer -->
          <div v-if="$slots.footer" class="px-6 py-4 border-t border-gray-100 flex-shrink-0">
            <slot name="footer" />
          </div>
        </div>
      </div>
    </transition>
  </teleport>
</template>
<script setup>
import { computed, ref, watch, nextTick } from "vue";
const props = defineProps({
  modelValue: { type: Boolean, required: true },
  title:      { type: String, default: "" },
  closable:   { type: Boolean, default: true },
  persistent: { type: Boolean, default: false },
  size:       { type: String, default: "md", validator: v => ["sm","md","lg","xl","full"].includes(v) }
});
const emit = defineEmits(["update:modelValue"]);
const panelRef = ref(null);
const titleId  = "app-modal-title-" + Math.random().toString(36).slice(2);
const sizeClass = computed(() => ({
  sm:   "max-w-sm",
  md:   "max-w-lg",
  lg:   "max-w-2xl",
  xl:   "max-w-4xl",
  full: "max-w-[95vw]"
}[props.size]));
function onClose() {
  if (!props.persistent) emit("update:modelValue", false);
}
function onBackdropClick() {
  if (!props.persistent) onClose();
}
watch(() => props.modelValue, async (val) => {
  if (val) {
    document.body.style.overflow = "hidden";
    await nextTick();
    panelRef.value?.focus();
  } else {
    document.body.style.overflow = "";
  }
});
</script>
<style scoped>
.modal-fade-enter-active,.modal-fade-leave-active{transition:opacity 0.2s ease;}
.modal-fade-enter-from,.modal-fade-leave-to{opacity:0;}
</style>