import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'
import canDirective from './directives/can'
import secureSrcDirective from './directives/secureSrc'

// R-06: route ทั้งหมดประกาศใน router/index.js (ระบบใหม่) และ router/legacy.js (ระบบเดิมที่รอย้ายใน R-08)

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
