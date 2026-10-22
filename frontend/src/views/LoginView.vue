<template>
  <div class="login-root">
    <!-- แผงซ้าย: ตัวตนของระบบ (ต้นแบบหน้า 1) -->
    <section class="brand" aria-label="เกี่ยวกับระบบ">
      <div class="brand-head">
        <div class="brand-mark" aria-hidden="true">DM</div>
        <div class="brand-name">
          <span class="brand-th">วิทยาลัยเทคนิคดอนเมือง</span>
          <span class="brand-en">Don Mueang Technical College</span>
        </div>
      </div>

      <div class="brand-body">
        <h1>ระบบบริหารจัดการ<br>ข้อมูลบุคลากร</h1>
        <p>ข้อมูลบุคลากร บัญชีผู้ใช้ และสิทธิ์การใช้งานของทุกโมดูลในวิทยาลัย อยู่ในที่เดียว</p>
        <ul class="brand-points">
          <li>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6z" /><path d="M9 12l2 2 4-4" /></svg>
            เห็นเฉพาะข้อมูลที่ตำแหน่งของคุณได้รับอนุญาต
          </li>
          <li>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
            ข้อมูลส่วนบุคคลคุ้มครองตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล
          </li>
          <li>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h9l4 4v14H6z" /><path d="M9 11h7M9 15h7M9 7h4" /></svg>
            ทุกการเข้าถึงข้อมูลสำคัญถูกบันทึก
          </li>
        </ul>
      </div>

      <span class="brand-foot">นักเรียนและผู้ปกครองเข้าใช้งานผ่านพอร์ทัลนักเรียน ซึ่งแยกจากระบบนี้</span>
    </section>

    <!-- แผงขวา: ฟอร์ม -->
    <section class="panel">
      <div class="panel-inner">
        <p v-if="notice" class="note info" role="status">{{ notice }}</p>

        <!-- ขั้นที่ 1: รหัสผ่าน -->
        <form v-if="step === 'login'" class="stack" @submit.prevent="submitLogin" novalidate>
          <div class="title">
            <h2>เข้าสู่ระบบ</h2>
            <span>สำหรับครูและบุคลากรวิทยาลัย</span>
          </div>

          <div v-if="options.allow_national_id_login" class="chips" role="radiogroup" aria-label="ใช้อะไรเข้าสู่ระบบ">
            <button type="button" role="radio" :aria-checked="mode === 'account'" class="chip" :class="{ on: mode === 'account' }" @click="mode = 'account'">อีเมลหรือรหัสบุคลากร</button>
            <button type="button" role="radio" :aria-checked="mode === 'national_id'" class="chip" :class="{ on: mode === 'national_id' }" @click="mode = 'national_id'">เลขประจำตัวประชาชน</button>
          </div>

          <label v-if="mode === 'account'" class="lbl">
            อีเมลวิทยาลัยหรือรหัสบุคลากร
            <input v-model.trim="identifier" class="inp" type="text" autocomplete="username" autocapitalize="off" spellcheck="false" required>
          </label>
          <label v-else class="lbl">
            เลขประจำตัวประชาชน 13 หลัก
            <input v-model.trim="nationalId" class="inp mono" type="text" inputmode="numeric" maxlength="13" pattern="\d{13}" autocomplete="off" required>
          </label>

          <label class="lbl">
            รหัสผ่าน
            <input v-model="password" class="inp" type="password" autocomplete="current-password" required>
          </label>

          <div class="row">
            <label class="check">
              <input v-model="remember" type="checkbox">
              จดจำอุปกรณ์นี้ {{ options.remember_device_days }} วัน
            </label>
            <router-link to="/forgot-password" class="link">ลืมรหัสผ่าน</router-link>
          </div>

          <p v-if="error" class="note bad" role="alert">{{ error }}</p>

          <button type="submit" class="btn pri" :disabled="busy">{{ busy ? 'กำลังตรวจสอบ…' : 'เข้าสู่ระบบ' }}</button>
        </form>

        <!-- ขั้นที่ 2: รหัส MFA หรือรหัสสำรอง -->
        <form v-else-if="step === 'mfa'" class="stack" @submit.prevent="submitMfa" novalidate>
          <div class="title">
            <h2>ยืนยันตัวตนอีกขั้น</h2>
            <span class="lead">{{ mfaLead }}</span>
          </div>

          <template v-if="!useRecovery">
            <OtpInput v-model="code" :disabled="busy" @complete="submitMfa" />
          </template>
          <label v-else class="lbl">
            รหัสสำรอง (ใช้ได้ครั้งเดียว)
            <input v-model.trim="recoveryCode" class="inp mono" type="text" autocomplete="one-time-code" placeholder="XXXXX-XXXXX" required>
          </label>

          <p v-if="error" class="note bad" role="alert">{{ error }}</p>

          <button type="submit" class="btn pri" :disabled="busy || !canSubmitMfa">{{ busy ? 'กำลังตรวจสอบ…' : 'ยืนยันและเข้าสู่ระบบ' }}</button>
          <div class="row">
            <button type="button" class="btn small" @click="backToLogin">ย้อนกลับ</button>
            <button type="button" class="link as-button" @click="toggleRecovery">{{ useRecovery ? 'ใช้รหัสจากแอป' : 'ใช้รหัสสำรอง' }}</button>
          </div>
          <p class="note info">หากเข้าจากอุปกรณ์ใหม่ ระบบจะส่งอีเมลแจ้งเตือนไปที่อีเมลของบัญชี</p>
        </form>

        <!-- ถูกบังคับใช้ MFA แต่ยังไม่เคยตั้ง -->
        <form v-else-if="step === 'setup'" class="stack" @submit.prevent="submitSetup" novalidate>
          <div class="title">
            <h2>ตั้งค่าการยืนยันตัวตน (MFA)</h2>
            <span class="lead">{{ reasonsText }} ต้องใช้ MFA สแกน QR ด้วยแอปยืนยันตัวตน (เช่น Google Authenticator, Microsoft Authenticator) แล้วกรอกรหัส 6 หลัก</span>
          </div>

          <div v-if="setup" class="qr-box">
            <!-- eslint-disable-next-line vue/no-v-html -- SVG สร้างโดย server จาก otpauth URI ไม่มีข้อมูลจากผู้ใช้ -->
            <div class="qr" role="img" aria-label="QR สำหรับเพิ่มบัญชีในแอปยืนยันตัวตน" v-html="setup.qr_svg"></div>
            <div class="secret">
              <span>สแกนไม่ได้? กรอกรหัสนี้ในแอป</span>
              <code class="mono">{{ groupedSecret }}</code>
            </div>
          </div>
          <p v-else class="note info">กำลังสร้างรหัส…</p>

          <OtpInput v-model="code" :disabled="busy || !setup" @complete="submitSetup" />

          <p v-if="error" class="note bad" role="alert">{{ error }}</p>

          <button type="submit" class="btn pri" :disabled="busy || code.length !== 6">{{ busy ? 'กำลังตรวจสอบ…' : 'ยืนยันและเปิดใช้ MFA' }}</button>
          <button type="button" class="btn small" @click="backToLogin">ย้อนกลับ</button>
        </form>

        <!-- รหัสสำรอง: แสดงครั้งเดียว -->
        <div v-else-if="step === 'recovery'" class="stack">
          <div class="title">
            <h2>เก็บรหัสสำรองไว้ในที่ปลอดภัย</h2>
            <span class="lead">ใช้แทนรหัสจากแอปได้รหัสละครั้งเมื่อโทรศัพท์หาย ระบบจะไม่แสดงรหัสชุดนี้อีก</span>
          </div>
          <ol class="codes mono" aria-label="รหัสสำรอง">
            <li v-for="c in recoveryCodes" :key="c">{{ c }}</li>
          </ol>
          <div class="row">
            <button type="button" class="btn small" @click="copyCodes">{{ copied ? 'คัดลอกแล้ว' : 'คัดลอกรหัสทั้งหมด' }}</button>
          </div>
          <label class="check">
            <input v-model="savedCodes" type="checkbox">
            ฉันเก็บรหัสสำรองไว้แล้ว
          </label>
          <button type="button" class="btn pri" :disabled="!savedCodes" @click="finish">เข้าสู่ระบบ</button>
        </div>

        <span class="fine">การใช้งานระบบถูกบันทึกตามนโยบายคุ้มครองข้อมูลส่วนบุคคลของวิทยาลัย · ติดปัญหาการเข้าใช้ ติดต่องานศูนย์ข้อมูลสารสนเทศ</span>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../axios'
import { useAuthStore } from '../stores/auth'
import OtpInput from '../components/auth/OtpInput.vue'

const router = useRouter()
const authStore = useAuthStore()

const options = ref({ allow_national_id_login: false, remember_device_days: 30 })
const step = ref('login')
const mode = ref('account')
const identifier = ref('')
const nationalId = ref('')
const password = ref('')
const remember = ref(false)
const busy = ref(false)
const error = ref('')
const notice = ref('')

const challenge = ref('')
const reasons = ref([])
const code = ref('')
const useRecovery = ref(false)
const recoveryCode = ref('')
const setup = ref(null)
const recoveryCodes = ref([])
const savedCodes = ref(false)
const copied = ref(false)

onMounted(async () => {
  try {
    notice.value = sessionStorage.getItem('login_notice') || ''
    sessionStorage.removeItem('login_notice')
  } catch (e) { /* storage ปิดอยู่ */ }
  try {
    const { data } = await api.get('/auth/options')
    options.value = { ...options.value, ...data }
  } catch (e) { /* ใช้ค่าเริ่มต้น: ไม่แสดงช่องเลขประจำตัวประชาชน */ }
})

const reasonsText = computed(() => (reasons.value.length ? reasons.value.join(' · ') : 'บัญชีนี้'))
const mfaLead = computed(() => `${reasonsText.value === 'บัญชีนี้' ? 'บัญชีนี้เปิดใช้ MFA' : reasonsText.value + ' ต้องใช้ MFA'} กรอกรหัส 6 หลักจากแอปยืนยันตัวตนในโทรศัพท์ของคุณ`)
const canSubmitMfa = computed(() => (useRecovery.value ? recoveryCode.value.length >= 10 : code.value.length === 6))
const groupedSecret = computed(() => (setup.value?.secret || '').replace(/(.{4})/g, '$1 ').trim())

const messageOf = (e, fallback) => {
  const data = e?.response?.data
  if (data?.errors) {
    const first = Object.values(data.errors)[0]
    if (Array.isArray(first) && first.length) return first[0]
  }
  return data?.message || fallback
}

const submitLogin = async () => {
  error.value = ''
  const login = mode.value === 'account' ? identifier.value : nationalId.value
  if (!login || !password.value) {
    error.value = mode.value === 'account' ? 'กรุณากรอกอีเมลหรือรหัสบุคลากร และรหัสผ่าน' : 'กรุณากรอกเลขประจำตัวประชาชนและรหัสผ่าน'
    return
  }
  if (mode.value === 'national_id' && !/^\d{13}$/.test(login)) {
    error.value = 'เลขประจำตัวประชาชนต้องเป็นตัวเลข 13 หลัก'
    return
  }

  busy.value = true
  try {
    const result = await authStore.login(login, password.value, { remember: remember.value })
    password.value = ''
    if (!result.mfa) {
      return finish()
    }
    challenge.value = result.challenge
    reasons.value = result.reasons || []
    code.value = ''
    if (result.enrolled) {
      step.value = 'mfa'
    } else {
      step.value = 'setup'
      setup.value = await authStore.startMfaSetup(result.challenge)
    }
  } catch (e) {
    error.value = messageOf(e, 'เข้าสู่ระบบไม่สำเร็จ กรุณาลองใหม่')
    if (e?.response?.data?.errors?.challenge) backToLogin()
  } finally {
    busy.value = false
  }
}

const submitMfa = async () => {
  if (busy.value || !canSubmitMfa.value) return
  error.value = ''
  busy.value = true
  try {
    await authStore.verifyMfa(challenge.value, useRecovery.value ? { recoveryCode: recoveryCode.value } : { code: code.value })
    finish()
  } catch (e) {
    code.value = ''
    error.value = messageOf(e, 'รหัสยืนยันไม่ถูกต้อง')
    if (e?.response?.status === 423 || e?.response?.data?.errors?.challenge) backToLogin(error.value)
  } finally {
    busy.value = false
  }
}

const submitSetup = async () => {
  if (busy.value || code.value.length !== 6) return
  error.value = ''
  busy.value = true
  try {
    recoveryCodes.value = await authStore.confirmMfaSetup(challenge.value, code.value)
    step.value = 'recovery'
  } catch (e) {
    code.value = ''
    error.value = messageOf(e, 'รหัสยืนยันไม่ถูกต้อง')
    if (e?.response?.status === 423 || e?.response?.data?.errors?.challenge) backToLogin(error.value)
  } finally {
    busy.value = false
  }
}

const toggleRecovery = () => {
  useRecovery.value = !useRecovery.value
  error.value = ''
}

const backToLogin = (message = '') => {
  step.value = 'login'
  challenge.value = ''
  setup.value = null
  code.value = ''
  recoveryCode.value = ''
  useRecovery.value = false
  error.value = typeof message === 'string' ? message : ''
}

const copyCodes = async () => {
  try {
    await navigator.clipboard.writeText(recoveryCodes.value.join('\n'))
    copied.value = true
  } catch (e) {
    copied.value = false
  }
}

const finish = () => {
  recoveryCodes.value = []
  router.push({ name: 'dashboard' })
}
</script>

<style scoped>
.login-root {
  --ink: #1B2430; --ink-2: #3F4853; --muted: #5F6875; --line: #C9CEC7; --bg: #F4F5F2;
  --pri: #1F5F8B; --pri-hover: #174C70; --info-bg: #E3EEF6; --info-ink: #1C4F73; --bad-bg: #F8E1DC; --bad-ink: #8E2F1E;
  min-height: 100vh; display: flex; flex-wrap: wrap; font-family: 'IBM Plex Sans Thai', var(--font-sans); color: var(--ink); background: var(--bg);
}
.brand { flex: 1 1 480px; box-sizing: border-box; background: #1B2430; color: #fff; padding: 56px 64px; display: flex; flex-direction: column; justify-content: space-between; gap: 48px; }
.brand-head { display: flex; align-items: center; gap: 14px; }
.brand-mark { width: 48px; height: 48px; border-radius: 12px; background: #fff; color: #1B2430; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; }
.brand-name { display: flex; flex-direction: column; gap: 2px; }
.brand-th { font-size: 16px; font-weight: 600; }
.brand-en { font-size: 13px; color: #C3C9C1; }
.brand-body { display: flex; flex-direction: column; gap: 20px; max-width: 520px; }
.brand-body h1 { margin: 0; font-size: 44px; line-height: 1.25; font-weight: 700; }
.brand-body p { margin: 0; font-size: 17px; line-height: 1.7; color: #D6DAD4; }
.brand-points { list-style: none; margin: 0; padding: 8px 0 0; display: flex; flex-direction: column; gap: 12px; }
.brand-points li { display: flex; align-items: center; gap: 12px; font-size: 15px; color: #E6E9E4; }
.brand-foot { font-size: 13px; color: #A9B0A7; }

.panel { flex: 1 1 480px; box-sizing: border-box; padding: 56px 32px; display: flex; align-items: center; justify-content: center; }
.panel-inner { width: 100%; max-width: 420px; display: flex; flex-direction: column; gap: 24px; }
.stack { display: flex; flex-direction: column; gap: 24px; }
.title { display: flex; flex-direction: column; gap: 6px; }
.title h2 { margin: 0; font-size: 28px; font-weight: 700; }
.title span { font-size: 15px; color: var(--muted); }
.title .lead { line-height: 1.6; }

.lbl { display: flex; flex-direction: column; gap: 6px; font-size: 14px; font-weight: 500; color: var(--ink-2); }
.inp { height: 48px; border: 1px solid var(--line); border-radius: 10px; padding: 0 14px; font: inherit; font-size: 15px; color: var(--ink); background: #fff; box-sizing: border-box; width: 100%; }
.inp:focus-visible, .chip:focus-visible, .btn:focus-visible, .link:focus-visible, .check input:focus-visible { outline: 2px solid var(--pri); outline-offset: 2px; }
.mono { font-family: 'IBM Plex Mono', ui-monospace, monospace; }

.row { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.check { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--ink-2); min-height: 44px; }
.check input { width: 18px; height: 18px; accent-color: var(--pri); }
.link { font-size: 14px; font-weight: 500; color: var(--pri); }
.link:hover { color: #164766; }
.as-button { background: none; border: 0; padding: 0; cursor: pointer; text-decoration: underline; font: inherit; font-size: 14px; font-weight: 500; min-height: 44px; }

.btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 48px; padding: 0 18px; border-radius: 10px; font: inherit; font-size: 15px; font-weight: 600; cursor: pointer; border: 1px solid #D5D9D3; background: #fff; color: var(--ink); white-space: nowrap; box-sizing: border-box; }
.btn:hover { background: var(--bg); }
.btn.pri { background: var(--pri); border-color: var(--pri); color: #fff; }
.btn.pri:hover { background: var(--pri-hover); }
.btn:disabled { opacity: .6; cursor: not-allowed; }
.btn.small { min-height: 44px; font-size: 14px; font-weight: 500; }

.chips { display: flex; flex-wrap: wrap; gap: 8px; }
.chip { display: inline-flex; align-items: center; min-height: 40px; padding: 0 14px; border-radius: 999px; border: 1px solid #D5D9D3; background: #fff; font: inherit; font-size: 13px; color: var(--ink-2); cursor: pointer; }
.chip.on { background: #1B2430; border-color: #1B2430; color: #fff; }

.note { margin: 0; padding: 12px 14px; border-radius: 10px; font-size: 13px; line-height: 1.6; }
.note.info { background: var(--info-bg); color: var(--info-ink); }
.note.bad { background: var(--bad-bg); color: var(--bad-ink); }

.qr-box { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; padding: 16px; border: 1px solid #E1E4DF; border-radius: 12px; background: #fff; }
.qr { width: 168px; height: 168px; flex: 0 0 auto; }
.qr :deep(svg) { width: 100%; height: 100%; }
.secret { display: flex; flex-direction: column; gap: 6px; font-size: 13px; color: var(--muted); min-width: 0; }
.secret code { font-size: 14px; color: var(--ink); word-break: break-all; }

.codes { margin: 0; padding: 16px 16px 16px 40px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 24px; background: #fff; border: 1px solid #E1E4DF; border-radius: 12px; font-size: 15px; }

.fine { font-size: 12px; color: var(--muted); line-height: 1.6; }

@media (max-width: 640px) {
  .brand { padding: 32px 16px; gap: 28px; }
  .brand-body h1 { font-size: 32px; }
  .panel { padding: 32px 16px; }
}
</style>
