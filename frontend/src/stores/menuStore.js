import { defineStore } from 'pinia';
import api from '../axios';

let fetchMenusPromise = null;

export const useMenuStore = defineStore('menu', {
  state: () => ({
    menus: [],
    loading: false,
    loaded: false,
  }),
  getters: {
    hasMenus: (state) => state.menus && state.menus.length > 0,
  },
  actions: {
    /**
     * SEC-025 — รวมคำขอที่วิ่งพร้อมกันให้เหลือใบเดียว และห้ามล้างเมนูทิ้งเมื่อโหลดไม่สำเร็จ
     *
     * เดิม `catch` ตั้ง `menus = []` แถบเมนูจึงหายไปทั้งแถบเมื่อเจอ 429
     * หรือระบบขัดข้องชั่วคราว ทั้งที่ผู้ใช้ยังเข้าสู่ระบบอยู่
     */
    async fetchUserMenus() {
      if (fetchMenusPromise) return fetchMenusPromise;

      this.loading = true;
      fetchMenusPromise = (async () => {
        try {
          const response = await api.get('/user/menus');
          if (response.data && response.data.success) {
            this.menus = response.data.data;
            this.loaded = true;
          }
        } catch (error) {
          console.error('Failed to fetch dynamic menus:', error);
        } finally {
          this.loading = false;
          fetchMenusPromise = null;
        }
      })();

      return fetchMenusPromise;
    },
    reset() {
      this.menus = [];
      this.loaded = false;
      this.loading = false;
    }
  }
});
