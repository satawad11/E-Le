<template>
  <div class="page">
    <div class="page-head">
      <div class="stack" style="gap: 4px">
        <h1 class="page-title">โครงสร้างองค์กร</h1>
        <span class="lead">ขอบเขตสิทธิ์และสายอนุมัติคำนวณจากต้นไม้นี้โดยอัตโนมัติ</span>
      </div>
      <button v-can="'org.unit.manage'" type="button" class="btn pri" @click="openCreate(null)">เพิ่มหน่วยงาน</button>
    </div>

    <div class="row legend" aria-label="ระดับหน่วยงาน">
      <span v-for="l in LEVELS" :key="l.lv" class="row" style="gap: 6px"><span class="lv" :class="`lv-${l.lv}`">L{{ l.lv }}</span><span class="sub">{{ l.label }}</span></span>
    </div>

    <p v-if="error" class="banner bad" role="alert">{{ error }}</p>

    <div class="two-col">
      <section class="card main-col tree" aria-label="ต้นไม้หน่วยงาน">
        <p v-if="!units.length && !error" class="empty">กำลังโหลด…</p>
        <button
          v-for="u in units"
          :key="u.uuid"
          type="button"
          class="node"
          :class="{ on: selected === u.uuid }"
          :style="{ paddingLeft: 14 + (u.depth - minDepth) * 24 + 'px' }"
          :aria-current="selected === u.uuid"
          @click="select(u.uuid)"
        >
          <span class="lv" :class="`lv-${u.type.level}`">L{{ u.type.level }}</span>
          <span class="stack node-text" style="gap: 2px">
            <span class="strong">{{ u.name }}</span>
            <span class="sub" :class="{ vacant: !u.head }">{{ headText(u) }}</span>
          </span>
          <span class="sub count">{{ u.members }} คน</span>
        </button>
      </section>

      <section v-if="detail" class="card pad side-col detail" aria-live="polite">
        <span class="sub">{{ detail.path.join(' › ') }}</span>
        <div class="row"><span class="lv" :class="`lv-${detail.type.level}`">L{{ detail.type.level }}</span><h2 class="card-title">{{ detail.name }}</h2></div>
        <div class="row stats">
          <div class="stack" style="gap: 2px"><span class="sub">บุคลากรในหน่วย (รวมหน่วยย่อย)</span><span class="big">{{ membersOf(detail.uuid) }}</span></div>
          <div class="stack" style="gap: 2px"><span class="sub">หน่วยงานย่อย</span><span class="big">{{ detail.children.length }}</span></div>
        </div>

        <div class="stack" style="gap: 4px">
          <span class="sub">ผู้ดำรงตำแหน่งหัวหน้า</span>
          <span :class="{ vacant: !detail.head }">{{ detail.head ? `${detail.head.acting ? 'รักษาการ' : ''}${detail.head.position} · ${detail.head.name}` : 'ตำแหน่งว่าง · ระบบส่งเรื่องต่อหัวหน้าระดับถัดไป' }}</span>
          <span v-if="detail.head?.acting && detail.head.effective_to" class="sub">รักษาการถึง {{ thaiDate(detail.head.effective_to) }}</span>
        </div>

        <div class="stack" style="gap: 8px">
          <span class="strong">สายอนุมัติของบุคลากรในหน่วยนี้</span>
          <ol class="chain">
            <li v-for="c in detail.approval_chain" :key="c.step" :class="{ skipped: c.skipped }">
              <span class="step">{{ c.step }}</span>
              <span class="stack" style="gap: 2px; min-width: 0">
                <span v-if="c.skipped" class="vacant">หัวหน้าระดับ L{{ c.level }}{{ c.unit ? ` · ${c.unit}` : '' }} — {{ c.skip_reason }} · ข้ามไปชั้นถัดไป</span>
                <span v-else>{{ c.approvers.map((a) => `${a.position} · ${a.name}`).join(' / ') }}<template v-if="c.merged_with_previous"> (คนเดียวกับขั้นก่อน รวมเป็นขั้นเดียว)</template></span>
                <span class="sub">L{{ c.level }}</span>
              </span>
            </li>
          </ol>
          <span v-if="detail.chain_unresolved" class="banner warn">ไม่พบผู้อนุมัติในทุกชั้น — เรื่องจะเข้าคิวงานบุคคล</span>
          <span class="sub">คำนวณโดยเซิร์ฟเวอร์จากผู้ดำรงตำแหน่งจริง ณ วันนี้ · ชั้นที่ว่างหรือเป็นผู้ยื่นเองจะถูกข้ามขึ้นชั้นถัดไปอัตโนมัติ · แต่ละโมดูลกำหนดได้ว่าใช้กี่ชั้น</span>
        </div>

        <div class="stack" style="gap: 8px">
          <span class="strong">หน่วยงานย่อย</span>
          <span v-if="!detail.children.length" class="sub">ไม่มีหน่วยงานย่อย</span>
          <button v-for="k in detail.children" :key="k.uuid" type="button" class="child" @click="select(k.uuid)">
            <span class="lv" :class="`lv-${k.type.level}`">L{{ k.type.level }}</span> {{ k.name }}
          </button>
        </div>

        <div v-if="detail.can.manage" class="row">
          <router-link v-can="'personnel.assignment.manage'" to="/org/assignments" class="btn small">แต่งตั้ง / มอบหมาย</router-link>
          <button type="button" class="btn small" @click="openCreate(detail)">เพิ่มหน่วยย่อย</button>
          <button type="button" class="btn small" @click="openRename(detail)">แก้ไข</button>
        </div>
      </section>
    </div>

    <dialog ref="dialogEl" class="dm-dialog" aria-labelledby="unit-title">
      <form class="dialog-body" @submit.prevent="save">
        <h2 id="unit-title">{{ form.mode === 'rename' ? 'แก้ไขหน่วยงาน' : 'เพิ่มหน่วยงาน' }}<template v-if="form.parent"> ใต้ {{ form.parent.name }}</template></h2>
        <label v-if="form.mode === 'create'" class="field">รหัสหน่วยงาน<input v-model.trim="form.code" class="input" required maxlength="80"></label>
        <label class="field">ชื่อหน่วยงาน<input v-model.trim="form.name" class="input" required maxlength="255"></label>
        <template v-if="form.mode === 'create'">
          <label class="field">ประเภท/ระดับ
            <select v-model="form.type_code" class="input" required>
              <option v-for="t in TYPES" :key="t.code" :value="t.code">L{{ t.level }} · {{ t.name }}</option>
            </select>
          </label>
          <label class="field">มีผลตั้งแต่<input v-model="form.effective_from" type="date" class="input" required></label>
        </template>
        <p v-if="dialogError" class="banner bad" role="alert">{{ dialogError }}</p>
        <div class="dialog-buttons">
          <button type="button" class="btn" @click="dialogEl.close()">ยกเลิก</button>
          <button type="submit" class="btn pri" :disabled="busy">บันทึก</button>
        </div>
      </form>
    </dialog>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../../axios'
import { useAlertStore } from '../../stores/alert'
import { apiMessage, thaiDate } from '../../utils/dm'

const LEVELS = [{ lv: 1, label: 'สถาบัน' }, { lv: 2, label: 'ฝ่าย' }, { lv: 3, label: 'งาน / แผนกวิชา' }, { lv: 4, label: 'สาขางาน / กลุ่มงาน' }, { lv: 5, label: 'บุคลากร' }]
const alerts = useAlertStore()
const units = ref([])
const selected = ref(null)
const detail = ref(null)
const error = ref('')

const minDepth = computed(() => Math.min(...units.value.map((u) => u.depth), 99))
const TYPES = computed(() => {
  const seen = new Map()
  units.value.forEach((u) => seen.set(u.type.code, u.type))
  return [...seen.values()].sort((a, b) => a.level - b.level)
})
const headText = (u) => (u.head ? `${u.head.acting ? 'รักษาการ' : ''}${u.head.position} · ${u.head.name}` : 'ตำแหน่งว่าง')
const membersOf = (uuid) => units.value.find((u) => u.uuid === uuid)?.members ?? 0

const loadUnits = async () => {
  try {
    // เรียงแบบต้นไม้ (หน่วยแม่ก่อนหน่วยลูก) จาก parent_uuid ที่ API ส่งมา — ไม่กรองหรือเพิ่มรายการเอง
    const list = (await api.get('/org/units')).data.filter((u) => !u.effective_to)
    const byParent = new Map()
    list.forEach((u) => byParent.set(u.parent_uuid, [...(byParent.get(u.parent_uuid) || []), u]))
    const known = new Set(list.map((u) => u.uuid))
    const ordered = []
    const walk = (u) => { ordered.push(u); (byParent.get(u.uuid) || []).forEach(walk) }
    list.filter((u) => !known.has(u.parent_uuid)).forEach(walk)
    units.value = ordered
  } catch (e) {
    error.value = apiMessage(e, 'โหลดโครงสร้างองค์กรไม่สำเร็จ')
  }
}
const select = async (uuid) => {
  selected.value = uuid
  try {
    detail.value = (await api.get(`/org/units/${uuid}`)).data
  } catch (e) {
    error.value = apiMessage(e, 'โหลดรายละเอียดหน่วยงานไม่สำเร็จ')
  }
}

onMounted(async () => {
  await loadUnits()
  const first = units.value.find((u) => u.type.level === 3) || units.value[0]
  if (first) select(first.uuid)
})

// ------------------------------------------------------------------ เพิ่ม/แก้ไขหน่วย (ปุ่มแสดงตาม can.manage จาก server)
const dialogEl = ref(null)
const form = reactive({ mode: 'create', parent: null, unit: null, code: '', name: '', type_code: '', effective_from: '' })
const busy = ref(false)
const dialogError = ref('')
const openCreate = (parent) => {
  Object.assign(form, { mode: 'create', parent, unit: null, code: '', name: '', type_code: TYPES.value.find((t) => t.level === (parent ? parent.type.level + 1 : 2))?.code || '', effective_from: new Date().toISOString().slice(0, 10) })
  dialogError.value = ''
  dialogEl.value.showModal()
}
const openRename = (unit) => {
  Object.assign(form, { mode: 'rename', parent: null, unit, name: unit.name })
  dialogError.value = ''
  dialogEl.value.showModal()
}
const save = async () => {
  busy.value = true
  dialogError.value = ''
  try {
    if (form.mode === 'rename') {
      await api.put(`/org/units/${form.unit.uuid}`, { name: form.name })
    } else {
      await api.post('/org/units', { code: form.code, name: form.name, type_code: form.type_code, parent_uuid: form.parent?.uuid || null, effective_from: form.effective_from })
    }
    alerts.success('บันทึกแล้ว')
    dialogEl.value.close()
    await loadUnits()
    if (selected.value) select(selected.value)
  } catch (e) {
    dialogError.value = apiMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.legend { gap: 16px; }
.tree { display: flex; flex-direction: column; overflow: hidden; }
.node { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 0; border-bottom: 1px solid var(--line-2); background: #fff; font: inherit; text-align: left; cursor: pointer; color: var(--ink); min-height: 56px; }
.node:hover { background: var(--bg-2); }
.node.on { background: var(--info-bg); }
.node-text { flex: 1; min-width: 0; font-size: 14px; }
.count { flex: none; }
.strong { font-weight: 600; }
.vacant { color: var(--bad); }
.detail { gap: 16px; }
.stats { gap: 32px; }
.big { font-size: 24px; font-weight: 700; }
.chain { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.chain li { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; }
.chain li.skipped .vacant { text-decoration: line-through; }
.step { width: 24px; height: 24px; border-radius: 999px; background: var(--bg-3); display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; flex: none; }
.child { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border: 1px solid var(--line-2); border-radius: 8px; background: #fff; font: inherit; font-size: 14px; cursor: pointer; text-align: left; }
.child:hover { background: var(--bg-2); }
</style>
