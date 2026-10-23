<template>
  <div class="page">
    <div class="page-head">
      <div class="stack" style="gap: 4px">
        <h1 class="page-title">สวัสดี คุณ{{ home?.greeting.name || '' }}</h1>
        <span class="lead">{{ thaiDateLong(home?.greeting.date) }}</span>
      </div>
      <span v-if="home?.cards.length" class="sub">ตัวเลขทั้งหมดอยู่ในขอบเขตสิทธิ์ของคุณ</span>
    </div>

    <p v-if="error" class="banner bad" role="alert">{{ error }}</p>
    <p v-if="loading && !home" class="empty">กำลังโหลด…</p>

    <template v-if="home">
      <div v-if="home.cards.length" class="cards">
        <router-link v-for="c in home.cards" :key="c.code" :to="c.route" class="card kpi">
          <span class="kpi-label">{{ c.label }}</span>
          <span class="kpi-value">{{ c.value.toLocaleString('th-TH') }}</span>
          <span class="sub">{{ c.note }}</span>
        </router-link>
      </div>

      <div class="two-col">
        <section class="card pad main-col stack" aria-labelledby="work-title">
          <h2 id="work-title" class="card-title">งานรอดำเนินการ <span class="sub">แยกตามตำแหน่งที่คุณกระทำ</span></h2>
          <p v-if="!home.work.length" class="empty">ไม่มีงานรอดำเนินการในขณะนี้</p>
          <div v-for="(g, i) in home.work" :key="i" class="work-group">
            <div class="row between">
              <span class="strong">ในฐานะ{{ g.as }}</span>
              <span class="tag warn">{{ g.total }} รายการ</span>
            </div>
            <ul class="work-list">
              <li v-for="(item, j) in g.items" :key="j" class="work-item">
                <div class="stack" style="gap: 2px; min-width: 0">
                  <span>{{ item.title }}</span>
                  <span class="sub">{{ [item.meta, item.date ? thaiDate(item.date) : null].filter(Boolean).join(' ') }}</span>
                </div>
                <router-link v-if="item.route" :to="item.route" class="btn small">{{ item.action || 'เปิด' }}</router-link>
              </li>
            </ul>
            <router-link v-if="g.route && g.total > g.items.length" :to="g.route" class="link">ดูทั้งหมด {{ g.total }} รายการ</router-link>
            <p v-if="g.note" class="sub">{{ g.note }}</p>
          </div>
        </section>

        <div class="side-col">
          <section class="card pad stack" aria-labelledby="pos-title">
            <h2 id="pos-title" class="card-title">ตำแหน่งของฉัน</h2>
            <p v-if="!home.positions.length" class="sub">ยังไม่มีตำแหน่งที่มีผล</p>
            <div v-for="(p, i) in home.positions" :key="i" class="pos">
              <span v-if="p.level" class="lv" :class="`lv-${p.level}`">L{{ p.level }}</span>
              <span v-else class="tag info">สิทธิ์</span>
              <div class="stack" style="gap: 2px; min-width: 0">
                <span class="strong">{{ p.position }}<template v-if="p.unit"> · {{ p.unit }}</template></span>
                <span class="sub">
                  {{ KIND_LABELS[p.kind] }}<template v-if="p.order_number"> · {{ p.order_number }}</template>
                  <template v-if="p.upcoming"> · มีผล {{ thaiDate(p.effective_from) }}</template>
                  <template v-else-if="p.effective_to"> · ถึง {{ thaiDate(p.effective_to) }}</template>
                </span>
              </div>
            </div>
            <span v-if="home.positions.some((p) => p.kind === 'primary')" class="sub">ตำแหน่งหลักใช้เป็นสายอนุมัติเมื่อคุณยื่นเรื่องเอง</span>
          </section>

          <section class="card pad stack" aria-labelledby="notice-title">
            <h2 id="notice-title" class="card-title">แจ้งเตือน</h2>
            <p v-if="!home.notices.length" class="sub">ไม่มีแจ้งเตือน</p>
            <div v-for="(n, i) in home.notices" :key="i" class="stack" style="gap: 2px">
              <span class="strong">{{ [n.title, n.date ? thaiDate(n.date) : null].filter(Boolean).join(' ') }}</span>
              <span class="sub">{{ n.text }}</span>
            </div>
          </section>

          <section class="card pad stack" aria-labelledby="mod-title">
            <h2 id="mod-title" class="card-title">โมดูลในระบบ <span class="sub">ใช้ระบบสิทธิ์กลางร่วมกัน</span></h2>
            <div v-for="m in home.modules" :key="m.code" class="row between">
              <span>{{ m.name }}</span>
              <span class="tag" :class="m.enabled ? 'ok' : 'mute'">{{ m.enabled ? 'ใช้งาน' : 'ปิด' }}</span>
            </div>
          </section>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../../axios'
import { KIND_LABELS, apiMessage, thaiDate, thaiDateLong } from '../../utils/dm'

const home = ref(null)
const loading = ref(false)
const error = ref('')

onMounted(async () => {
  loading.value = true
  try {
    home.value = (await api.get('/me/home')).data
  } catch (e) {
    error.value = apiMessage(e, 'โหลดหน้าหลักไม่สำเร็จ')
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; }
.kpi { padding: 18px 20px; display: flex; flex-direction: column; gap: 4px; text-decoration: none; color: inherit; }
.kpi:hover { border-color: var(--pri); }
.kpi-label { font-size: 14px; color: var(--ink-2); }
.kpi-value { font-size: 32px; font-weight: 700; }
.strong { font-weight: 600; }
.work-group { display: flex; flex-direction: column; gap: 10px; padding-top: 14px; border-top: 1px solid var(--line-2); }
.work-group:first-of-type { border-top: 0; padding-top: 0; }
.work-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.work-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; border: 1px solid var(--line-2); border-radius: 10px; font-size: 14px; }
.pos { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; }
</style>
