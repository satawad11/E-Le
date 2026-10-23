<template>
  <div class="page">
    <div class="page-head">
      <div class="stack" style="gap: 4px">
        <h1 class="page-title">คำสั่งแต่งตั้งและมอบหมาย</h1>
        <span class="lead">บันทึกตามคำสั่งใหม่ได้จากแท็บ "ตำแหน่งและสังกัด" ในประวัติบุคลากร · ไม่มีการแก้หรือลบ (ปิดแถวเดิมแล้วเพิ่มแถวใหม่)</span>
      </div>
    </div>

    <div class="row">
      <div class="chips" role="group" aria-label="สถานะคำสั่ง">
        <button v-for="(label, key) in STATES" :key="key" type="button" class="chip" :class="{ on: state === key }" :aria-pressed="state === key" @click="state = key; load()">{{ label }}</button>
      </div>
      <label class="field" style="flex: 1 1 240px">
        <span class="sr-only">ค้นหา</span>
        <input v-model="q" type="search" class="input" placeholder="ค้นหาชื่อ รหัสบุคลากร หรือเลขคำสั่ง">
      </label>
    </div>

    <p v-if="error" class="banner bad" role="alert">{{ error }}</p>

    <section class="card" style="overflow: hidden">
      <div class="table-scroll">
        <table class="dt" style="min-width: 900px">
          <thead><tr><th>บุคลากร</th><th>ประเภท</th><th>ตำแหน่ง</th><th>หน่วยงาน</th><th>ระดับ</th><th>มีผล</th><th>สิ้นสุด</th><th>คำสั่งที่</th></tr></thead>
          <tbody>
            <tr v-if="loading && !rows.length"><td colspan="8" class="empty">กำลังโหลด…</td></tr>
            <tr v-else-if="!rows.length"><td colspan="8" class="empty">ไม่มีรายการ</td></tr>
            <tr v-for="a in rows" :key="a.uuid">
              <td><router-link :to="`/personnel/${a.personnel.uuid}`" class="link">{{ a.personnel.full_name }}</router-link><br><span class="sub mono">{{ a.personnel.personnel_code }}</span></td>
              <td>{{ KIND_LABELS[a.kind] }}</td>
              <td>{{ a.position?.name }}</td>
              <td>{{ a.org_unit?.name }}</td>
              <td><span class="lv" :class="`lv-${a.level}`">L{{ a.level }}</span></td>
              <td>{{ thaiDate(a.effective_from) }}</td>
              <td>{{ a.effective_to ? thaiDate(a.effective_to) : '—' }}</td>
              <td class="mono">{{ a.order_number || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue'
import api from '../../axios'
import { KIND_LABELS, apiMessage, thaiDate } from '../../utils/dm'

const STATES = { active: 'มีผลอยู่', upcoming: 'รอมีผล', ended: 'สิ้นสุดแล้ว' }
const rows = ref([])
const state = ref('active')
const q = ref('')
const loading = ref(false)
const error = ref('')

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    rows.value = (await api.get('/org/assignments', { params: { state: state.value, q: q.value || undefined } })).data.data
  } catch (e) {
    error.value = apiMessage(e, 'โหลดคำสั่งไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}
let timer = null
watch(q, () => { clearTimeout(timer); timer = setTimeout(load, 300) })
onMounted(load)
</script>
