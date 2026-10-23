<template>
  <div class="page">
    <section class="card pad stack" style="max-width: 640px">
      <h1 class="page-title" style="font-size: 24px">ฟังก์ชันนี้ยังไม่เปิดใช้งานในระบบใหม่</h1>
      <p style="margin: 0; font-size: 15px; line-height: 1.7">หน้านี้เป็นของระบบเดิม (ลา ลงเวลา OT สารบรรณ หรือการตั้งค่า) ซึ่งกำลังย้ายเข้าระบบสิทธิ์กลาง จะเปิดใช้เมื่อย้ายเสร็จ</p>
      <span v-if="route.query.from" class="sub mono">{{ route.query.from }}</span>
      <div class="row"><router-link to="/" class="btn pri">กลับหน้าหลัก</router-link></div>
    </section>
  </div>
</template>

<script setup>
import { useRoute } from 'vue-router'

const route = useRoute()
</script>
