<template>
  <div class="page">
    <div class="page-head">
      <div class="stack" style="gap: 4px">
        <h1 class="page-title">{{ title }}</h1>
        <span class="lead">{{ summary }}</span>
      </div>
      <div class="row">
        <router-link v-can="'personnel.report.view'" to="/personnel/reports" class="btn">รายงานบุคลากร</router-link>
      </div>
    </div>

    <!-- แถบขอบเขต: มาจากแหล่งสิทธิ์ที่ engine ใช้จริง (meta.access) -->
    <div v-if="delegated" class="banner info">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex: none; margin-top: 2px"><path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6z" /><path d="M9 12l2 2 4-4" /></svg>
      <div class="stack" style="gap: 4px">
        <span class="strong">คุณเข้าถึงเมนูนี้ด้วยสิทธิ์ที่ได้รับมอบหมาย</span>
        <span>{{ delegatedText }}</span>
      </div>
    </div>
    <div v-else-if="scopeText" class="banner info">
      <span>{{ scopeText }}</span>
    </div>

    <div class="chips" role="group" aria-label="กรองด่วน">
      <button type="button" class="chip" :class="{ on: !chip }" :aria-pressed="!chip" @click="setChip(null)">ทั้งหมด {{ meta.counts?.all ?? 0 }}</button>
      <button v-for="c in CHIPS" :key="c.key" type="button" class="chip" :class="{ on: chip === c.key }" :aria-pressed="chip === c.key" @click="setChip(c.key)">
        {{ c.label }} {{ meta.chips?.[c.key] ?? 0 }}
      </button>
    </div>

    <div class="filters">
      <label class="field">หน่วยงาน
        <select v-model="filters.org_unit" class="input" @change="reload">
          <option value="">ทุกหน่วยงาน</option>
          <option v-for="u in meta.filters?.units || []" :key="u.uuid" :value="u.uuid">{{ '— '.repeat(Math.max(0, u.depth - 1)) }}{{ u.name }}</option>
        </select>
      </label>
      <label class="field">ประเภทบุคลากร
        <select v-model="filters.type" class="input" @change="reload">
          <option value="">ทุกประเภท</option>
          <option v-for="t in meta.filters?.types || []" :key="t.code" :value="t.code">{{ t.name }}</option>
        </select>
      </label>
      <label class="field">สถานะ
        <select v-model="filters.status" class="input" @change="reload">
          <option value="">ทุกสถานะ</option>
          <option v-for="(v, k) in STATUS_LABELS" :key="k" :value="k">{{ v[0] }}</option>
        </select>
      </label>
      <label class="field grow">ค้นหา
        <input v-model="filters.q" type="search" class="input" placeholder="ชื่อ นามสกุล หรือรหัสบุคลากร">
      </label>
    </div>

    <p v-if="error" class="banner bad" role="alert">{{ error }}</p>

    <div class="two-col">
      <section class="card main-col" style="overflow: hidden" :aria-label="title">
        <div class="table-scroll">
          <!-- มุมมองผู้ได้รับมอบ (ต้นแบบหน้า 12) แสดงเฉพาะคอลัมน์ที่ใช้ทำงาน: ชื่อ ตำแหน่ง ใบอนุญาต ชั่วโมงอบรม -->
          <table class="dt" :style="{ minWidth: limited ? '720px' : '920px' }">
            <thead>
              <tr>
                <th v-if="!limited" scope="col">รหัส</th><th scope="col">ชื่อ-สกุล</th><th scope="col">ตำแหน่งหลัก · สังกัด</th><th v-if="!limited" scope="col">ตำแหน่งบริหาร / หน้าที่</th>
                <th v-if="!limited" scope="col">ประเภท</th><th v-if="!limited" scope="col">สถานะ</th><th scope="col">ใบอนุญาตฯ หมดอายุ</th><th scope="col">ชั่วโมงอบรม {{ meta.fiscal_year || '' }}</th>
                <th scope="col"><span class="sr-only">การดำเนินการ</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="loading && !rows.length"><td colspan="9" class="empty">กำลังโหลด…</td></tr>
              <tr v-else-if="!rows.length"><td colspan="9" class="empty">ไม่พบบุคลากรที่ตรงเงื่อนไข</td></tr>
              <tr v-for="r in rows" :key="r.uuid">
                <td v-if="!limited" class="mono">{{ r.personnel_code || '—' }}</td>
                <td>
                  <router-link v-if="!r.is_self" :to="`/personnel/${r.uuid}`" class="link strong">{{ r.full_name }}</router-link>
                  <router-link v-else to="/me" class="link strong">{{ r.full_name }} (คุณ)</router-link>
                </td>
                <td><span v-if="r.primary">{{ r.primary.position }}<br><span class="sub">{{ r.primary.unit }}</span></span><span v-else class="sub">—</span></td>
                <td v-if="!limited">
                  <div class="stack" style="gap: 4px">
                    <span v-for="(role, i) in r.roles" :key="i" class="role">
                      <span v-if="role.level" class="lv" :class="`lv-${role.level}`">L{{ role.level }}</span>
                      {{ role.kind === 'acting' ? 'รักษาการ' : role.kind === 'duty' ? 'หน้าที่: ' : '' }}{{ role.position }} {{ role.unit }}
                      <span v-if="role.kind === 'acting' && role.effective_to" class="sub">ถึง {{ thaiDate(role.effective_to) }}</span>
                    </span>
                    <span v-if="!r.roles.length" class="sub">—</span>
                  </div>
                </td>
                <td v-if="!limited">{{ r.type }}</td>
                <td v-if="!limited"><span v-if="r.status" class="tag" :class="STATUS_LABELS[r.status]?.[1]">{{ STATUS_LABELS[r.status]?.[0] }}</span><span v-else class="sub">—</span></td>
                <td>
                  <span v-if="!r.license" class="sub">—</span>
                  <span v-else-if="!r.license.required" class="sub">ไม่ต้องมี</span>
                  <span v-else-if="!r.license.expires_at" class="tag warn">ยังไม่มีข้อมูล</span>
                  <span v-else :class="r.license.days_left !== null && r.license.days_left <= 90 ? 'tag warn' : ''">
                    {{ thaiDate(r.license.expires_at) }}<template v-if="r.license.days_left !== null && r.license.days_left <= 90"> · {{ r.license.days_left < 0 ? 'หมดอายุแล้ว' : `อีก ${r.license.days_left} วัน` }}</template>
                  </span>
                </td>
                <td>
                  <span v-if="r.training_hours === null" class="sub">—</span>
                  <span v-else-if="r.training_hours === 0" class="tag warn">ยังไม่มีชั่วโมง</span>
                  <span v-else>{{ r.training_hours }} ชั่วโมง</span>
                </td>
                <td>
                  <button v-if="r.abilities.manage_training" type="button" class="btn small" @click="openTraining(r)">บันทึกการอบรม</button>
                  <span v-else-if="r.is_self" class="sub">ข้อมูลของคุณ ยื่นผ่านคำขอแก้ไข</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="pager">
          <span class="sub">{{ meta.total ? `แสดง ${meta.from}–${meta.to} จาก ${meta.total} รายการ` : '' }}</span>
          <div class="row">
            <button type="button" class="btn" :disabled="page <= 1 || loading" @click="go(page - 1)">ก่อนหน้า</button>
            <button type="button" class="btn" :disabled="page >= (meta.last_page || 1) || loading" @click="go(page + 1)">ถัดไป</button>
          </div>
        </div>
      </section>

      <!-- สิทธิ์ของคุณในหน้านี้ (ต้นแบบหน้า 12) — จาก capabilities ที่ engine คำนวณ -->
      <section v-if="limited" class="card pad side-col" aria-labelledby="cap-title" style="gap: 16px">
        <h2 id="cap-title" class="card-title">สิทธิ์ของคุณในหน้านี้</h2>
        <div class="stack" style="gap: 10px">
          <span class="cap-ok">ทำได้</span>
          <span v-for="t in caps.yes" :key="t">{{ t }}</span>
        </div>
        <div class="stack cap-no-block" style="gap: 10px">
          <span class="cap-no">ทำไม่ได้</span>
          <span v-for="t in caps.no" :key="t">{{ t }}</span>
        </div>
        <span class="sub">ต้องการสิทธิ์เพิ่ม ขอผ่านหัวหน้าหน่วยเพื่อส่งต่องานบุคลากรอนุมัติ</span>
      </section>
    </div>

    <dialog ref="dialogEl" class="dm-dialog" aria-labelledby="training-title" @close="target = null">
      <form v-if="target" class="dialog-body" @submit.prevent="saveTraining">
        <h2 id="training-title">บันทึกการอบรม · {{ target.full_name }}</h2>
        <label class="field">หลักสูตร<input v-model.trim="training.course" class="input" required maxlength="255"></label>
        <label class="field">ผู้จัด<input v-model.trim="training.organizer" class="input" maxlength="255"></label>
        <div class="row">
          <label class="field" style="flex: 1">วันเริ่ม<input v-model="training.start_date" type="date" class="input"></label>
          <label class="field" style="flex: 1">วันสิ้นสุด<input v-model="training.end_date" type="date" class="input"></label>
          <label class="field" style="flex: 1">ชั่วโมง<input v-model.number="training.hours" type="number" min="0" step="0.5" class="input"></label>
        </div>
        <p v-if="dialogError" class="banner bad" role="alert">{{ dialogError }}</p>
        <div class="dialog-buttons">
          <button type="button" class="btn" @click="dialogEl.close()">ยกเลิก</button>
          <button type="submit" class="btn pri" :disabled="saving || !training.course">{{ saving ? 'กำลังบันทึก…' : 'บันทึก' }}</button>
        </div>
      </form>
    </dialog>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { useMenuStore } from '../../stores/menuStore'
import { STATUS_LABELS, apiMessage, thaiDate } from '../../utils/dm'

const route = useRoute()
const router = useRouter()
const menu = useMenuStore()
const alerts = useAlertStore()

const CHIPS = [
  { key: 'license_expiring', label: 'ใบอนุญาตใกล้หมดอายุ' },
  { key: 'incomplete', label: 'ข้อมูลไม่ครบ' },
  { key: 'pending_start', label: 'รอเริ่มงาน' },
  { key: 'pending_order', label: 'มีคำสั่งรอมีผล' },
]

const rows = ref([])
const meta = ref({})
const page = ref(1)
const chip = ref(route.query.chip || null)
const filters = reactive({ org_unit: '', type: '', status: '', q: '' })
const loading = ref(false)
const error = ref('')

// ชื่อหน้าเป็นชื่อเมนูที่ API ตั้งตาม scope (เช่น "บุคลากรแผนกวิชาช่างยนต์")
const title = computed(() => menu.byRoute('/personnel')?.name || 'รายชื่อบุคลากร')
const summary = computed(() => {
  const c = meta.value.counts
  if (!c) return ''
  const parts = [`${c.all} คน`]
  if (c.active) parts.push(`ปฏิบัติงาน ${c.active}`)
  if (c.study_leave) parts.push(`ลาศึกษาต่อ ${c.study_leave}`)
  if (c.pending) parts.push(`รอเริ่มงาน ${c.pending}`)
  return parts.join(' · ')
})

const access = computed(() => meta.value.access || [])
// แถบ "สิทธิ์ที่ได้รับมอบหมาย" (ต้นแบบหน้า 12): เห็นรายการนี้จากชุดสิทธิ์ที่มอบรายหน่วยเท่านั้น ไม่ใช่จากตำแหน่งและไม่ใช่ทั้งวิทยาลัย
const delegated = computed(() => access.value.length > 0 && access.value.every((a) => a.source_type !== 'assignment' && a.scope !== 'all'))
const delegatedText = computed(() => {
  const a = access.value.find((x) => x.source_type !== 'assignment')
  if (!a) return ''
  return [a.label?.replace(/ \(คำสั่ง.*\)$/, ''), a.units?.length ? `ขอบเขต${a.units.join(', ')}` : 'ขอบเขตทั้งวิทยาลัย',
    a.expires_at ? `หมดอายุ ${thaiDate(a.expires_at)}` : null, a.approved_by ? `อนุมัติโดย${a.approved_by}` : null,
    a.order_number ? `ตาม${a.order_number}` : null].filter(Boolean).join(' · ')
})
const scopeText = computed(() => {
  const list = access.value
  if (!list.length) return ''
  const all = list.find((a) => a.scope === 'all')
  if (all) return `คุณเห็นรายชื่อทั้งวิทยาลัยในฐานะ${all.label}`
  const units = [...new Set(list.flatMap((a) => a.units))]
  return `คุณเห็นรายชื่อของ${units.join(', ')} และหน่วยงานย่อย ในฐานะ${list.map((a) => a.label).join(', ')}`
})

const capabilities = computed(() => meta.value.capabilities || {})
const limited = computed(() => delegated.value)
const caps = computed(() => {
  const c = capabilities.value
  const yes = ['ดูรายชื่อและตำแหน่งของบุคลากรในขอบเขต', 'ดูวันหมดอายุใบอนุญาตประกอบวิชาชีพ']
  const no = ['ดูบุคลากรนอกขอบเขต']
  ;(c.manage_training ? yes : no).push(c.manage_training ? 'บันทึกและแก้ไขประวัติการอบรมของคนในขอบเขต' : 'บันทึกประวัติการอบรม')
  ;(c.view_confidential ? yes : no).push(c.view_confidential ? 'ดูข้อมูลส่วนตัวชั้นลับ' : 'ดูข้อมูลส่วนตัวหรือข้อมูลอ่อนไหว')
  if (c.reveal) yes.push('เปิดดูข้อมูลอ่อนไหวพร้อมเหตุผล')
  no.push('แก้ไขประวัติของตนเองโดยตรง (ยื่นคำขอแก้ไข)')
  ;(c.report ? yes : no).push(c.report ? 'ดูรายงานบุคลากร' : 'ดูหรือส่งออกรายงาน')
  if (!c.edit) no.push('แก้ไขข้อมูลบุคลากร')
  return { yes, no }
})

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    const params = { page: page.value, chip: chip.value || undefined }
    Object.entries(filters).forEach(([k, v]) => { if (v) params[k] = v })
    const { data } = await api.get('/personnel', { params })
    rows.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = apiMessage(e, 'โหลดรายชื่อบุคลากรไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}
const reload = () => { page.value = 1; load() }
const go = (p) => { page.value = p; load() }
const setChip = (key) => {
  chip.value = key
  router.replace({ query: key ? { chip: key } : {} })
  reload()
}
let timer = null
watch(() => filters.q, () => { clearTimeout(timer); timer = setTimeout(reload, 300) })

onMounted(() => { menu.load(); load() })

// ------------------------------------------------------------------ บันทึกการอบรม (abilities.manage_training ต่อแถวจาก server)
const dialogEl = ref(null)
const target = ref(null)
const training = reactive({ course: '', organizer: '', start_date: '', end_date: '', hours: null })
const saving = ref(false)
const dialogError = ref('')
const openTraining = (row) => {
  target.value = row
  Object.assign(training, { course: '', organizer: '', start_date: '', end_date: '', hours: null })
  dialogError.value = ''
  dialogEl.value.showModal()
}
const saveTraining = async () => {
  saving.value = true
  dialogError.value = ''
  try {
    const payload = Object.fromEntries(Object.entries(training).filter(([, v]) => v !== '' && v !== null))
    await api.post(`/personnel/${target.value.uuid}/records/trainings`, payload)
    alerts.success('บันทึกการอบรมแล้ว')
    dialogEl.value.close()
    load()
  } catch (e) {
    dialogError.value = apiMessage(e)
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.strong { font-weight: 600; }
.filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
.filters .field { flex: 1 1 180px; }
.filters .grow { flex: 2 1 240px; }
.dt td a.strong { white-space: nowrap; }
.role { font-size: 13px; display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-top: 1px solid var(--line); }
.cap-ok { font-size: 13px; font-weight: 600; color: var(--ok); }
.cap-no { font-size: 13px; font-weight: 600; color: var(--bad); }
.cap-no-block { padding-top: 14px; border-top: 1px solid var(--line-2); }
.side-col > span, .stack > span { font-size: 14px; }
</style>
