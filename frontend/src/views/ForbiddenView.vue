<template>
  <div class="page">
    <section class="card pad stack" style="max-width: 640px">
      <span class="tag bad">403</span>
      <h1 class="page-title" style="font-size: 24px">ไม่มีสิทธิ์เข้าถึงหน้านี้</h1>
      <p style="margin: 0; font-size: 15px; line-height: 1.7">ตำแหน่งหรือชุดสิทธิ์ของคุณไม่ครอบคลุมหน้านี้ ถ้าต้องใช้งาน ให้ขอผ่านหัวหน้าหน่วยเพื่อส่งต่องานบุคลากร</p>
      <span v-if="route.query.from" class="sub mono">{{ route.query.from }}</span>
      <div class="row"><router-link to="/" class="btn pri">กลับหน้าหลัก</router-link></div>
    </section>
  </div>
</template>

<script setup>
import { useRoute } from 'vue-router'

const route = useRoute()
</script>
