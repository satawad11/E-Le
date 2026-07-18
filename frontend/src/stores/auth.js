import { defineStore } from 'pinia';
import api from '../axios';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: localStorage.getItem('token') || null,
  }),
  getters: {
    isAuthenticated: (state) => !!state.token,

    // Role name helper (returns first role or empty)
    roleName: (state) => state.user?.role_names?.[0] || state.user?.role?.name || '',

    // Individual role checks
    isSuperAdmin: (state) => state.user?.role_names?.includes('Super Admin') || state.user?.role_names?.includes('SuperAdmin'),
    isDirector:   (state) => state.user?.role_names?.includes('Director'),
    isDeputy:     (state) => state.user?.role_names?.includes('Deputy Director') || state.user?.role_names?.includes('Deputy'),
    isHRHead:     (state) => state.user?.role_names?.includes('HR Head') || state.user?.role_names?.includes('HRHead'),
    isEmployee:   (state) => state.user?.role_names?.includes('Employee'),
    isSarabanOfficer: (state) => state.user?.role_names?.includes('Saraban Officer') || state.user?.role_names?.includes('เจ้าหน้าที่สารบัญ'),

    // Can approve leaves (Director, Deputy, HRHead, SuperAdmin)
    isManager: (state) => {
      return state.user?.role_names?.some(r => [
        'Super Admin', 'SuperAdmin', 'Director', 'Deputy Director', 'Deputy', 'HR Head', 'HRHead'
      ].includes(r));
    },

    // Can access admin settings (SuperAdmin only)
    isAdmin: (state) => {
      return state.user?.role_names?.includes('Super Admin') || state.user?.role_names?.includes('SuperAdmin');
    },

    // Can access user management (Admin and HR)
    isUserManager: (state) => {
      return state.user?.permissions?.includes('view_users') || state.user?.permissions?.includes('edit_users');
    },

    // Check specific custom permission
    hasPermission: (state) => (permissionName) => {
      return state.user?.permissions?.includes(permissionName) || false;
    },

    // Check if user has any admin menu permission
    hasAnyAdminMenu: (state) => {
      if (state.user?.role_names?.includes('Super Admin') || state.user?.role_names?.includes('SuperAdmin')) return true;
      const adminPerms = ['view_users', 'edit_users', 'manage_departments', 'manage_leave_types', 'manage_settings', 'view_all_attendances'];
      return state.user?.permissions?.some(p => adminPerms.includes(p));
    },

    // Check if user has any settings menu permission
    hasAnySettingsMenu: (state) => {
      if (state.user?.role_names?.includes('Super Admin') || state.user?.role_names?.includes('SuperAdmin')) return true;
      const settingsPerms = ['manage_departments', 'manage_workflow_steps', 'manage_leave_types', 'manage_settings'];
      return state.user?.permissions?.some(p => settingsPerms.includes(p));
    },
  },
  actions: {
    async login(email, password) {
      try {
        const response = await api.post('/login', { email, password });
        this.token = response.data.access_token;
        this.user = response.data.user;
        localStorage.setItem('token', this.token);
        return true;
      } catch (error) {
        console.error('Login failed', error);
        throw error;
      }
    },
    async fetchUser() {
      if (!this.token) return;
      try {
        const response = await api.get('/user');
        this.user = response.data;
      } catch (error) {
        this.logout();
      }
    },
    logout() {
      // clear state ทันที ไม่รอ API
      const token = this.token;
      this.user = null;
      this.token = null;
      localStorage.removeItem('token');

      // clear leave data เพื่อไม่ให้ข้อมูลเก่าค้าง (dynamic import ป้องกัน circular dep)
      import('./leave').then(({ useLeaveStore }) => {
        useLeaveStore().reset();
      }).catch(() => {});

      // revoke token ใน background (ไม่ต้อง await)
      if (token) {
        api.post('/logout').catch(() => {/* ไม่สนใจ error */});
      }
    }
  }
});

