<template>
  <main class="mx-auto max-w-4xl space-y-5 p-4 sm:p-6" aria-labelledby="history-title">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 id="history-title" class="text-2xl font-bold text-gray-900">ประวัติการเข้าถึงข้อมูลของฉัน</h1>
        <p class="text-sm text-gray-500">
          รายการที่เจ้าหน้าที่เปิดดูหรือแก้ไขข้อมูลส่วนบุคคลของท่าน
          ตามมาตรา 30 พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562
        </p>
      </div>
      <router-link to="/profile" class="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
        กลับไปหน้าโปรไฟล์
      </router-link>
    </header>

    <div v-if="loading" role="status" class="rounded-2xl border bg-white p-10 text-center text-gray-500">
      กำลังโหลด...
    </div>

    <template v-else>
      <p class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-900">
        ระบบไม่บันทึกกรณีที่ท่านเปิดดูข้อมูลของตนเอง — แสดงเฉพาะการเข้าถึงโดยผู้อื่นเท่านั้น
        หากพบรายการที่ท่านสงสัย โปรดติดต่องานบุคลากรของวิทยาลัย
      </p>

      <section class="overflow-hidden rounded-2xl border bg-white">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
              <tr>
                <th class="p-3 text-left">วันเวลา</th>
                <th class="p-3 text-left">ผู้เข้าถึง</th>
                <th class="p-3 text-left">รายการ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.id" class="border-t">
                <td class="whitespace-nowrap p-3">{{ thaiDateTime(row.created_at) }}</td>
                <td class="p-3">
                  <p class="font-medium">{{ row.accessed_by }}</p>
                  <p v-if="row.accessed_by_position" class="text-xs text-gray-500">{{ row.accessed_by_position }}</p>
                </td>
                <td class="p-3">
                  <p>{{ row.purpose || resourceLabels[row.resource] || row.resource }}</p>
                  <p class="text-xs text-gray-400">{{ row.resource }}</p>
                </td>
              </tr>
              <tr v-if="!rows.length">
                <td colspan="3" class="p-10 text-center text-gray-500">
                  ยังไม่มีผู้ใดเข้าถึงข้อมูลส่วนบุคคลของท่าน
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="lastPage > 1" class="flex items-center justify-between border-t bg-gray-50 px-4 py-3 text-sm">
          <button
            type="button"
            class="rounded-lg border px-3 py-1.5 disabled:opacity-40"
            :disabled="page <= 1"
            @click="go(page - 1)"
          >ก่อนหน้า</button>
          <span class="text-gray-600">หน้า {{ page }} จาก {{ lastPage }} ({{ total }} รายการ)</span>
          <button
            type="button"
            class="rounded-lg border px-3 py-1.5 disabled:opacity-40"
            :disabled="page >= lastPage"
            @click="go(page + 1)"
          >ถัดไป</button>
        </div>
      </section>
    </template>
  </main>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../axios'
import { useAlertStore } from '../stores/alert'

const alerts = useAlertStore()
const loading = ref(true)
const rows = ref([])
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)

const resourceLabels = {
  leave_card: 'เปิดดูบัตรลา',
  user_profile: 'เปิดดูทะเบียนประวัติ',
  user_profile_edit: 'แก้ไขทะเบียนประวัติ',
}

const thaiDateTime = v => v
  ? new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(v))
  : '—'

const go = async (target) => {
  loading.value = true
  try {
    const { data } = await api.get('/profile/access-history', { params: { page: target } })
    rows.value = data.data
    page.value = data.current_page
    lastPage.value = data.last_page
    total.value = data.total
  } catch (e) {
    alerts.error(e.response?.data?.message || 'โหลดประวัติการเข้าถึงไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

onMounted(() => go(1))
</script>
