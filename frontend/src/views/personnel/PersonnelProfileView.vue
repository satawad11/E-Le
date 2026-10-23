<template>
  <div class="page">
    <p v-if="error" class="banner bad" role="alert">{{ error }}</p>
    <p v-if="loading && !person" class="empty">กำลังโหลด…</p>

    <template v-if="person">
      <!-- หัวประวัติ -->
      <section class="card pad head">
        <div class="avatar" aria-hidden="true">{{ initials }}</div>
        <div class="stack" style="gap: 6px; min-width: 0; flex: 1">
          <div class="row">
            <h1 class="page-title" style="font-size: 24px">{{ p.full_name || 'ไม่มีสิทธิ์ดูชื่อ' }}</h1>
            <span v-if="p.status" class="tag" :class="STATUS_LABELS[p.status]?.[1]">{{ STATUS_LABELS[p.status]?.[0] }}</span>
            <span v-if="self" class="tag info">ข้อมูลของฉัน</span>
          </div>
          <span class="sub">{{ [p.personnel_code, p.type, p.academic_standing, p.started_at ? `บรรจุ ${thaiDate(p.started_at)}` : null].filter(Boolean).join(' · ') }}</span>
          <div class="row" style="gap: 8px">
            <span v-for="a in activeAssignments" :key="a.uuid" class="pos-chip">
              <span v-if="a.kind === 'primary'" aria-label="ตำแหน่งหลัก">★</span>
              <span class="lv" :class="`lv-${a.level}`">L{{ a.level }}</span>
              {{ a.kind === 'acting' ? 'รักษาการ' : '' }}{{ a.position?.name }} {{ a.org_unit?.name }}
            </span>
          </div>
        </div>
      </section>

      <div class="tabs" role="tablist" aria-label="หมวดประวัติ">
        <button v-for="t in tabs" :key="t.id" type="button" role="tab" class="tab" :class="{ on: tab === t.id }" :aria-selected="tab === t.id" @click="tab = t.id">{{ t.label }}</button>
      </div>

      <!-- ข้อมูลส่วนตัว -->
      <section v-if="tab === 'personal'" class="card pad stack" role="tabpanel">
        <div class="row legend">
          <span class="sub">ระดับชั้นความลับ:</span>
          <span v-for="(v, k) in CLASS_LABELS" :key="k" class="tag" :class="v[1]">{{ v[0] }}</span>
          <span v-if="!self" class="sub">· ข้อมูลที่ไม่มีสิทธิ์ดูถูกปิดบังโดยเซิร์ฟเวอร์</span>
        </div>
        <div v-for="f in personalFields" :key="f.entity + f.field" class="frow">
          <div class="flabel">
            <span>{{ fieldLabel(f.field) }}</span>
            <span class="tag" :class="CLASS_LABELS[f.cls]?.[1]">{{ CLASS_LABELS[f.cls]?.[0] }}</span>
            <span v-if="isPending(f.entity, f.field)" class="tag warn">รอตรวจสอบ</span>
          </div>
          <div class="fvalue">
            <template v-if="revealed[f.field] !== undefined">
              <span class="mono">{{ revealed[f.field] ?? 'ไม่มีข้อมูล' }}</span>
              <span class="sub">เปิดดูแล้ว · บันทึกในประวัติการเข้าถึง · ค่าจะหายเมื่อออกจากหน้านี้</span>
            </template>
            <template v-else-if="f.masked && f.cls !== 'sensitive'">
              <span class="sub">ไม่มีสิทธิ์ดูข้อมูลชั้นนี้</span>
            </template>
            <template v-else>
              <span :class="{ mono: f.cls === 'sensitive' }">{{ display(f) }}</span>
              <button v-if="f.cls === 'sensitive' && person.can_reveal && f.value" type="button" class="link" @click="openReveal(f.field)">แสดงเต็ม</button>
            </template>
          </div>
          <div class="factions">
            <template v-if="self">
              <button v-if="rule(f) === 'self'" type="button" class="btn small" @click="openEdit(f)">แก้ไข</button>
              <button v-else-if="rule(f) === 'self_with_review'" type="button" class="btn small" :disabled="isPending(f.entity, f.field)" @click="openRequest(f.entity, [f.field])">ยื่นแก้ไข</button>
              <span v-else class="sub">แก้ไขได้เฉพาะงานบุคลากร</span>
            </template>
            <button v-else-if="records?.can.edit && canEditField(f)" type="button" class="btn small" @click="openEdit(f)">แก้ไข</button>
          </div>
        </div>
      </section>

      <!-- ตำแหน่งและสังกัด -->
      <section v-if="tab === 'positions'" class="card stack" style="overflow: hidden" role="tabpanel">
        <div class="row between pad" style="padding-bottom: 0">
          <span class="sub">เก็บทุกการเปลี่ยนแปลงพร้อมวันที่มีผล ไม่เขียนทับ ตอบได้ว่า ณ วันใดสังกัดหน่วยใด</span>
          <button v-if="records?.can.manage_assignments && !self" type="button" class="btn pri" @click="openAssign">บันทึกตามคำสั่งใหม่</button>
        </div>
        <div class="table-scroll">
          <table class="dt" style="min-width: 860px">
            <thead><tr><th>ประเภท</th><th>ตำแหน่ง</th><th>หน่วยงาน</th><th>ระดับ</th><th>มีผล</th><th>สิ้นสุด</th><th>คำสั่งที่</th><th>สถานะ</th><th><span class="sr-only">การดำเนินการ</span></th></tr></thead>
            <tbody>
              <tr v-if="!assignments.length"><td colspan="9" class="empty">ไม่มีข้อมูลตำแหน่ง</td></tr>
              <tr v-for="a in assignments" :key="a.uuid" :class="{ dim: a.state === 'ended' }">
                <td>{{ KIND_LABELS[a.kind] }} <span v-if="a.kind === 'primary'">★</span></td>
                <td>{{ a.position?.name }}</td>
                <td>{{ a.org_unit?.name }}</td>
                <td><span class="lv" :class="`lv-${a.level}`">L{{ a.level }}</span></td>
                <td>{{ thaiDate(a.effective_from) }}</td>
                <td>{{ a.effective_to ? thaiDate(a.effective_to) : '—' }}</td>
                <td class="mono">{{ a.order_number || '—' }}</td>
                <td><span class="tag" :class="{ active: 'ok', upcoming: 'info', ended: 'mute' }[a.state]">{{ { active: 'มีผล', upcoming: 'รอมีผล', ended: 'สิ้นสุด' }[a.state] }}</span></td>
                <td><button v-if="records?.can.manage_assignments && !self && a.state !== 'ended' && !a.end_reason" type="button" class="btn small" @click="openEnd(a)">สิ้นสุด</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="sub pad" style="padding-top: 0">★ ตำแหน่งหลักใช้เป็นสังกัดในสมุดรายชื่อ การนับอัตรากำลัง และสายอนุมัติเมื่อเจ้าตัวยื่นเรื่องเอง · ตำแหน่งรักษาการมอบสิทธิ์เฉพาะช่วงวันที่กำหนดและหมดอัตโนมัติ</p>
      </section>

      <!-- การศึกษาและวิชาชีพ -->
      <section v-if="tab === 'edu' && records" class="stack" role="tabpanel" style="gap: 20px">
        <div v-for="sec in RECORD_SECTIONS" :key="sec.type" class="card pad stack">
          <div class="row between">
            <h2 class="card-title">{{ sec.title }}<span v-if="sec.type === 'trainings'" class="sub"> · รวม {{ trainingHours }} ชั่วโมง</span></h2>
            <button v-if="canAddRecord(sec.type)" type="button" class="btn small" @click="openRecord(sec.type)">{{ self ? 'ยื่นเพิ่ม' : 'เพิ่ม' }}</button>
          </div>
          <p v-if="!records[sec.type].length" class="sub">ยังไม่มีข้อมูล</p>
          <div v-for="r in records[sec.type]" :key="r.uuid" class="rec">
            <div class="stack" style="gap: 2px; min-width: 0">
              <span class="strong">{{ sec.summary(r) }}</span>
              <span class="sub">{{ sec.detail(r) }}</span>
            </div>
            <div class="row" style="gap: 6px">
              <span v-if="r.verified" class="tag ok">ตรวจแล้ว</span>
              <button v-if="self" type="button" class="btn small" @click="openRecord(sec.type, r)">ยื่นแก้ไข</button>
              <button v-else-if="canAddRecord(sec.type)" type="button" class="btn small" @click="openRecord(sec.type, r)">แก้ไข</button>
            </div>
          </div>
        </div>

        <div v-if="records.documents" class="card pad stack">
          <div class="row between">
            <h2 class="card-title">เอกสารแนบ</h2>
            <label v-if="self || records.can.edit" class="btn small">
              อัปโหลด<input type="file" accept="application/pdf,image/jpeg,image/png" class="sr-only" @change="upload($event, self ? 'evidence' : 'other')">
            </label>
          </div>
          <p v-if="!records.documents.length" class="sub">ยังไม่มีเอกสาร</p>
          <div v-for="d in records.documents" :key="d.uuid" class="rec">
            <div class="stack" style="gap: 2px">
              <span>{{ d.original_name }}</span>
              <span class="sub">{{ DOC_CATEGORIES[d.category] || d.category }} · {{ thaiDate(d.uploaded_at) }} · {{ Math.ceil(d.size / 1024) }} KB</span>
            </div>
            <button type="button" class="btn small" @click="openDocument(d)">เปิดดู</button>
          </div>
          <span class="sub">เอกสารเก็บในพื้นที่ส่วนตัวของระบบ เปิดได้ผ่านลิงก์ชั่วคราวที่ออกให้คุณเท่านั้น</span>
        </div>
      </section>

      <!-- ประวัติการเข้าถึง -->
      <section v-if="tab === 'access'" class="card stack" style="overflow: hidden" role="tabpanel">
        <p class="sub pad" style="padding-bottom: 0">ทุกการเปิดดูข้อมูลอ่อนไหวถูกบันทึก เจ้าของข้อมูลดูประวัตินี้ได้จากหน้าของตนเอง</p>
        <div class="table-scroll">
          <table class="dt" style="min-width: 720px">
            <thead><tr><th>เวลา</th><th>ผู้เข้าถึง</th><th>ในฐานะ</th><th>ข้อมูลที่เปิดดู</th><th>เหตุผล</th></tr></thead>
            <tbody>
              <tr v-if="!accessLog.length"><td colspan="5" class="empty">ยังไม่มีการเปิดดูข้อมูลอ่อนไหว</td></tr>
              <tr v-for="(l, i) in accessLog" :key="i">
                <td>{{ thaiDateTime(l.at) }}</td><td>{{ l.actor }}</td><td>{{ l.acting_as?.permission === 'personnel.profile.reveal' ? 'สิทธิ์เปิดดูข้อมูลอ่อนไหว' : (l.acting_as?.permission || '—') }}</td>
                <td>{{ (l.fields || []).map(fieldLabel).join(', ') }}</td><td>{{ l.reason }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- คำขอของฉัน -->
      <section v-if="self && myRequests.length" class="card pad stack">
        <h2 class="card-title">คำขอแก้ไขของฉัน</h2>
        <div v-for="r in myRequests" :key="r.uuid" class="rec">
          <div class="stack" style="gap: 2px">
            <span>{{ ENTITY_LABELS[r.entity] }} · {{ r.fields.map(fieldLabel).join(', ') }}</span>
            <span class="sub">ยื่น {{ thaiDate(r.created_at) }}<template v-if="r.review_note"> · หมายเหตุ: {{ r.review_note }}</template></span>
          </div>
          <span class="tag" :class="REQUEST_STATUS[r.status]?.[1]">{{ REQUEST_STATUS[r.status]?.[0] }}</span>
        </div>
      </section>
    </template>

    <!-- เปิดดูข้อมูลอ่อนไหว: ต้องมีเหตุผล · ค่าไม่เก็บใน store/localStorage -->
    <dialog ref="revealEl" class="dm-dialog" aria-labelledby="reveal-title">
      <form class="dialog-body" @submit.prevent="confirmReveal">
        <h2 id="reveal-title">เปิดดู{{ fieldLabel(revealField) }}</h2>
        <label class="field">ระบุเหตุผลในการเปิดดู (บังคับ)
          <textarea v-model.trim="revealReason" class="input" rows="3" minlength="5" maxlength="500" required></textarea>
        </label>
        <span class="sub">การเปิดดูจะถูกบันทึกและเจ้าของข้อมูลเห็นได้</span>
        <p v-if="dialogError" class="banner bad" role="alert">{{ dialogError }}</p>
        <div class="dialog-buttons">
          <button type="button" class="btn" @click="revealEl.close()">ยกเลิก</button>
          <button type="submit" class="btn pri" :disabled="busy || revealReason.length < 5">ยืนยันเปิดดู</button>
        </div>
      </form>
    </dialog>

    <!-- แก้ไขตรง (เจ้าของ: ฟิลด์ self · งานบุคคล: ผู้อื่น) / ยื่นคำขอแก้ไข -->
    <dialog ref="editEl" class="dm-dialog" aria-labelledby="edit-title">
      <form v-if="editing" class="dialog-body" @submit.prevent="saveEdit">
        <h2 id="edit-title">{{ editing.mode === 'request' ? 'ยื่นคำขอแก้ไข' : 'แก้ไข' }}{{ editing.title }}</h2>
        <label v-for="field in editing.fields" :key="field" class="field">{{ fieldLabel(field) }}
          <input v-model="editing.values[field]" class="input" :type="inputType(field)" :inputmode="field === 'national_id' ? 'numeric' : undefined">
        </label>
        <label v-if="editing.mode === 'request'" class="field">หลักฐานประกอบ (PDF/JPG/PNG ไม่เกิน 10 MB)
          <input type="file" accept="application/pdf,image/jpeg,image/png" class="input" @change="evidenceFile = $event.target.files[0] || null">
        </label>
        <span v-if="editing.mode === 'request'" class="sub">ค่าเดิมยังแสดงในระบบจนกว่างานบุคลากรจะอนุมัติ</span>
        <p v-if="dialogError" class="banner bad" role="alert">{{ dialogError }}</p>
        <div class="dialog-buttons">
          <button type="button" class="btn" @click="editEl.close()">ยกเลิก</button>
          <button type="submit" class="btn pri" :disabled="busy">{{ editing.mode === 'request' ? 'ยื่นคำขอ' : 'บันทึก' }}</button>
        </div>
      </form>
    </dialog>

    <!-- บันทึกตามคำสั่งใหม่ / สิ้นสุดตำแหน่ง -->
    <dialog ref="assignEl" class="dm-dialog" aria-labelledby="assign-title">
      <form class="dialog-body" @submit.prevent="saveAssign">
        <h2 id="assign-title">{{ endingAssignment ? 'สิ้นสุดตำแหน่ง' : 'บันทึกตามคำสั่งใหม่' }}</h2>
        <template v-if="endingAssignment">
          <span>{{ endingAssignment.position?.name }} · {{ endingAssignment.org_unit?.name }}</span>
          <label class="field">วันสิ้นสุด<input v-model="assignForm.effective_to" type="date" class="input" required></label>
          <label class="field">เหตุผล<input v-model.trim="assignForm.end_reason" class="input" required maxlength="255"></label>
        </template>
        <template v-else>
          <label class="field">ประเภท
            <select v-model="assignForm.kind" class="input" required>
              <option value="primary">ตำแหน่งหลัก</option><option value="management">ตำแหน่งบริหาร</option><option value="duty">หน้าที่มอบหมาย</option>
            </select>
          </label>
          <label class="field">หน่วยงาน
            <select v-model="assignForm.org_unit_uuid" class="input" required>
              <option v-for="u in units" :key="u.uuid" :value="u.uuid">{{ '— '.repeat(Math.max(0, u.depth - 1)) }}{{ u.name }}</option>
            </select>
          </label>
          <label class="field">ตำแหน่ง
            <select v-model="assignForm.position_uuid" class="input" required>
              <option v-for="pos in positions" :key="pos.uuid" :value="pos.uuid">{{ pos.name }}</option>
            </select>
          </label>
          <div class="row">
            <label class="field" style="flex: 1">คำสั่งที่<input v-model.trim="assignForm.order_number" class="input" required maxlength="120"></label>
            <label class="field" style="flex: 1">ลงวันที่<input v-model="assignForm.order_date" type="date" class="input" required></label>
          </div>
          <div class="row">
            <label class="field" style="flex: 1">มีผลตั้งแต่<input v-model="assignForm.effective_from" type="date" class="input" required></label>
            <label class="field" style="flex: 1">ถึง (ถ้ามี)<input v-model="assignForm.effective_to" type="date" class="input"></label>
          </div>
        </template>
        <p v-if="dialogError" class="banner bad" role="alert">{{ dialogError }}</p>
        <div class="dialog-buttons">
          <button type="button" class="btn" @click="assignEl.close()">ยกเลิก</button>
          <button type="submit" class="btn pri" :disabled="busy">บันทึก</button>
        </div>
      </form>
    </dialog>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { useAuthStore } from '../../stores/auth'
import { CLASS_LABELS, ENTITY_LABELS, KIND_LABELS, REQUEST_STATUS, STATUS_LABELS, apiMessage, fieldLabel, thaiDate, thaiDateTime } from '../../utils/dm'

const props = defineProps({ uuid: { type: String, default: null }, self: { type: Boolean, default: false } })
const auth = useAuthStore()
const alerts = useAlertStore()

const person = ref(null)
const assignments = ref([])
const records = ref(null)
const rules = ref({})
const accessLog = ref([])
const myRequests = ref([])
const loading = ref(false)
const error = ref('')
const tab = ref('personal')

/** ค่าที่เปิดดูแล้ว — อยู่ในหน่วยความจำของหน้านี้เท่านั้น ไม่เข้า store/localStorage และล้างเมื่อออกจากหน้า */
const revealed = reactive({})
const clearRevealed = () => Object.keys(revealed).forEach((k) => delete revealed[k])
onBeforeRouteLeave(clearRevealed)
onBeforeUnmount(clearRevealed)

const targetUuid = computed(() => (props.self ? auth.user?.personnel?.uuid : props.uuid))
const p = computed(() => person.value?.personnel || {})
const initials = computed(() => ((p.value.first_name || '').slice(0, 1) + (p.value.last_name || '').slice(0, 1)) || '•')
const activeAssignments = computed(() => assignments.value.filter((a) => a.state === 'active'))
const trainingHours = computed(() => (records.value?.trainings || []).reduce((s, t) => s + (t.hours || 0), 0))

const tabs = computed(() => [
  { id: 'personal', label: 'ข้อมูลส่วนตัว' },
  { id: 'positions', label: 'ตำแหน่งและสังกัด' },
  { id: 'edu', label: 'การศึกษาและวิชาชีพ' },
  ...(records.value?.can.view_access_log ? [{ id: 'access', label: 'ประวัติการเข้าถึง' }] : []),
])

const PERSONAL = [
  ['personnel', 'title'], ['personnel', 'first_name'], ['personnel', 'last_name'], ['personnel', 'first_name_en'], ['personnel', 'last_name_en'],
  ['personnel_profile', 'email_work'], ['personnel_profile', 'phone_mobile'], ['personnel_profile', 'birth_date'],
  ['personnel_profile', 'emergency_contact_name'], ['personnel_profile', 'emergency_contact_phone'], ['personnel_profile', 'emergency_contact_relation'],
  ['personnel_profile', 'national_id'], ['personnel_profile', 'address_registered'], ['personnel_profile', 'address_current'],
  ['personnel_profile', 'bank_name'], ['personnel_profile', 'bank_account'], ['personnel_profile', 'health_notes'],
]
const personalFields = computed(() => PERSONAL.map(([entity, field]) => {
  const source = entity === 'personnel' ? person.value.personnel : person.value.profile
  const key = entity === 'personnel' ? field : `profile.${field}`
  return { entity, field, value: source?.[field] ?? null, cls: rules.value[entity]?.[field]?.class || 'sensitive', masked: person.value.masked.includes(key) }
}))
const rule = (f) => rules.value[f.entity]?.[f.field]?.editable_by || 'hr_only'
const display = (f) => (f.value === null || f.value === '' ? 'ไม่มีข้อมูล' : (f.field === 'birth_date' ? thaiDate(f.value) : f.value))
const canEditField = (f) => !f.masked || (f.cls === 'sensitive' && person.value.can_reveal)
const isPending = (entity, field) => (records.value?.pending_changes || []).some((c) => c.entity === entity && c.fields.includes(field))
const inputType = (field) => (['birth_date', 'start_date', 'end_date', 'issued_at', 'expires_at'].includes(field) ? 'date' : ['hours', 'graduated_year'].includes(field) ? 'number' : 'text')

const DOC_CATEGORIES = { evidence: 'หลักฐานประกอบคำขอ', education: 'การศึกษา', training: 'การอบรม', license: 'ใบอนุญาต', appointment: 'คำสั่ง', other: 'อื่น ๆ' }
const RECORD_SECTIONS = [
  { type: 'educations', entity: 'personnel_education', title: 'วุฒิการศึกษา', fields: ['level', 'degree', 'major', 'institution', 'graduated_year'],
    summary: (r) => [r.level, r.degree].filter(Boolean).join(' '), detail: (r) => [r.major, r.institution, r.graduated_year].filter(Boolean).join(' · ') },
  { type: 'licenses', entity: 'personnel_license', title: 'ใบอนุญาตประกอบวิชาชีพ', fields: ['license_type', 'license_number', 'issued_at', 'expires_at'],
    summary: (r) => r.license_type, detail: (r) => [r.license_number ? `เลขที่ ${r.license_number}` : (r.masked.includes('license_number') ? 'เลขที่: ไม่มีสิทธิ์ดู' : null), r.expires_at ? `หมดอายุ ${thaiDate(r.expires_at)}` : null].filter(Boolean).join(' · ') },
  { type: 'trainings', entity: 'personnel_training', title: 'ประวัติการอบรม', fields: ['course', 'organizer', 'hours', 'start_date', 'end_date'],
    summary: (r) => r.course, detail: (r) => [r.organizer, r.hours ? `${r.hours} ชั่วโมง` : null, r.start_date ? thaiDate(r.start_date) : null].filter(Boolean).join(' · ') },
]
const canAddRecord = (type) => (props.self ? records.value?.can.request_change : (records.value?.can.edit || (type === 'trainings' && records.value?.can.manage_training)))

// ------------------------------------------------------------------ โหลด
async function load() {
  if (!targetUuid.value) {
    error.value = props.self ? 'บัญชีนี้ไม่ได้ผูกกับข้อมูลบุคลากร' : 'ไม่พบบุคลากร'
    return
  }
  loading.value = true
  error.value = ''
  clearRevealed()
  try {
    const base = `/personnel/${targetUuid.value}`
    const [pr, as, rec, fr] = await Promise.all([api.get(base), api.get(`${base}/assignments`), api.get(`${base}/records`), api.get('/personnel/field-rules')])
    person.value = pr.data
    assignments.value = as.data
    records.value = rec.data
    rules.value = fr.data
    if (rec.data.can.view_access_log) accessLog.value = (await api.get(`${base}/access-log`)).data.data
    if (props.self) myRequests.value = (await api.get(`${base}/change-requests`)).data.data
  } catch (e) {
    error.value = apiMessage(e, 'โหลดประวัติบุคลากรไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}
watch(targetUuid, load, { immediate: true })

// ------------------------------------------------------------------ เปิดดูข้อมูลอ่อนไหว
const revealEl = ref(null)
const revealField = ref('')
const revealReason = ref('')
const busy = ref(false)
const dialogError = ref('')
const openReveal = (field) => {
  revealField.value = field
  revealReason.value = ''
  dialogError.value = ''
  revealEl.value.showModal()
}
const confirmReveal = async () => {
  busy.value = true
  dialogError.value = ''
  try {
    const { data } = await api.post(`/personnel/${targetUuid.value}/reveal`, { fields: [revealField.value], reason: revealReason.value })
    revealed[revealField.value] = data.fields[revealField.value] ?? null
    revealEl.value.close()
    if (records.value?.can.view_access_log) accessLog.value = (await api.get(`/personnel/${targetUuid.value}/access-log`)).data.data
  } catch (e) {
    dialogError.value = apiMessage(e)
  } finally {
    busy.value = false
  }
}

// ------------------------------------------------------------------ แก้ไข / ยื่นคำขอ
const editEl = ref(null)
const editing = ref(null)
const evidenceFile = ref(null)
const openEdit = (f) => {
  editing.value = { mode: 'edit', entity: f.entity, fields: [f.field], title: fieldLabel(f.field), values: { [f.field]: f.masked ? '' : (f.value ?? '') } }
  dialogError.value = ''
  editEl.value.showModal()
}
const openRequest = (entity, fields, record = null) => {
  const source = record || (entity === 'personnel' ? person.value.personnel : person.value.profile) || {}
  editing.value = { mode: 'request', entity, fields, record, title: record ? ENTITY_LABELS[entity] : fields.map(fieldLabel).join(', '),
    values: Object.fromEntries(fields.map((f) => [f, rules.value[entity]?.[f]?.class === 'sensitive' ? '' : (source[f] ?? '')])) }
  evidenceFile.value = null
  dialogError.value = ''
  editEl.value.showModal()
}
const openRecord = (type, record = null) => {
  const sec = RECORD_SECTIONS.find((s) => s.type === type)
  if (props.self) return openRequest(sec.entity, sec.fields, record)
  editing.value = { mode: 'record', type, record, entity: sec.entity, fields: sec.fields, title: sec.title, values: Object.fromEntries(sec.fields.map((f) => [f, record?.[f] ?? ''])) }
  dialogError.value = ''
  editEl.value.showModal()
}
const cleanValues = (values) => Object.fromEntries(Object.entries(values).map(([k, v]) => [k, v === '' ? null : v]))
const saveEdit = async () => {
  busy.value = true
  dialogError.value = ''
  const ed = editing.value
  try {
    if (ed.mode === 'edit') {
      person.value = (await api.patch(`/personnel/${targetUuid.value}`, { entity: ed.entity, changes: cleanValues(ed.values) })).data
      alerts.success('บันทึกแล้ว')
    } else if (ed.mode === 'record') {
      const values = Object.fromEntries(Object.entries(cleanValues(ed.values)).filter(([, v]) => v !== null))
      if (ed.record) await api.patch(`/personnel/${targetUuid.value}/records/${ed.type}/${ed.record.uuid}`, values)
      else await api.post(`/personnel/${targetUuid.value}/records/${ed.type}`, values)
      alerts.success('บันทึกแล้ว')
    } else {
      const evidence = []
      if (evidenceFile.value) {
        const form = new FormData()
        form.append('file', evidenceFile.value)
        form.append('category', 'evidence')
        evidence.push((await api.post(`/personnel/${targetUuid.value}/documents`, form, { headers: { 'Content-Type': 'multipart/form-data' } })).data.uuid)
      }
      const payload = Object.fromEntries(Object.entries(cleanValues(ed.values)).filter(([k, v]) => v !== null || ed.record?.[k] !== undefined))
      await api.post(`/personnel/${targetUuid.value}/change-requests`, { entity: ed.entity, record_uuid: ed.record?.uuid || null, payload, evidence_document_uuids: evidence })
      alerts.success('ยื่นคำขอแล้ว งานบุคลากรจะตรวจและแจ้งผล')
    }
    editEl.value.close()
    await load()
  } catch (e) {
    dialogError.value = apiMessage(e)
  } finally {
    busy.value = false
  }
}

// ------------------------------------------------------------------ เอกสาร: ขอลิงก์ชั่วคราวแล้วดาวน์โหลดด้วย token
const upload = async (event, category) => {
  const file = event.target.files[0]
  event.target.value = ''
  if (!file) return
  const form = new FormData()
  form.append('file', file)
  form.append('category', category)
  try {
    await api.post(`/personnel/${targetUuid.value}/documents`, form, { headers: { 'Content-Type': 'multipart/form-data' } })
    alerts.success('อัปโหลดแล้ว')
    records.value = (await api.get(`/personnel/${targetUuid.value}/records`)).data
  } catch (e) {
    alerts.error(apiMessage(e, 'อัปโหลดไม่สำเร็จ'))
  }
}
const openDocument = async (doc) => {
  try {
    const { data } = await api.get(`/personnel/${targetUuid.value}/documents/${doc.uuid}/link`)
    const file = await api.get(data.url.replace(/^\/api/, ''), { responseType: 'blob' })
    const url = URL.createObjectURL(file.data)
    window.open(url, '_blank', 'noopener')
    setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch (e) {
    alerts.error(apiMessage(e, 'เปิดเอกสารไม่สำเร็จ'))
  }
}

// ------------------------------------------------------------------ คำสั่งแต่งตั้ง
const assignEl = ref(null)
const endingAssignment = ref(null)
const units = ref([])
const positions = ref([])
const assignForm = reactive({ kind: 'primary', org_unit_uuid: '', position_uuid: '', order_number: '', order_date: '', effective_from: '', effective_to: '', end_reason: '' })
const openAssign = async () => {
  endingAssignment.value = null
  Object.assign(assignForm, { kind: 'primary', org_unit_uuid: '', position_uuid: '', order_number: '', order_date: '', effective_from: '', effective_to: '', end_reason: '' })
  dialogError.value = ''
  try {
    const [u, ps] = await Promise.all([api.get('/org/units'), api.get('/org/positions')])
    units.value = u.data
    positions.value = ps.data.data || ps.data
  } catch (e) {
    dialogError.value = apiMessage(e, 'โหลดรายการหน่วยงาน/ตำแหน่งไม่สำเร็จ')
  }
  assignEl.value.showModal()
}
const openEnd = (a) => {
  endingAssignment.value = a
  Object.assign(assignForm, { effective_to: new Date().toISOString().slice(0, 10), end_reason: '' })
  dialogError.value = ''
  assignEl.value.showModal()
}
const saveAssign = async () => {
  busy.value = true
  dialogError.value = ''
  try {
    if (endingAssignment.value) {
      await api.post(`/org/assignments/${endingAssignment.value.uuid}/end`, { effective_to: assignForm.effective_to, end_reason: assignForm.end_reason })
    } else {
      const { end_reason, ...payload } = assignForm
      await api.post(`/personnel/${targetUuid.value}/assignments`, { ...payload, effective_to: payload.effective_to || null })
    }
    alerts.success('บันทึกแล้ว')
    assignEl.value.close()
    await load()
  } catch (e) {
    dialogError.value = apiMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.head { display: flex; gap: 18px; align-items: flex-start; flex-wrap: wrap; }
.avatar { width: 64px; height: 64px; border-radius: 999px; background: #D6E6F2; color: #1C4F73; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; flex: none; }
.pos-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border: 1px solid var(--line); border-radius: 999px; font-size: 13px; background: var(--bg-2); }
.legend { gap: 8px; }
.frow { display: grid; grid-template-columns: minmax(220px, 1fr) minmax(0, 2fr) auto; gap: 12px; align-items: center; padding: 10px 0; border-top: 1px solid var(--line-2); }
.flabel { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; font-size: 14px; color: var(--ink-2); }
.fvalue { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; font-size: 14px; min-width: 0; word-break: break-word; }
.factions { display: flex; justify-content: flex-end; }
.rec { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 12px 0; border-top: 1px solid var(--line-2); flex-wrap: wrap; }
.strong { font-weight: 600; }
@media (max-width: 640px) {
  .frow { grid-template-columns: 1fr; gap: 6px; }
  .factions { justify-content: flex-start; }
}
</style>
