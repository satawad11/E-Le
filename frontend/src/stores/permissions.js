import { defineStore } from 'pinia'
import api from '../axios'

const RANK = { own: 0, assigned: 1, unit: 1, subtree: 2, all: 3 }

/**
 * สิทธิ์ของผู้ใช้ปัจจุบันจาก GET /api/me/permissions (R-06)
 *
 * ใช้ซ่อน/แสดงปุ่ม แท็บ และ guard ของ route เท่านั้น — การตรวจจริงอยู่ที่ server ทุก endpoint (PermissionEngine)
 */
export const usePermissionStore = defineStore('permissions', {
  state: () => ({ grants: [], names: [], loaded: false, loading: null }),
  getters: {
    /** สิทธิ์ที่มีอย่างน้อยหนึ่ง scope (หรือ scope ≥ ที่ระบุ) */
    can: (state) => (name, minScope = null) => {
      if (!minScope) return state.names.includes(name)
      return state.grants.some((g) => g.permission === name && RANK[g.scope] >= RANK[minScope])
    },
    canAny() {
      return (names = [], minScope = null) => names.some((n) => this.can(n, minScope))
    },
    /** มีสิทธิ์นี้กับคนอื่น (scope กว้างกว่าตนเอง) */
    canBeyondOwn: (state) => (name) => state.grants.some((g) => g.permission === name && g.scope !== 'own'),
    scopesOf: (state) => (name) => [...new Set(state.grants.filter((g) => g.permission === name).map((g) => g.scope))],
  },
  actions: {
    async load(force = false) {
      if (this.loaded && !force) return
      if (this.loading) return this.loading
      this.loading = api.get('/me/permissions')
        .then(({ data }) => {
          this.grants = data.grants || []
          this.names = data.names || []
          this.loaded = true
        })
        .finally(() => { this.loading = null })
      return this.loading
    },
    reset() {
      this.grants = []
      this.names = []
      this.loaded = false
    },
  },
})
