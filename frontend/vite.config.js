import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    tailwindcss(),
  ],
  server: {
    watch: {
      usePolling: true,
    },
    /*
     * ชี้ไปที่ nginx ไม่ใช่ backend โดยตรง
     *
     * backend เป็น php-fpm (FastCGI พอร์ต 9000) ไม่ได้พูด HTTP อีกต่อไป
     * และการผ่าน nginx ทำให้ dev server เจอกฎเดียวกับ production
     * เช่น `/storage` ที่ต้องเป็น 404 เสมอ (DEPLOY-004)
     */
    proxy: {
      '/api': {
        target: 'http://nginx',
        changeOrigin: true,
        headers: {
            Accept: 'application/json',
        }
      },
      '/storage': {
        target: 'http://nginx',
        changeOrigin: true,
      }
    }
  }
})
