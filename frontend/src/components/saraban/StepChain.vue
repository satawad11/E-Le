<template>
  <ol class="flex flex-wrap items-stretch gap-2">
    <li v-for="(step, index) in steps" :key="step.id ?? index" class="flex items-stretch gap-2">
      <div class="border border-gray-200 rounded-xl px-4 py-3 bg-gray-50/60 min-w-[10rem]">
        <p class="text-xs text-gray-500">ขั้นที่ {{ index + 1 }}</p>
        <p class="font-medium text-gray-800">{{ step.name }}</p>
        <p class="text-xs text-gray-500 mt-0.5">
          {{ permissionLabels[step.assignee_permission] || step.assignee_permission }}
        </p>
        <!--
          ขั้นสุดท้ายคือขั้นที่ออกเลขหนังสือ — ต้องเห็นได้จากผังโดยไม่ต้องเปิดคู่มือ
          เพราะเป็นจุดที่ย้อนกลับไม่ได้
        -->
        <AppBadge v-if="step.is_final" variant="brand" class="mt-2">ลงนามและออกเลข</AppBadge>
      </div>

      <span
        v-if="index < steps.length - 1"
        class="self-center text-gray-400 text-lg"
        aria-hidden="true"
      >
        →
      </span>
    </li>
  </ol>
</template>

<script setup>
import AppBadge from '../ui/AppBadge.vue'

/**
 * ผังลำดับขั้นของสายการเดินหนังสือ — อ่านอย่างเดียว
 *
 * ใช้ทั้งในหน้าจัดการสายงานและที่อื่นที่ต้องแสดงว่าหนังสือจะผ่านใครบ้าง ·
 * แสดงเป็นแถวเรียงพร้อมลูกศร เพราะคำถามที่ผู้ดูแลถามคือ "ผ่านใครก่อนหลัง"
 * ซึ่งตารางตอบได้ช้ากว่าผังแถวเดียว
 */
defineProps({
  steps: { type: Array, default: () => [] },
  /** แผนที่ชื่อสิทธิ์ → คำอธิบายไทย ที่ server ส่งมา */
  permissionLabels: { type: Object, default: () => ({}) },
})
</script>
