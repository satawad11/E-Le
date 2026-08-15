<template>
  <main class="min-h-screen bg-gray-50 flex items-center justify-center px-4">
    <section class="w-full max-w-md rounded-2xl bg-white p-8 shadow-xl border border-gray-100">
      <div class="mb-6 text-center">
        <div class="w-12 h-12 bg-[#800000]/10 text-[#800000] rounded-2xl flex items-center justify-center mx-auto mb-3">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
          </svg>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">ลืมรหัสผ่าน</h1>
        <p class="mt-1 text-sm text-gray-500">กรอกอีเมลที่ใช้ในระบบเพื่อรับลิงก์ตั้งรหัสผ่านใหม่</p>
      </div>

      <form class="space-y-4" @submit.prevent="submit">
        <label class="block text-sm font-semibold text-gray-700">
          อีเมล
          <input v-model="email" required type="email" autocomplete="email" class="mt-1.5 w-full rounded-xl border border-gray-300 px-4 py-2.5 focus:border-[#800000] focus:ring-2 focus:ring-[#800000]/20 focus:outline-none transition-colors">
        </label>
        <p v-if="message" class="rounded-xl bg-emerald-50 border border-emerald-200 p-3 text-sm text-emerald-700">{{ message }}</p>
        <p v-if="error" class="rounded-xl bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ error }}</p>
        <button :disabled="loading" class="w-full rounded-xl bg-[#800000] hover:bg-[#600000] px-4 py-3 font-semibold text-white transition-colors disabled:opacity-60 shadow-sm">
          {{ loading ? 'กำลังส่ง...' : 'ส่งลิงก์ตั้งรหัสผ่านใหม่' }}
        </button>
      </form>
      <RouterLink to="/login" class="mt-6 block text-center text-sm font-semibold text-[#800000] hover:underline">← กลับหน้าเข้าสู่ระบบ</RouterLink>
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
