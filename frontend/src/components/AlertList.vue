<template>
  <div class="fixed top-4 right-4 z-[9999] flex flex-col gap-2 pointer-events-none">
    <transition-group name="alert" tag="div" class="flex flex-col gap-2 items-end">
      <div 
        v-for="alert in alertStore.alerts" 
        :key="alert.id"
        class="pointer-events-auto max-w-sm w-auto min-w-[300px] bg-white shadow-lg rounded-xl flex ring-1 ring-black ring-opacity-5 overflow-hidden transform transition-all"
        :class="{
          'border-l-4 border-green-500': alert.type === 'success',
          'border-l-4 border-red-500': alert.type === 'error',
          'border-l-4 border-blue-500': alert.type === 'info'
        }"
      >
        <div class="p-4 flex items-start w-full">
          <div class="flex-shrink-0">
            <!-- Success Icon -->
            <svg v-if="alert.type === 'success'" class="h-6 w-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <!-- Error Icon -->
            <svg v-if="alert.type === 'error'" class="h-6 w-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <!-- Info Icon -->
            <svg v-if="alert.type === 'info'" class="h-6 w-6 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div class="ml-3 flex-1 pt-0.5">
            <p class="text-sm font-medium text-gray-900">{{ alert.message }}</p>
          </div>
          <div class="ml-4 flex-shrink-0 flex">
            <button @click="alertStore.removeAlert(alert.id)" class="bg-white rounded-md inline-flex text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#800000]">
              <span class="sr-only">ปิดการแจ้งเตือน</span>
              <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.x"/>
              </svg>
            </button>
          </div>
        </div>
      </div>
    </transition-group>
  </div>
</template>

<script setup>
import { useAlertStore } from '../stores/alert'
import { ICONS } from '../utils/icons'
const alertStore = useAlertStore()
</script>

<style>
.alert-enter-active,
.alert-leave-active {
  transition: all 0.3s ease;
}
.alert-enter-from {
  opacity: 0;
  transform: translateX(30px);
}
.alert-leave-to {
  opacity: 0;
  transform: scale(0.9);
}
</style>
