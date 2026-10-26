<template>
  <div class="acc">
    <div class="content">
      <div class="heading">
        <div class="heading-text">
          <h1>บัญชีผู้ใช้</h1>
          <span>บัญชีบุคลากรเปิด ปรับ และปิดอัตโนมัติตามข้อมูลบุคลากร</span>
        </div>
        <label class="search">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
          <input v-model="q" type="search" aria-label="ค้นหาบัญชี" placeholder="ค้นหาชื่อ อีเมล รหัสบุคลากร">
        </label>
      </div>

      <div class="chips" role="group" aria-label="กรองตามสถานะ">
        <button
          v-for="chip in chips"
          :key="chip.key"
          type="button"
          class="chip"
          :class="{ on: status === chip.key }"
          :aria-pressed="status === chip.key"
          @click="setStatus(chip.key)"
        >
          {{ chip.label }} {{ counts[chip.count] ?? 0 }}
        </button>
      </div>

      <p v-if="error" class="banner bad" role="alert">{{ error }}</p>

      <div class="layout">
        <section class="card table-card" aria-label="รายการบัญชี">
          <div class="table-scroll">
            <table>
              <thead>
                <tr>
                  <th scope="col">ผู้ใช้</th>
                  <th scope="col">บุคลากรที่ผูก</th>
                  <th scope="col">สถานะ</th>
                  <th scope="col">MFA</th>
                  <th scope="col">ที่มาของสิทธิ์</th>
                  <th scope="col">เข้าใช้ล่าสุด</th>
                  <th scope="col"><span class="sr-only">การดำเนินการ</span></th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="loading && !rows.length"><td colspan="7" class="empty">กำลังโหลด…</td></tr>
                <tr v-else-if="!rows.length"><td colspan="7" class="empty">ไม่พบบัญชีที่ตรงเงื่อนไข</td></tr>
                <tr v-for="row in rows" :key="row.uuid" :class="{ dim: row.status === 'separated' }">
                  <td>
                    <div class="who">
                      <span :class="row.account_type === 'service' ? 'mono strong' : 'strong'">{{ row.name }}</span>
                      <span class="sub">{{ row.account_type === 'service' ? 'บัญชีระบบ' : row.email }}</span>
                    </div>
                  </td>
                  <td>
                    <span v-if="row.personnel" class="mono">{{ row.personnel.personnel_code }}</span>
                    <span v-else class="tag mute">{{ row.is_break_glass ? 'บัญชีฉุกเฉิน' : 'ไม่ผูกบุคลากร' }}</span>
                  </td>
                  <td>
                    <div class="who">
                      <span class="tag" :class="statusTag(row.status)">{{ statusLabel(row.status) }}</span>
                      <span v-if="statusNote(row)" class="sub">{{ statusNote(row) }}</span>
                    </div>
                  </td>
                  <td>
                    <span v-if="row.status === 'separated' || row.status === 'pending'" class="sub">—</span>
                    <span v-else class="tag" :class="row.mfa.enabled ? 'ok' : (row.mfa.required ? 'warn' : 'mute')" :title="row.mfa.reasons.join(' · ')">
                      {{ row.mfa.enabled ? 'เปิด' : 'ปิด' }} · {{ row.mfa.required ? 'บังคับ' : 'ไม่บังคับ' }}
                    </span>
                  </td>
                  <td>{{ sourcesText(row) }}</td>
                  <td><span :class="{ sub: !row.last_login_at }">{{ lastLogin(row.last_login_at) }}</span></td>
                  <td>
                    <button v-if="row.abilities.manage && actionsFor(row).length" type="button" class="btn small" @click="openManage(row)">จัดการ</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="pager">
            <span class="sub">{{ meta.total ? `แสดง ${meta.from}–${meta.to} จาก ${meta.total} บัญชี` : '' }}</span>
            <div class="pager-buttons">
              <button type="button" class="btn" :disabled="page <= 1 || loading" @click="go(page - 1)">ก่อนหน้า</button>
              <button type="button" class="btn" :disabled="page >= (meta.last_page || 1) || loading" @click="go(page + 1)">ถัดไป</button>
            </div>
          </div>
        </section>

        <div class="side">
          <section class="card pad">
            <h2>วงจรบัญชีอัตโนมัติ เดือนนี้</h2>
            <div class="jml">
              <span class="tag ok badge">Joiner</span>
              <div class="who"><span class="strong">เปิดบัญชีใหม่ {{ lifecycle.joiner }}</span><span class="sub">จากบุคลากรสถานะ "ปฏิบัติงาน"</span></div>
            </div>
            <div class="jml">
              <span class="tag info badge">Mover</span>
              <div class="who"><span class="strong">ปรับสิทธิ์จากคำสั่ง {{ lifecycle.mover }}</span><span class="sub">แต่งตั้ง ย้าย รักษาการ</span></div>
            </div>
            <div class="jml">
              <span class="tag mute badge">Leaver</span>
              <div class="who"><span class="strong">ปิดบัญชี {{ lifecycle.leaver }}</span><span class="sub">ตัดเซสชัน ถอนสิทธิ์ แจ้งงานค้าง</span></div>
            </div>
          </section>

          <section v-if="review" class="card pad">
            <div class="row-between">
              <h2>ทบทวนสิทธิ์พิเศษ</h2>
              <span class="tag warn">{{ review.name }}</span>
            </div>
            <span>ยืนยันแล้ว {{ review.decided }} จาก {{ review.total }} รายการ</span>
            <div class="progress" role="img" :aria-label="`ความคืบหน้า ${review.decided} จาก ${review.total}`">
              <div :style="{ width: progressWidth }"></div>
            </div>
            <span class="sub">ครบกำหนด {{ thaiDate(review.due_at) }} รายการที่หัวหน้าหน่วยไม่ยืนยันจะถูกถอนอัตโนมัติ</span>
          </section>

          <section class="card pad">
            <h2>นโยบายความปลอดภัย</h2>
            <span>MFA บังคับ: L1–L3, งานบุคลากร, ผู้ดูแลระบบ, บัญชีฉุกเฉิน</span>
            <span>ล็อกบัญชี {{ policy.lockout_minutes }} นาทีหลังรหัสผิด {{ policy.lockout_attempts }} ครั้ง</span>
            <span>เซสชันหมดอายุ {{ policy.session_hours }} ชั่วโมง</span>
            <span>ระงับบัญชีที่ไม่เข้าใช้ {{ policy.inactive_days }} วัน</span>
            <span>แจ้งเตือนเมื่อเข้าจากอุปกรณ์ใหม่</span>
          </section>
        </div>
      </div>
    </div>

    <!-- จัดการบัญชี: ทุกการกระทำต้องระบุเหตุผล (บันทึกใน audit) -->
    <dialog ref="dialogEl" class="dialog" aria-labelledby="manage-title" @close="onDialogClose">
      <form v-if="selected" method="dialog" class="dialog-body" @submit.prevent="runAction">
        <h2 id="manage-title">จัดการบัญชี {{ selected.name }}</h2>
        <fieldset class="actions">
          <legend class="sub">เลือกการดำเนินการ</legend>
          <label v-for="a in actionsFor(selected)" :key="a.key" class="radio">
            <input v-model="action" type="radio" name="action" :value="a.key">
            <span><span class="strong">{{ a.label }}</span><span class="sub block">{{ a.hint }}</span></span>
          </label>
        </fieldset>
        <label class="lbl">
          เหตุผล (บันทึกในประวัติการใช้งาน)
          <textarea v-model.trim="note" rows="3" maxlength="500" required></textarea>
        </label>
        <p v-if="dialogError" class="banner bad" role="alert">{{ dialogError }}</p>
        <div class="dialog-buttons">
          <button type="button" class="btn" @click="closeDialog">ยกเลิก</button>
          <button type="submit" class="btn pri" :disabled="!action || !note || saving">{{ saving ? 'กำลังบันทึก…' : 'ยืนยัน' }}</button>
        </div>
      </form>
    </dialog>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'

const alertStore = useAlertStore()

const rows = ref([])
const meta = ref({})
const page = ref(1)
const route = useRoute()
const status = ref(typeof route.query.status === 'string' ? route.query.status : 'all')
const q = ref('')
const loading = ref(false)
const error = ref('')

const chips = [
  { key: 'all', label: 'ทั้งหมด', count: 'all' },
  { key: 'active', label: 'ใช้งาน', count: 'active' },
  { key: 'pending', label: 'รอเปิดใช้', count: 'pending' },
  { key: 'locked', label: 'ล็อกชั่วคราว', count: 'locked' },
  { key: 'suspended', label: 'ระงับ', count: 'suspended' },
  { key: 'separated', label: 'ปิด', count: 'separated' },
]

const counts = computed(() => meta.value.counts || {})
const lifecycle = computed(() => meta.value.lifecycle_this_month || { joiner: 0, mover: 0, leaver: 0 })
const review = computed(() => meta.value.review || null)
const policy = computed(() => meta.value.policy || {})
const progressWidth = computed(() => (review.value?.total ? `${Math.round((review.value.decided / review.value.total) * 100)}%` : '0%'))

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/access/accounts', {
      params: { page: page.value, status: status.value === 'all' ? undefined : status.value, q: q.value || undefined },
    })
    rows.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = e?.response?.data?.message || 'โหลดรายการบัญชีไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

const setStatus = (key) => {
  status.value = key
  page.value = 1
  load()
}
const go = (target) => {
  page.value = target
  load()
}

let searchTimer = null
watch(q, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    load()
  }, 300)
})

onMounted(load)

// ------------------------------------------------------------------ แสดงผล

const STATUS = {
  active: ['ใช้งาน', 'ok'],
  pending: ['รอเปิดใช้', 'info'],
  locked: ['ล็อกชั่วคราว', 'warn'],
  suspended: ['ระงับ', 'bad'],
  separated: ['ปิด', 'mute'],
}
const statusLabel = (s) => STATUS[s]?.[0] ?? s
const statusTag = (s) => STATUS[s]?.[1] ?? 'mute'

const REASONS = {
  'personnel:study_leave': 'ลาศึกษาต่อ',
  'personnel:suspended': 'พักราชการตามข้อมูลบุคลากร',
  inactive_90_days: 'ไม่เข้าใช้นานเกินกำหนด',
  manual: 'ระงับโดยผู้ดูแลระบบ',
}

const thaiDate = (value) => (value ? new Intl.DateTimeFormat('th-TH', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(value)) : '')
const thaiTime = (value) => new Intl.DateTimeFormat('th-TH', { hour: '2-digit', minute: '2-digit' }).format(new Date(value))

const statusNote = (row) => {
  switch (row.status) {
    case 'locked':
      return row.locked_until ? `รหัสผิดหลายครั้ง · ปลดอัตโนมัติ ${thaiTime(row.locked_until)}` : 'รหัสผิดหลายครั้ง'
    case 'pending':
      return row.personnel?.started_at ? `เปิดอัตโนมัติ ${thaiDate(row.personnel.started_at)}` : 'รอวันเริ่มปฏิบัติงาน'
    case 'suspended':
      return REASONS[row.status_reason] || ''
    case 'separated':
      return [row.separated_at ? thaiDate(row.separated_at) : '', row.separation_reason || 'ปิดอัตโนมัติ'].filter(Boolean).join(' · ')
    default:
      return ''
  }
}

const sourcesText = (row) => {
  if (row.status === 'separated') return 'ถอนทั้งหมด'
  const s = row.sources
  const parts = []
  if (s.positions) parts.push(`ตำแหน่ง ${s.positions}`)
  if (s.acting) parts.push(`รักษาการ ${s.acting}`)
  if (s.grants + s.overrides) parts.push(`พิเศษ ${s.grants + s.overrides}`)
  return parts.length ? parts.join(' · ') : 'ไม่มีสิทธิ์'
}

const lastLogin = (value) => {
  if (!value) return 'ยังไม่เคยเข้าใช้'
  const d = new Date(value)
  return d.toDateString() === new Date().toDateString() ? `วันนี้ ${thaiTime(value)}` : thaiDate(value)
}

// ------------------------------------------------------------------ จัดการบัญชี (ปุ่มแสดงตาม abilities ที่ server คำนวณ)

const dialogEl = ref(null)
const selected = ref(null)
const action = ref('')
const note = ref('')
const saving = ref(false)
const dialogError = ref('')

const actionsFor = (row) => {
  const list = []
  if (row.status === 'active') list.push({ key: 'suspend', label: 'ระงับบัญชี', hint: 'ตัดการเข้าใช้และเซสชันทั้งหมดทันที' })
  if (row.status === 'locked') list.push({ key: 'unlock', label: 'ปลดล็อก', hint: 'ยืนยันตัวตนเจ้าของบัญชีแล้ว' })
  if (['suspended', 'locked'].includes(row.status)) list.push({ key: 'reactivate', label: 'เปิดใช้งาน', hint: 'เปิดได้เมื่อบุคลากรอยู่ในสถานะปฏิบัติงาน' })
  if (row.mfa.enabled && row.status !== 'separated') list.push({ key: 'reset-mfa', label: 'รีเซ็ต MFA', hint: 'เช่น โทรศัพท์หาย — ผู้ใช้ตั้งใหม่ตอนเข้าสู่ระบบครั้งถัดไป' })
  return list
}

const openManage = (row) => {
  selected.value = row
  action.value = actionsFor(row)[0]?.key || ''
  note.value = ''
  dialogError.value = ''
  dialogEl.value?.showModal()
}
const closeDialog = () => dialogEl.value?.close()
const onDialogClose = () => { selected.value = null }

const runAction = async () => {
  if (!selected.value || !action.value || !note.value) return
  saving.value = true
  dialogError.value = ''
  try {
    await api.post(`/access/accounts/${selected.value.uuid}/${action.value}`, { note: note.value })
    alertStore.success('บันทึกแล้ว')
    closeDialog()
    load()
  } catch (e) {
    const data = e?.response?.data
    dialogError.value = (data?.errors && Object.values(data.errors)[0]?.[0]) || data?.message || 'ดำเนินการไม่สำเร็จ'
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.acc {
  --ink: #1B2430; --ink-2: #3F4853; --muted: #5F6875; --line: #E1E4DF; --bg: #F4F5F2; --pri: #1F5F8B;
  font-family: 'IBM Plex Sans Thai', var(--font-sans); color: var(--ink); background: var(--bg); min-height: 100%;
}
.search { display: flex; align-items: center; gap: 8px; border: 1px solid #D5D9D3; border-radius: 8px; padding: 0 12px; height: 40px; background: #F8F9F7; flex: 0 1 320px; color: var(--muted); box-sizing: border-box; min-width: 0; }
.search input { border: 0; background: transparent; outline: none; font: inherit; font-size: 14px; width: 100%; color: var(--ink); min-width: 0; }
.search:focus-within { outline: 2px solid var(--pri); outline-offset: 1px; }

.content { padding: 28px 32px 48px; display: flex; flex-direction: column; gap: 20px; max-width: 1240px; width: 100%; box-sizing: border-box; }
.heading { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px; }
.heading-text { display: flex; flex-direction: column; gap: 4px; }
.heading h1 { margin: 0; font-size: 28px; font-weight: 700; }
.heading span { font-size: 14px; color: var(--muted); }

.chips { display: flex; flex-wrap: wrap; gap: 8px; }
.chip { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 0 14px; border-radius: 999px; border: 1px solid #D5D9D3; background: #fff; font: inherit; font-size: 13px; color: var(--ink-2); cursor: pointer; }
.chip.on { background: #1B2430; border-color: #1B2430; color: #fff; }
.chip:focus-visible, .btn:focus-visible { outline: 2px solid var(--pri); outline-offset: 2px; }

.layout { display: flex; flex-wrap: wrap; gap: 24px; align-items: flex-start; }
.card { background: #fff; border: 1px solid var(--line); border-radius: 12px; }
.table-card { flex: 3 1 600px; min-width: 0; overflow: hidden; }
.table-scroll { overflow-x: auto; position: relative; }
table { border-collapse: collapse; width: 100%; min-width: 900px; }
th { text-align: left; font-size: 12px; font-weight: 600; color: var(--muted); padding: 12px 14px; border-bottom: 1px solid #DCE0DA; background: #F8F9F7; white-space: nowrap; }
td { padding: 13px 14px; border-bottom: 1px solid #E8EBE6; font-size: 14px; vertical-align: top; }
tr.dim td { color: var(--muted); }
.empty { text-align: center; color: var(--muted); padding: 32px; }
.who { display: flex; flex-direction: column; gap: 2px; }
.strong { font-weight: 600; }
.sub { font-size: 12px; color: var(--muted); }
.block { display: block; }
.mono { font-family: 'IBM Plex Mono', ui-monospace, monospace; }
.tag { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 500; white-space: nowrap; width: fit-content; }
.ok { background: #E2F1E6; color: #1E6B35; } .info { background: #E3EEF6; color: #1C4F73; } .warn { background: #FBEFD9; color: #7A4B06; }
.bad { background: #F8E1DC; color: #8E2F1E; } .mute { background: #EEF0EC; color: var(--ink-2); }

.btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 40px; padding: 0 16px; border-radius: 8px; font: inherit; font-size: 14px; font-weight: 500; cursor: pointer; border: 1px solid #D5D9D3; background: #fff; color: var(--ink); white-space: nowrap; box-sizing: border-box; }
.btn:hover { background: var(--bg); }
.btn.pri { background: var(--pri); border-color: var(--pri); color: #fff; }
.btn.pri:hover { background: #174C70; }
.btn:disabled { opacity: .55; cursor: not-allowed; }
.btn.small { min-height: 36px; padding: 0 12px; font-size: 13px; }

.pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-top: 1px solid var(--line); }
.pager-buttons { display: flex; gap: 8px; }

.side { flex: 1 1 300px; min-width: 0; display: flex; flex-direction: column; gap: 20px; }
.pad { padding: 22px; display: flex; flex-direction: column; gap: 12px; font-size: 14px; }
.pad h2 { margin: 0; font-size: 17px; font-weight: 600; }
.jml { display: flex; align-items: flex-start; gap: 12px; }
.badge { min-width: 64px; justify-content: center; }
.row-between { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
.progress { height: 8px; background: #EEF0EC; border-radius: 999px; overflow: hidden; }
.progress div { height: 100%; background: var(--pri); }

.banner { margin: 0; padding: 12px 14px; border-radius: 10px; font-size: 13px; }

.dialog { margin: auto; border: 0; border-radius: 12px; padding: 0; width: min(480px, calc(100vw - 32px)); max-height: calc(100vh - 32px); color: var(--ink); font-family: 'IBM Plex Sans Thai', var(--font-sans); }
.dialog::backdrop { background: rgba(27, 36, 48, .45); }
.dialog-body { padding: 24px; display: flex; flex-direction: column; gap: 16px; }
.dialog-body h2 { margin: 0; font-size: 18px; }
.actions { border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.radio { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; min-height: 44px; }
.radio input { margin-top: 4px; accent-color: var(--pri); }
.lbl { display: flex; flex-direction: column; gap: 6px; font-size: 14px; font-weight: 500; color: var(--ink-2); }
textarea { border: 1px solid #C9CEC7; border-radius: 10px; padding: 10px 12px; font: inherit; font-size: 14px; resize: vertical; }
.dialog-buttons { display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }

@media (max-width: 640px) {
  .content { padding-left: 16px; padding-right: 16px; }
  .heading h1 { font-size: 24px; }
}
</style>
