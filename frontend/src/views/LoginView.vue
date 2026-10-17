<template>
  <div class="login-root">
    <!-- Left decorative panel -->
    <div class="login-panel">
      <div class="panel-glow g1"></div>
      <div class="panel-glow g2"></div>
      <div class="panel-glow g3"></div>

      <div class="panel-content">
        <div class="logo-icon">
          <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
        </div>
        <h2 class="panel-title">ระบบบริหารการลาออนไลน์</h2>
        <p class="panel-sub">ระบบบริหารจัดการวันลาออนไลน์<br>สำหรับองค์กรยุคใหม่</p>

        <div class="features">
          <div v-for="f in features" :key="f.text" class="feature-item">
            <svg class="feature-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="f.icon"/>
            </svg>
            <span>{{ f.text }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Right: login form -->
    <div class="login-form-wrap">
      <div class="login-card">
        <!-- Header -->
        <div class="mb-8">
          <div class="mobile-logo">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            E-Leave
          </div>
          <h1 class="form-title">ยินดีต้อนรับ</h1>
          <p class="form-subtitle">กรุณาเข้าสู่ระบบเพื่อดำเนินการต่อ</p>
        </div>

        <!-- Error alert -->
        <transition name="shake">
          <div v-if="errorMsg" class="error-alert" role="alert" aria-live="assertive">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            {{ errorMsg }}
          </div>
        </transition>

        <!-- Form -->
        <form @submit.prevent="handleLogin" class="space-y-5">
          <!-- Email or ID -->
          <div class="field-group">
            <label for="login-email" class="field-label">อีเมล หรือ เลขประจำตัวประชาชน</label>
            <div class="field-wrap" :class="{ 'field-focus': focusEmail }">
              <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"/>
              </svg>
              <input
                id="login-email"
                v-model="email"
                type="text"
                required
                autocomplete="username"
                placeholder="กรอกอีเมล หรือ เลขประจำตัวประชาชน"
                class="field-input"
                @focus="focusEmail = true"
                @blur="focusEmail = false"
              >
            </div>
          </div>

          <!-- Password -->
          <div class="field-group">
            <label for="login-password" class="field-label">รหัสผ่าน</label>
            <div class="field-wrap" :class="{ 'field-focus': focusPass }">
              <svg class="field-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
              </svg>
              <input
                id="login-password"
                v-model="password"
                :type="showPass ? 'text' : 'password'"
                required
                autocomplete="current-password"
                placeholder="กรอกรหัสผ่าน"
                class="field-input"
                @focus="focusPass = true"
                @blur="focusPass = false"
              >
              <button
                type="button"
                @click="showPass = !showPass"
                class="eye-btn focus-visible:ring-2 focus-visible:ring-[#800000] rounded"
                :aria-label="showPass ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'"
              >
                <svg v-if="!showPass" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                </svg>
              </button>
            </div>
          </div>

          <div class="text-right -mt-2">
            <RouterLink to="/forgot-password" class="text-sm font-medium text-indigo-600 hover:text-indigo-700">
              ลืมรหัสผ่าน?
            </RouterLink>
          </div>

          <!-- Submit button -->
          <button
            type="submit"
            :disabled="loading"
            class="submit-btn"
            id="btn-login"
          >
            <span v-if="loading" class="animate-spin rounded-full h-5 w-5 border-b-2 border-white flex-shrink-0" aria-hidden="true"></span>
            <span>{{ loading ? 'กำลังเข้าสู่ระบบ...' : 'เข้าสู่ระบบ' }}</span>
            <svg v-if="!loading" class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
            </svg>
          </button>
        </form>

        <!-- Quick fill accounts -->
        <div class="mt-8 pt-6 border-t border-gray-100">
          <p class="text-xs text-gray-400 text-center mb-3 font-semibold uppercase tracking-wider">บัญชีทดสอบ</p>
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
            <button
              v-for="acc in testAccounts"
              :key="acc.email"
              @click="fill(acc)"
              class="demo-btn"
              :class="{ 'col-span-2 sm:col-span-1': acc.wide }"
            >
              <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="acc.icon"/>
              </svg>
              <span>{{ acc.label }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useAlertStore } from '../stores/alert'
import { ICONS } from '../utils/icons'

const router = useRouter()
const authStore = useAuthStore()
const alertStore = useAlertStore()

const email    = ref('')
const password = ref('')
const loading  = ref(false)
const errorMsg = ref('')
const showPass = ref(false)
const focusEmail = ref(false)
const focusPass  = ref(false)

const features = [
  { icon: ICONS.clipboard, text: 'ยื่นใบลาออนไลน์ทุกประเภท' },
  { icon: ICONS.check,     text: 'อนุมัติใบลาผ่านระบบ' },
  { icon: ICONS.chart,     text: 'ติดตามสถิติวันลาแบบ Real-time' },
  { icon: ICONS.lock,      text: 'ระบบสิทธิ์ตามตำแหน่งงาน' },
]

const testAccounts = [
  { email: 'superadmin@example.com', label: 'ผู้ดูแลระบบสูงสุด', icon: ICONS.shieldCheck, id_card: '1111111111111' },
  { email: 'director@example.com',   label: 'ผู้อำนวยการ',     icon: ICONS.building,    id_card: '2222222222222' },
  { email: 'deputy@example.com',     label: 'รองผู้อำนวยการ',   icon: ICONS.briefcase,   id_card: '3333333333333' },
  { email: 'hrhead@example.com',     label: 'เจ้าหน้าที่ HR',    icon: ICONS.clipboard,   id_card: '4444444444444' },
  { email: 'doc@example.com',        label: 'สารบรรณกลาง',    icon: ICONS.documentDuplicate, id_card: 'doc@example.com' },
  { email: 'employee@example.com',   label: 'ครู/เจ้าหน้าที่',    icon: ICONS.user,        id_card: '5555555555555' },
]

const handleLogin = async () => {
  errorMsg.value = ''
  loading.value = true
  try {
    await authStore.login(email.value, password.value)
    alertStore.success('เข้าสู่ระบบสำเร็จ')
    router.push({ name: 'dashboard' })
  } catch (error) {
    errorMsg.value = error.response?.data?.message || 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'
  } finally {
    loading.value = false
  }
}

const fill = (acc) => {
  email.value    = acc.email
  password.value = acc.id_card === 'doc@example.com' ? 'password' : acc.id_card
  errorMsg.value = ''
}
</script>

<style scoped>
/* ====== Layout ====== */
.login-root {
  min-height: 100vh;
  display: flex;
  background: #f8f7f4;
  font-family: var(--font-sans);
}

/* ====== Left decorative panel ====== */
.login-panel {
  display: none;
  position: relative;
  overflow: hidden;
  background: linear-gradient(135deg, #800000 0%, #5a0000 50%, #3d0000 100%);
  flex-direction: column;
  justify-content: center;
  align-items: center;
  padding: 3rem;
}
@media (min-width: 1024px) {
  .login-panel { display: flex; width: 45%; }
}

.panel-glow {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.4;
  animation: floatBlob 8s ease-in-out infinite;
}
.g1 { width: 350px; height: 350px; background: #c0392b; top: -100px; left: -100px; animation-delay: 0s; }
.g2 { width: 280px; height: 280px; background: #e67e22; bottom: 0; right: -80px; animation-delay: 3s; }
.g3 { width: 200px; height: 200px; background: #922b21; top: 40%; left: 50%; animation-delay: 6s; }

@keyframes floatBlob {
  0%,100% { transform: translate(0, 0) scale(1); }
  50%      { transform: translate(20px, -20px) scale(1.08); }
}

.panel-content {
  position: relative;
  z-index: 1;
  color: white;
  text-align: center;
}

.logo-icon {
  width: 80px; height: 80px;
  background: rgba(255,255,255,0.15);
  backdrop-filter: blur(10px);
  border-radius: 24px;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 1.5rem;
  border: 1px solid rgba(255,255,255,0.25);
}

.panel-title {
  font-size: 2.5rem;
  font-weight: 800;
  letter-spacing: -0.03em;
  margin-bottom: 0.5rem;
}

.panel-sub {
  font-size: 1rem;
  color: rgba(255,255,255,0.7);
  line-height: 1.7;
  margin-bottom: 2.5rem;
}

.features { text-align: left; display: flex; flex-direction: column; gap: 0.75rem; }
.feature-item {
  display: flex; align-items: center; gap: 0.75rem;
  background: rgba(255,255,255,0.08);
  border: 1px solid rgba(255,255,255,0.12);
  backdrop-filter: blur(6px);
  border-radius: 12px;
  padding: 0.75rem 1rem;
  font-size: 0.9rem;
  color: rgba(255,255,255,0.9);
}
.feature-icon { width: 1.25rem; height: 1.25rem; flex-shrink: 0; }

/* ====== Right form panel ====== */
.login-form-wrap {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem 1rem;
}

.login-card {
  width: 100%;
  max-width: 440px;
  background: #fff;
  border-radius: 28px;
  padding: 2.5rem 2rem;
  box-shadow: 0 20px 60px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.06);
}

/* Mobile logo only shows on small screens */
.mobile-logo {
  display: none;
  align-items: center;
  gap: 0.5rem;
  background: #800000;
  color: white;
  font-weight: 800;
  font-size: 1.1rem;
  padding: 0.5rem 0.875rem;
  border-radius: 12px;
  width: fit-content;
  margin-bottom: 1.25rem;
}
@media (max-width: 1023px) {
  .mobile-logo { display: flex; }
}

.form-title {
  font-size: 1.875rem;
  font-weight: 800;
  color: #111827;
  letter-spacing: -0.02em;
  margin-bottom: 0.25rem;
}
.form-subtitle {
  font-size: 0.9rem;
  color: #6b7280;
}

/* Error alert */
.error-alert {
  display: flex; align-items: center; gap: 0.5rem;
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #dc2626;
  border-radius: 12px;
  padding: 0.75rem 1rem;
  font-size: 0.875rem;
  margin-bottom: 1rem;
  animation: shake 0.4s ease;
}
@keyframes shake {
  0%,100% { transform: translateX(0); }
  20%     { transform: translateX(-6px); }
  40%     { transform: translateX(6px); }
  60%     { transform: translateX(-4px); }
  80%     { transform: translateX(4px); }
}

/* Form fields */
.field-group { display: flex; flex-direction: column; gap: 0.4rem; }
.field-label { font-size: 0.875rem; font-weight: 600; color: #374151; }

.field-wrap {
  position: relative;
  display: flex; align-items: center;
  border: 2px solid #e5e7eb;
  border-radius: 14px;
  background: #f9fafb;
  transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
}
.field-wrap.field-focus {
  border-color: #800000;
  background: #fff;
  box-shadow: 0 0 0 4px rgba(128,0,0,0.08);
}
.field-icon {
  width: 1.2rem; height: 1.2rem;
  color: #9ca3af;
  position: absolute;
  left: 1rem;
  pointer-events: none;
  flex-shrink: 0;
}
.field-input {
  flex: 1;
  border: none;
  background: transparent;
  outline: none;
  padding: 0.875rem 1rem 0.875rem 2.75rem;
  font-size: 1rem;
  color: #111827;
  width: 100%;
}
.field-input::placeholder { color: #d1d5db; }
.eye-btn {
  position: absolute; right: 0.25rem;
  color: #9ca3af; cursor: pointer; padding: 0.5rem;
  background: none; border: none;
  display: flex; align-items: center; justify-content: center;
  min-width: 44px; min-height: 44px;
  transition: color 0.15s;
}
.eye-btn:hover { color: #800000; }

/* Submit button */
.submit-btn {
  width: 100%;
  display: flex; align-items: center; justify-content: center; gap: 0.5rem;
  padding: 0.9375rem 1.5rem;
  background: linear-gradient(135deg, #800000 0%, #5a0000 100%);
  color: white;
  font-weight: 700;
  font-size: 1rem;
  border: none;
  border-radius: 14px;
  cursor: pointer;
  transition: all 0.2s;
  box-shadow: 0 4px 14px rgba(128,0,0,0.35);
}
.submit-btn:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 6px 20px rgba(128,0,0,0.45);
}
.submit-btn:active:not(:disabled) { transform: translateY(0); }
.submit-btn:disabled { opacity: 0.7; cursor: not-allowed; }

/* Demo account buttons */
.demo-btn {
  display: flex; align-items: center; justify-content: center; gap: 0.4rem;
  padding: 0.5rem 0.75rem;
  background: #f9fafb;
  border: 1.5px solid #e5e7eb;
  border-radius: 10px;
  font-size: 0.8125rem;
  color: #374151;
  cursor: pointer;
  font-weight: 500;
  transition: all 0.15s;
}
.demo-btn:hover {
  background: #fff0f0;
  border-color: #800000;
  color: #800000;
}

/* Shake transition */
.shake-enter-active { animation: shake 0.4s ease; }
</style>
