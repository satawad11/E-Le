<template>
  <div class="page">
    <div class="page-head">
      <div class="stack" style="gap: 4px">
        <h1 class="page-title">คำขอแก้ไขรอตรวจ</h1>
        <span class="lead">ค่าเดิมยังแสดงในระบบจนกว่าคำขอจะได้รับอนุมัติ</span>
      </div>
    </div>

    <div class="chips" role="group" aria-label="สถานะคำขอ">
      <button v-for="(v, k) in REQUEST_STATUS" :key="k" type="button" class="chip" :class="{ on: status === k }" :aria-pressed="status === k" @click="setStatus(k)">{{ v[0] }}</button>
    </div>

    <p v-if="error" class="banner bad" role="alert">{{ error }}</p>

    <div class="two-col">
      <section class="card queue" aria-label="รายการคำขอ">
        <p v-if="loading && !items.length" class="empty">กำลังโหลด…</p>
        <p v-else-if="!items.length" class="empty">ไม่มีคำขอในสถานะนี้</p>
        <button v-for="q in items" :key="q.uuid" type="button" class="qitem" :class="{ on: current?.uuid === q.uuid }" :aria-current="current?.uuid === q.uuid" @click="select(q)">
          <span class="row between" style="gap: 8px">
            <span class="strong">{{ ENTITY_LABELS[q.entity] }}{{ q.is_new_record ? ' (เพิ่มใหม่)' : '' }}</span>
            <span class="tag" :class="q.locked_reason ? 'mute' : REQUEST_STATUS[q.status]?.[1]">{{ q.locked_reason ? 'ล็อก' : REQUEST_STATUS[q.status]?.[0] }}</span>
          </span>
          <span>{{ q.personnel.full_name }}</span>
          <span class="sub">{{ q.personnel.unit || '—' }} · ยื่น {{ thaiDate(q.created_at) }}</span>
        </button>
      </section>

      <section v-if="current" class="card pad detail stack" aria-live="polite">
        <div class="row between">
          <div class="stack" style="gap: 2px">
            <span class="sub">{{ current.personnel.unit || '—' }} · ยื่น {{ thaiDateTime(current.created_at) }} โดย {{ current.requested_by }}</span>
            <h2 class="card-title">{{ ENTITY_LABELS[current.entity] }}{{ current.is_new_record ? ' (เพิ่มใหม่)' : '' }} · {{ current.personnel.full_name }}</h2>
          </div>
          <span class="tag" :class="REQUEST_STATUS[current.status]?.[1]">{{ REQUEST_STATUS[current.status]?.[0] }}</span>
        </div>

        <div class="table-scroll">
          <table class="dt" style="min-width: 520px">
            <thead><tr><th>ฟิลด์</th><th>ค่าปัจจุบัน</th><th>ค่าที่ขอแก้ไข</th></tr></thead>
            <tbody>
              <tr v-for="f in current.fields" :key="f.field">
                <td>{{ fieldLabel(f.field) }} <span v-if="f.masked" class="tag bad">ปิดบัง</span></td>
                <td :class="{ mono: f.masked }">{{ f.current ?? (current.is_new_record ? '—' : 'ไม่มีข้อมูล') }}</td>
                <td class="strong" :class="{ mono: f.masked }">{{ f.requested ?? 'ลบค่า' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="current.fields.some((f) => f.masked)" class="sub">ข้อมูลชั้นอ่อนไหวแสดงแบบปิดบัง ตรวจกับเอกสารต้นฉบับที่แนบ · ผู้มีสิทธิ์เปิดดูค่าปัจจุบันได้จากหน้าประวัติพร้อมเหตุผล</p>

        <div class="stack" style="gap: 8px">
          <span class="strong">หลักฐานแนบ</span>
          <span v-if="!current.evidence.length" class="sub">ไม่มีหลักฐานแนบ</span>
          <div v-for="d in current.evidence" :key="d.uuid" class="row between file">
            <span>{{ d.original_name }}</span>
            <button type="button" class="btn small" @click="openEvidence(d)">เปิดดู</button>
          </div>
        </div>

        <p v-if="current.locked_reason" class="banner warn">{{ current.locked_reason }}</p>

        <form v-else-if="current.can_review" class="stack" @submit.prevent>
          <span class="strong">รายการตรวจ</span>
          <label class="check"><input v-model="checks.evidence" type="checkbox"> หลักฐานตรงกับข้อมูลที่ขอแก้ไข</label>
          <label class="check"><input v-model="checks.source" type="checkbox"> ตรวจกับต้นฉบับหรือแหล่งที่ออกเอกสารแล้ว</label>
          <label class="field">หมายเหตุถึงผู้ยื่น (จำเป็นเมื่อส่งกลับหรือไม่อนุมัติ)
            <textarea v-model.trim="note" class="input" rows="3" maxlength="1000"></textarea>
          </label>
          <p v-if="actionError" class="banner bad" role="alert">{{ actionError }}</p>
          <div class="row">
            <button type="button" class="btn pri" :disabled="busy || !checks.evidence || !checks.source" @click="decide('approved')">อนุมัติ</button>
            <button type="button" class="btn" :disabled="busy || !note" @click="decide('returned')">ส่งกลับให้แก้ไข</button>
            <button type="button" class="btn danger" :disabled="busy || !note" @click="decide('rejected')">ไม่อนุมัติ</button>
          </div>
        </form>

        <div v-else-if="current.status !== 'pending'" class="stack" style="gap: 4px">
          <span class="strong">พิจารณาโดย {{ current.reviewed_by || '—' }} · {{ thaiDateTime(current.reviewed_at) }}</span>
          <span v-if="current.review_note" class="sub">หมายเหตุ: {{ current.review_note }}</span>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { useMenuStore } from '../../stores/menuStore'
import { ENTITY_LABELS, REQUEST_STATUS, apiMessage, fieldLabel, thaiDate, thaiDateTime } from '../../utils/dm'

const alerts = useAlertStore()
const menu = useMenuStore()
const items = ref([])
const current = ref(null)
const status = ref('pending')
const loading = ref(false)
const error = ref('')
const note = ref('')
const checks = reactive({ evidence: false, source: false })
const busy = ref(false)
const actionError = ref('')

const load = async (keep = null) => {
  loading.value = true
  error.value = ''
  try {
    items.value = (await api.get('/personnel/change-requests', { params: { status: status.value } })).data.data
    current.value = items.value.find((i) => i.uuid === keep) || items.value.find((i) => !i.locked_reason) || items.value[0] || null
  } catch (e) {
    error.value = apiMessage(e, 'โหลดคำขอไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}
const setStatus = (s) => { status.value = s; load() }
const select = (q) => {
  current.value = q
  note.value = ''
  checks.evidence = false
  checks.source = false
  actionError.value = ''
}

const decide = async (decision) => {
  busy.value = true
  actionError.value = ''
  try {
    await api.post(`/personnel/change-requests/${current.value.uuid}/review`, { decision, review_note: note.value || null })
    alerts.success({ approved: 'อนุมัติแล้ว ค่าใหม่มีผลทันที', returned: 'ส่งกลับให้ผู้ยื่นแล้ว', rejected: 'บันทึกว่าไม่อนุมัติแล้ว' }[decision])
    select({ uuid: null })
    await load()
    menu.load(true)
  } catch (e) {
    actionError.value = apiMessage(e)
  } finally {
    busy.value = false
  }
}

const openEvidence = async (doc) => {
  try {
    const { data } = await api.get(`/personnel/${current.value.personnel.uuid}/documents/${doc.uuid}/link`)
    const file = await api.get(data.url.replace(/^\/api/, ''), { responseType: 'blob' })
    const url = URL.createObjectURL(file.data)
    window.open(url, '_blank', 'noopener')
    setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch (e) {
    alerts.error(apiMessage(e, 'เปิดเอกสารไม่สำเร็จ'))
  }
}

onMounted(() => load())
</script>

<style scoped>
.queue { flex: 1 1 300px; min-width: 0; display: flex; flex-direction: column; overflow: hidden; }
.detail { flex: 2 1 520px; min-width: 0; }
.qitem { display: flex; flex-direction: column; gap: 4px; text-align: left; padding: 14px 16px; border: 0; border-bottom: 1px solid var(--line-2); background: #fff; font: inherit; font-size: 14px; color: var(--ink); cursor: pointer; }
.qitem:hover { background: var(--bg-2); }
.qitem.on { background: var(--info-bg); }
.strong { font-weight: 600; }
.file { padding: 8px 12px; border: 1px solid var(--line-2); border-radius: 8px; font-size: 14px; }
.check { display: flex; align-items: center; gap: 8px; font-size: 14px; min-height: 40px; }
.check input { width: 18px; height: 18px; accent-color: var(--pri); }
</style>
