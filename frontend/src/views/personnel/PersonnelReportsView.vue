<template>
  <div class="page">
    <div class="page-head">
      <div class="stack" style="gap: 4px">
        <h1 class="page-title">รายงานบุคลากร</h1>
        <span class="lead">{{ scopeText }}</span>
      </div>
    </div>
    <p v-if="error" class="banner bad" role="alert">{{ error }}</p>
    <p v-if="!data && !error" class="empty">กำลังโหลด…</p>

    <template v-if="data">
      <div class="two-col">
        <section class="card pad main-col stack">
          <h2 class="card-title">อัตรากำลังตามหน่วยงาน <span class="sub">ปฏิบัติงานรวม {{ data.headcount.total }} คน (ตามตำแหน่งหลัก)</span></h2>
          <div class="table-scroll">
            <table class="dt">
              <thead><tr><th>หน่วยงาน</th><th style="text-align: right">จำนวน</th></tr></thead>
              <tbody>
                <tr v-if="!data.headcount.by_unit.length"><td colspan="2" class="empty">ไม่มีข้อมูล</td></tr>
                <tr v-for="u in data.headcount.by_unit" :key="u.name"><td :style="{ paddingLeft: 14 + Math.max(0, u.depth) * 18 + 'px' }">{{ u.name }}</td><td style="text-align: right">{{ u.n }}</td></tr>
              </tbody>
            </table>
          </div>
        </section>
        <section class="card pad side-col">
          <h2 class="card-title">ตามประเภทบุคลากร</h2>
          <div v-for="t in data.headcount.by_type" :key="t.name" class="row between"><span>{{ t.name }}</span><span class="strong">{{ t.n }}</span></div>
          <p v-if="!data.headcount.by_type.length" class="sub">ไม่มีข้อมูล</p>
        </section>
      </div>

      <section class="card pad stack">
        <h2 class="card-title">ใบอนุญาตประกอบวิชาชีพใกล้หมดอายุ <span class="sub">ภายใน 90 วัน</span></h2>
        <p v-if="!data.licenses_expiring.length" class="sub">ไม่มีรายการ</p>
        <div class="table-scroll" v-else>
          <table class="dt" style="min-width: 560px">
            <thead><tr><th>บุคลากร</th><th>ใบอนุญาต</th><th>หมดอายุ</th></tr></thead>
            <tbody>
              <tr v-for="(l, i) in data.licenses_expiring" :key="i">
                <td><router-link :to="`/personnel/${l.personnel.uuid}`" class="link">{{ l.personnel.full_name }}</router-link> <span class="sub mono">{{ l.personnel.personnel_code }}</span></td>
                <td>{{ l.license_type }}</td>
                <td><span class="tag warn">{{ thaiDate(l.expires_at) }} · อีก {{ l.days_left }} วัน</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="card pad stack">
        <h2 class="card-title">ข้อมูลไม่ครบ</h2>
        <p v-if="!data.incomplete.length" class="sub">ข้อมูลครบทุกคน</p>
        <div v-for="p in data.incomplete" :key="p.uuid" class="row between line">
          <router-link :to="`/personnel/${p.uuid}`" class="link">{{ p.full_name }} <span class="sub mono">{{ p.personnel_code }}</span></router-link>
          <span class="row" style="gap: 6px"><span v-for="m in p.missing" :key="m" class="tag warn">ขาด{{ m }}</span></span>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../axios'
import { apiMessage, thaiDate } from '../../utils/dm'

const data = ref(null)
const error = ref('')
const scopeText = computed(() => {
  const all = (data.value?.access || []).find((a) => a.scope === 'all')
  if (all) return 'ทั้งวิทยาลัย'
  const units = [...new Set((data.value?.access || []).flatMap((a) => a.units))]
  return units.length ? `เฉพาะ${units.join(', ')} และหน่วยงานย่อย` : ''
})

onMounted(async () => {
  try {
    data.value = (await api.get('/personnel/reports')).data
  } catch (e) {
    error.value = apiMessage(e, 'โหลดรายงานไม่สำเร็จ')
  }
})
</script>

<style scoped>
.strong { font-weight: 600; }
.line { padding: 10px 0; border-top: 1px solid var(--line-2); font-size: 14px; }
.side-col.card { gap: 10px; font-size: 14px; }
</style>
