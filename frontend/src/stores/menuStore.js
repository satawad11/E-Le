import { defineStore } from 'pinia'
import api from '../axios'

/**
 * เมนูของผู้ใช้จาก GET /api/me/menu (R-06) — ชื่อเมนู (ตาม scope) และ badge มาจาก API ทั้งหมด ไม่มีรายการเมนูในโค้ดหน้าเว็บ
 */
export const useMenuStore = defineStore('menu', {
  state: () => ({ groups: [], loaded: false, loading: null, error: null }),
  getters: {
    /** เมนูทุกรายการ (รวมเมนูย่อย) เรียงแบน — ใช้หาชื่อหน้าและกลุ่มสำหรับ breadcrumb */
    flat: (state) => state.groups.flatMap((g) => g.items.flatMap((i) => [{ ...i, group: g }, ...(i.children || []).map((c) => ({ ...c, group: g, parent: i }))])),
    byRoute() {
      return (path) => this.flat.find((i) => i.route === path) || null
    },
  },
  actions: {
    async load(force = false) {
      if (this.loaded && !force) return
      if (this.loading) return this.loading
      this.loading = api.get('/me/menu')
        .then(({ data }) => {
          this.groups = data.groups || []
          this.loaded = true
          this.error = null
        })
        .catch(() => { this.error = 'โหลดเมนูไม่สำเร็จ' })
        .finally(() => { this.loading = null })
      return this.loading
    },
    reset() {
      this.groups = []
      this.loaded = false
    },
  },
})
