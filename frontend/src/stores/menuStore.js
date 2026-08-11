import { defineStore } from 'pinia';
import api from '../axios';

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
    async fetchUserMenus() {
      this.loading = true;
      try {
        const response = await api.get('/user/menus');
        if (response.data && response.data.success) {
          this.menus = response.data.data;
          this.loaded = true;
        }
      } catch (error) {
        console.error('Failed to fetch dynamic menus:', error);
        this.menus = [];
      } finally {
        this.loading = false;
      }
    },
    reset() {
      this.menus = [];
      this.loaded = false;
      this.loading = false;
    }
  }
});
