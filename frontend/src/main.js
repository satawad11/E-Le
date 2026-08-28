import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'
import canDirective from './directives/can'
import secureSrcDirective from './directives/secureSrc'

/*
 * CLEANUP-01 (D1) — เส้นทางทั้งหมดอยู่ที่ `router/index.js` ที่เดียว
 *
 * ไฟล์นี้เคยเรียก `router.addRoute()` อีก 8 เส้นทางโดยใช้ชื่อ route ซ้ำกับที่
 * ประกาศไว้ใน `router/index.js` — Vue Router ถือว่าชื่อซ้ำคือ "แทนที่" ผลคือ
 * เงื่อนไขสิทธิ์และชื่อหน้าที่เขียนไว้ใน `router/index.js` ถูกทิ้งเงียบ ๆ
 * เช่น หน้าคัดกรองเลื่อนเงินเดือนและศูนย์งานกำกับการลาใช้สิทธิ์คนละชุดกับที่ตั้งใจ
 * และ `/profile/access-history` ถูกย้ายไป `/my-access-history` โดยไม่มีใครรู้
 */

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)
app.directive('can', canDirective)
app.directive('secure-src', secureSrcDirective)

// Wait for the initial auth guard and lazy route component before replacing
// the startup screen. This prevents a partially rendered shell after F5.
await router.isReady()
app.mount('#app')
