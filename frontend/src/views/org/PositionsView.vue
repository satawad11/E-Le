<template>
  <div class="page">
    <div class="page-head">
      <div class="stack" style="gap: 4px">
        <h1 class="page-title">ตำแหน่ง</h1>
        <span class="lead">ตำแหน่งสายงาน และตำแหน่งบริหารหนึ่งตำแหน่งต่อระดับหน่วย · ไม่ลบ ใช้ปิดใช้งาน</span>
      </div>
      <button type="button" class="btn pri" @click="openForm(null)">เพิ่มตำแหน่ง</button>
    </div>

    <p v-if="error" class="banner bad" role="alert">{{ error }}</p>

    <section class="card" style="overflow: hidden">
      <div class="table-scroll">
        <table class="dt" style="min-width: 640px">
          <thead><tr><th>รหัส</th><th>ชื่อตำแหน่ง</th><th>ประเภท</th><th>ระดับหน่วย</th><th>สถานะ</th><th><span class="sr-only">การดำเนินการ</span></th></tr></thead>
          <tbody>
            <tr v-if="!rows.length"><td colspan="6" class="empty">{{ loading ? 'กำลังโหลด…' : 'ยังไม่มีตำแหน่ง' }}</td></tr>
            <tr v-for="p in rows" :key="p.uuid" :class="{ dim: !p.is_active }">
              <td class="mono">{{ p.code }}</td>
              <td>{{ p.name }}</td>
              <td>{{ p.kind === 'management' ? 'ตำแหน่งบริหาร' : 'ตำแหน่งสายงาน' }}</td>
              <td><span v-if="p.org_unit_type" class="lv" :class="`lv-${p.org_unit_type.level}`">L{{ p.org_unit_type.level }}</span> {{ p.org_unit_type?.name || '—' }}</td>
              <td><span class="tag" :class="p.is_active ? 'ok' : 'mute'">{{ p.is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}</span></td>
              <td><button type="button" class="btn small" @click="openForm(p)">แก้ไข</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <dialog ref="dialogEl" class="dm-dialog" aria-labelledby="pos-title">
      <form class="dialog-body" @submit.prevent="save">
        <h2 id="pos-title">{{ form.uuid ? 'แก้ไขตำแหน่ง' : 'เพิ่มตำแหน่ง' }}</h2>
        <label v-if="!form.uuid" class="field">รหัส<input v-model.trim="form.code" class="input" required maxlength="50"></label>
        <label class="field">ชื่อตำแหน่ง<input v-model.trim="form.name" class="input" required maxlength="255"></label>
        <template v-if="!form.uuid">
          <label class="field">ประเภท
            <select v-model="form.kind" class="input"><option value="line">ตำแหน่งสายงาน</option><option value="management">ตำแหน่งบริหาร</option></select>
          </label>
          <label v-if="form.kind === 'management'" class="field">ระดับหน่วยที่บริหาร
            <select v-model="form.org_unit_type_code" class="input" required>
              <option v-for="t in unitTypes" :key="t.code" :value="t.code">L{{ t.level }} · {{ t.name }}</option>
            </select>
          </label>
        </template>
        <label v-else class="check"><input v-model="form.is_active" type="checkbox"> ใช้งาน</label>
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
import { apiMessage } from '../../utils/dm'

const alerts = useAlertStore()
const rows = ref([])
const units = ref([])
const loading = ref(false)
const error = ref('')
const unitTypes = computed(() => {
  const seen = new Map()
  units.value.forEach((u) => seen.set(u.type.code, u.type))
  return [...seen.values()].sort((a, b) => a.level - b.level)
})

const load = async () => {
  loading.value = true
  try {
    rows.value = (await api.get('/org/positions')).data
  } catch (e) {
    error.value = apiMessage(e, 'โหลดตำแหน่งไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}
onMounted(async () => {
  await load()
  try { units.value = (await api.get('/org/units')).data } catch (e) { /* ใช้เฉพาะตอนเพิ่มตำแหน่งบริหาร */ }
})

const dialogEl = ref(null)
const form = reactive({ uuid: null, code: '', name: '', kind: 'line', org_unit_type_code: '', is_active: true })
const busy = ref(false)
const dialogError = ref('')
const openForm = (p) => {
  Object.assign(form, p ? { uuid: p.uuid, name: p.name, is_active: p.is_active } : { uuid: null, code: '', name: '', kind: 'line', org_unit_type_code: '', is_active: true })
  dialogError.value = ''
  dialogEl.value.showModal()
}
const save = async () => {
  busy.value = true
  dialogError.value = ''
  try {
    if (form.uuid) await api.put(`/org/positions/${form.uuid}`, { name: form.name, is_active: form.is_active })
    else await api.post('/org/positions', { code: form.code, name: form.name, kind: form.kind, org_unit_type_code: form.kind === 'management' ? form.org_unit_type_code : null })
    alerts.success('บันทึกแล้ว')
    dialogEl.value.close()
    load()
  } catch (e) {
    dialogError.value = apiMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.check { display: flex; align-items: center; gap: 8px; font-size: 14px; min-height: 40px; }
</style>
