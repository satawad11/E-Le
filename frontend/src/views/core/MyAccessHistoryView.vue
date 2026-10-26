<template>
  <div class="page">
    <div class="page-head">
      <div class="stack" style="gap: 4px">
        <h1 class="page-title">ประวัติการเข้าถึงข้อมูลของฉัน</h1>
        <span class="lead">ทุกครั้งที่มีผู้เปิดดูข้อมูลอ่อนไหวของคุณ ระบบบันทึกผู้เปิด เวลา และเหตุผล (พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล มาตรา 30)</span>
      </div>
    </div>
    <p v-if="error" class="banner bad" role="alert">{{ error }}</p>
    <section class="card" style="overflow: hidden">
      <div class="table-scroll">
        <table class="dt" style="min-width: 640px">
          <thead><tr><th>เวลา</th><th>ผู้เข้าถึง</th><th>ข้อมูลที่เปิดดู</th><th>เหตุผล</th></tr></thead>
          <tbody>
            <tr v-if="loading"><td colspan="4" class="empty">กำลังโหลด…</td></tr>
            <tr v-else-if="!rows.length"><td colspan="4" class="empty">ยังไม่มีผู้เปิดดูข้อมูลอ่อนไหวของคุณ</td></tr>
            <tr v-for="(l, i) in rows" :key="i">
              <td>{{ thaiDateTime(l.at) }}</td><td>{{ l.actor }}</td><td>{{ (l.fields || []).map(fieldLabel).join(', ') }}</td><td>{{ l.reason }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../../axios'
import { useAuthStore } from '../../stores/auth'
import { apiMessage, fieldLabel, thaiDateTime } from '../../utils/dm'

const auth = useAuthStore()
const rows = ref([])
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  const uuid = auth.user?.personnel?.uuid
  if (!uuid) {
    error.value = 'บัญชีนี้ไม่ได้ผูกกับข้อมูลบุคลากร'
    loading.value = false
    return
  }
  try {
    rows.value = (await api.get(`/personnel/${uuid}/access-log`)).data.data
  } catch (e) {
    error.value = apiMessage(e, 'โหลดประวัติไม่สำเร็จ')
  } finally {
    loading.value = false
  }
})
</script>
