<template>
  <main class="min-h-screen bg-slate-50 flex items-center justify-center px-4">
    <section class="w-full max-w-md rounded-2xl bg-white p-8 shadow-lg">
      <h1 class="text-2xl font-bold text-slate-900">ตั้งรหัสผ่านใหม่</h1>
      <p class="mt-2 text-sm text-slate-600">รหัสผ่านต้องมีอย่างน้อย 12 ตัวอักษร</p>

      <form class="mt-6 space-y-4" @submit.prevent="submit">
        <label class="block text-sm font-medium text-slate-700">อีเมล
          <input v-model="email" required type="email" autocomplete="email" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        </label>
        <label class="block text-sm font-medium text-slate-700">รหัสผ่านใหม่
          <input v-model="password" required minlength="12" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        </label>
        <label class="block text-sm font-medium text-slate-700">ยืนยันรหัสผ่านใหม่
          <input v-model="passwordConfirmation" required minlength="12" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        </label>
        <p v-if="message" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ message }}</p>
        <p v-if="error" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
        <button :disabled="loading" class="w-full rounded-lg bg-indigo-600 px-4 py-2 font-medium text-white disabled:opacity-60">
          {{ loading ? 'กำลังบันทึก...' : 'บันทึกรหัสผ่านใหม่' }}
        </button>
      </form>
      <RouterLink to="/login" class="mt-5 block text-center text-sm text-indigo-600">กลับหน้าเข้าสู่ระบบ</RouterLink>
    </section>
  </main>
</template>

<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../axios'

const route = useRoute()
const router = useRouter()
const email = ref(String(route.query.email || ''))
const token = String(route.query.token || '')
const password = ref('')
const passwordConfirmation = ref('')
const loading = ref(false)
const message = ref('')
const error = ref('')

async function submit() {
  loading.value = true
  message.value = ''
  error.value = ''
  try {
    const response = await api.post('/reset-password', {
      token,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value
    })
    message.value = response.data.message
    window.setTimeout(() => router.push({ name: 'login' }), 1200)
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'ลิงก์ไม่ถูกต้องหรือหมดอายุแล้ว'
  } finally {
    loading.value = false
  }
}
</script>
