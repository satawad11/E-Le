import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.directive('can', {
  mounted(el, binding) {
    const authStore = useAuthStore()
    if (!authStore.isAdmin && !authStore.hasPermission(binding.value)) {
      el.style.display = 'none'
    }
  },
  updated(el, binding) {
    const authStore = useAuthStore()
    if (!authStore.isAdmin && !authStore.hasPermission(binding.value)) {
      el.style.display = 'none'
    } else {
      el.style.display = ''
    }
  }
})

app.mount('#app')
