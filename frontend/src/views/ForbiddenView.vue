<template>
  <div class="min-h-[70vh] flex items-center justify-center p-4">
    <PermissionDenied
      title="ไม่มีสิทธิ์เข้าถึงหน้านี้"
      message="คุณไม่มีสิทธิ์ในการเข้าถึงหน้าที่ร้องขอ หากต้องการสิทธิ์เพิ่มเติม กรุณาติดต่อผู้ดูแลระบบเพื่อตรวจสอบและมอบหมายสิทธิ์"
    />
  </div>
</template>

<script setup>
import PermissionDenied from '../components/ui/PermissionDenied.vue'
</script>
