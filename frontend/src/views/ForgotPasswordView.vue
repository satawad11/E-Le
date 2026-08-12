<template>
  <main class="min-h-screen bg-slate-50 flex items-center justify-center px-4">
    <section class="w-full max-w-md rounded-2xl bg-white p-8 shadow-lg">
      <h1 class="text-2xl font-bold text-slate-900">ลืมรหัสผ่าน</h1>
      <p class="mt-2 text-sm text-slate-600">กรอกอีเมลที่ใช้ในระบบเพื่อรับลิงก์ตั้งรหัสผ่านใหม่</p>

      <form class="mt-6 space-y-4" @submit.prevent="submit">
        <label class="block text-sm font-medium text-slate-700">
          อีเมล
          <input v-model="email" required type="email" autocomplete="email" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-indigo-500 focus:outline-none">
        </label>
        <p v-if="message" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ message }}</p>
        <p v-if="error" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
        <button :disabled="loading" class="w-full rounded-lg bg-indigo-600 px-4 py-2 font-medium text-white disabled:opacity-60">
          {{ loading ? 'กำลังส่ง...' : 'ส่งลิงก์ตั้งรหัสผ่านใหม่' }}
        </button>
      </form>
      <RouterLink to="/login" class="mt-5 block text-center text-sm text-indigo-600">กลับหน้าเข้าสู่ระบบ</RouterLink>
    </section>
  </main>
</template>

<script setup>
import { ref } from 'vue'
import api from '../axios'

const email = ref('')
const loading = ref(false)
const message = ref('')
const error = ref('')

async function submit() {
  loading.value = true
  message.value = ''
  error.value = ''
  try {
    const response = await api.post('/forgot-password', { email: email.value })
    message.value = response.data.message
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'ไม่สามารถดำเนินการได้ กรุณาลองใหม่'
  } finally {
    loading.value = false
  }
}
</script>
