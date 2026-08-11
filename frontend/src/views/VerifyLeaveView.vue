<template>
  <main class="min-h-screen bg-gray-50 px-4 py-10">
    <div class="mx-auto max-w-lg">
      <header class="mb-6 text-center">
        <h1 class="text-xl font-bold text-[#800000]">ตรวจสอบใบลาอิเล็กทรอนิกส์</h1>
        <p class="text-sm text-gray-500">วิทยาลัยเทคนิคดอนเมือง</p>
      </header>

      <div v-if="loading" role="status" class="rounded-2xl border bg-white p-10 text-center text-gray-500">
        กำลังตรวจสอบ...
      </div>

      <div v-else-if="error" class="rounded-2xl border border-red-200 bg-red-50 p-8 text-center">
        <p class="text-lg font-bold text-red-800">ไม่พบเอกสาร</p>
        <p class="mt-1 text-sm text-red-700">{{ error }}</p>
      </div>

      <div v-else-if="result" class="overflow-hidden rounded-2xl border bg-white">
        <div class="px-6 py-5 text-center" :class="result.is_valid ? 'bg-emerald-50' : 'bg-amber-50'">
          <p class="text-2xl font-extrabold" :class="result.is_valid ? 'text-emerald-700' : 'text-amber-800'">
            {{ result.is_valid ? '✓ เอกสารถูกต้อง' : '⚠ เอกสารใช้อ้างอิงไม่ได้' }}
          </p>
          <p class="mt-1 text-sm" :class="result.is_valid ? 'text-emerald-700' : 'text-amber-800'">
            สถานะ: {{ result.status_label }}
          </p>
          <p v-if="result.content_altered" class="mt-2 rounded-lg bg-red-100 px-3 py-2 text-sm font-semibold text-red-800">
            ตรวจพบว่าข้อมูลถูกแก้ไขหลังการอนุมัติ
          </p>
        </div>

        <dl class="divide-y text-sm">
          <div class="flex justify-between gap-4 px-6 py-3">
            <dt class="text-gray-500">เลขที่ใบลา</dt><dd class="font-semibold">{{ result.document_number || '—' }}</dd>
          </div>
          <div class="flex justify-between gap-4 px-6 py-3">
            <dt class="text-gray-500">ผู้ลา</dt>
            <dd class="text-right font-semibold">
              {{ result.holder.name }}
              <span v-if="result.holder.position" class="block text-xs font-normal text-gray-500">{{ result.holder.position }}</span>
            </dd>
          </div>
          <div class="flex justify-between gap-4 px-6 py-3">
            <dt class="text-gray-500">ประเภทการลา</dt><dd class="font-semibold">{{ result.leave_type || '—' }}</dd>
          </div>
          <div class="flex justify-between gap-4 px-6 py-3">
            <dt class="text-gray-500">ช่วงวันที่ลา</dt>
            <dd class="text-right font-semibold">{{ thaiDate(result.start_date) }} – {{ thaiDate(result.end_date) }}</dd>
          </div>
          <div class="flex justify-between gap-4 px-6 py-3">
            <dt class="text-gray-500">จำนวนวัน</dt><dd class="font-semibold">{{ result.total_days }} วัน</dd>
          </div>
          <div v-if="result.approvers.length" class="px-6 py-3">
            <dt class="mb-1 text-gray-500">ผู้อนุมัติ</dt>
            <dd>
              <p v-for="(a, i) in result.approvers" :key="i" class="font-semibold">
                {{ a.name }}<span v-if="a.position" class="text-xs font-normal text-gray-500"> · {{ a.position }}</span>
              </p>
            </dd>
          </div>
        </dl>

        <p class="border-t bg-gray-50 px-6 py-3 text-center text-xs text-gray-500">
          ตรวจสอบเมื่อ {{ new Date(result.verified_at).toLocaleString('th-TH') }}
        </p>
      </div>
    </div>
  </main>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '../axios'

const route = useRoute()
const loading = ref(true)
const result = ref(null)
const error = ref('')

const thaiDate = v => v ? new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium' }).format(new Date(v)) : '—'

onMounted(async () => {
  try {
    const { data } = await api.get(`/leaves/verify/${route.params.hash}`)
    result.value = data
  } catch (e) {
    error.value = e.response?.data?.message || 'รหัสตรวจสอบไม่ถูกต้องหรือเอกสารถูกลบไปแล้ว'
  } finally {
    loading.value = false
  }
})
</script>
