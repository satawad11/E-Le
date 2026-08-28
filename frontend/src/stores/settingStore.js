import { defineStore } from 'pinia';
import api from '../axios';

/**
 * ค่าตั้งค่าระบบที่หน้าเว็บใช้ร่วมกัน
 *
 * CLEANUP-02 — ถอด `enable_attendance_menu` / `enable_leave_menu` ออก
 *
 * สองค่านี้เคยใช้ซ่อนเมนูที่เขียนตายตัวใน `App.vue` เมื่อแถบเมนูเปลี่ยนไปอ่านจาก
 * `/api/user/menus` (ซึ่งกรองด้วย ModuleRegistry ฝั่ง server ให้แล้ว) ก็ไม่มีที่ใดอ่านอีก
 * การเปิด-ปิดโมดูลตั้งได้ที่หน้า "โมดูลระบบ" และมีผลกับทั้งเมนูและ API พร้อมกัน
 */
export const useSettingStore = defineStore('setting', {
  state: () => ({
    settings: {},
    loaded: false,
  }),
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
