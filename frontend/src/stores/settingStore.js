import { defineStore } from 'pinia';
import api from '../axios';

export const useSettingStore = defineStore('setting', {
  state: () => ({
    settings: {
      enable_attendance_menu: '1',
      enable_leave_menu: '1',
    },
    loaded: false,
  }),
  getters: {
    isAttendanceEnabled: (state) => state.settings.enable_attendance_menu !== '0',
    isLeaveEnabled: (state) => state.settings.enable_leave_menu !== '0',
  },
  actions: {
    async fetchSettings() {
      try {
        const res = await api.get('/settings');
        if (res.data) {
          this.settings = { ...this.settings, ...res.data };
          this.loaded = true;
        }
      } catch (e) {
        console.error('Failed to fetch settings:', e);
      }
    },
    async updateSetting(key, value) {
      try {
        await api.post('/admin/settings', { [key]: value });
        this.settings[key] = value;
        return true;
      } catch (e) {
        console.error(`Failed to update setting ${key}:`, e);
        throw e;
      }
    }
  }
});
