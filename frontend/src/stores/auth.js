import { defineStore } from 'pinia';
import api, { isAuthenticationExpired } from '../axios';
import { useLeaveStore } from './leave';

let fetchUserPromise = null;
let refreshTokenPromise = null;

// Helper to extract flat string array of permission names regardless of whether permissions is array of objects or strings
const getPermissionNames = (state) => {
  if (!state.user) return [];
  const list = state.user.permission_names || state.user.permissions_list || state.user.permissions || [];
  if (!Array.isArray(list)) return [];
  return list.map(p => typeof p === 'string' ? p : p?.name).filter(Boolean);
};

/**
 * ผู้ดูแลระบบสูงสุด — จุดเดียวในไฟล์นี้ที่ยังตัดสินจากชื่อ role
 *
 * ตรงกับ Gate::before ฝั่ง backend ที่ให้ Super Admin ผ่านทุก ability
 * เก็บไว้เป็น bootstrap identity เพราะถ้าผูกกับ permission จะเกิดปัญหา
 * ไก่กับไข่ตอนตั้งค่าระบบครั้งแรก
 */
const isSuperAdminUser = (state) =>
  Boolean(state.user?.role_names?.some(r => ['Super Admin', 'SuperAdmin'].includes(r)));

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: localStorage.getItem('token') || null,
    tokenExpiresAt: Number(localStorage.getItem('token_expires_at')) || null,
  }),
  getters: {
    isAuthenticated: (state) => !!state.token,

    // Personnel Domain helpers (with legacy fallback)
    personnel: (state) => state.user?.personnel || null,
    currentAssignment: (state) => state.user?.current_assignment || null,
    personnelCode: (state) => state.user?.personnel?.personnel_code || state.user?.personnel_code || state.user?.profile?.personnel_code || '',
    displayName: (state) => state.user?.personnel?.full_name || state.user?.full_name_with_title || state.user?.name || '',
    positionName: (state) => state.user?.current_assignment?.position || state.user?.position || state.user?.profile?.position || '',
    orgUnitName: (state) => state.user?.current_assignment?.org_unit?.name || state.user?.org_unit?.name || state.user?.department?.name || '',

    // Role name helper (returns first role or empty)
    roleName: (state) => state.user?.role_names?.[0] || state.user?.role?.name || '',

    /*
     * LEGACY-04 (04-8) — เลิกตัดสินสิทธิ์จาก "ชื่อ role"
     *
     * ชื่อ role ในระบบนี้ผู้ดูแลแก้ได้จากหน้าจอ และที่ผ่านมาชื่อในโค้ดกับชื่อที่มีจริง
     * ในฐานข้อมูลก็ไม่ตรงกันอยู่แล้ว (เช่น `หัวหน้าแผนกวิชา / หัวหน้าฝ่าย` ไม่มีอยู่จริง)
     * ตัวตัดสินจึงเปลี่ยนมาใช้ permission ที่ backend ส่งมาแทน
     *
     * หมายเหตุ: ฝั่งหน้าเว็บเป็นเพียง UX ไม่ใช่การควบคุมสิทธิ์ — backend บังคับเสมอ
     *
     * ถอด getter ที่ไม่มีใครเรียกออกแล้ว: isDirector, isDeputy, isDeptHead, isEmployee
     */

    /**
     * ผู้ดูแลระบบสูงสุด — คงการเช็คชื่อ role ไว้จุดเดียวโดยตั้งใจ
     * เพราะเป็น bootstrap identity เหมือนฝั่ง backend (Gate::before)
     * ถ้าผูกกับ permission จะเกิดปัญหาไก่กับไข่ตอนตั้งค่าระบบครั้งแรก
     */
    isSuperAdmin: (state) => isSuperAdminUser(state),


    /** ผู้มีสิทธิ์ตรวจสอบใบลาในฐานะงานบุคลากร */
    isHRHead: (state) => {
      const perms = getPermissionNames(state);
      return perms.includes('system.user.view');
    },

    isManager: (state) => {
      const perms = getPermissionNames(state);
      return perms.includes('leave.approval.dept') || perms.includes('leave.approve');
    },

    /** เข้าหน้าตั้งค่าระบบได้ */
    isAdmin: (state) => getPermissionNames(state).includes('system.setting.manage'),

    /**
     * ตรวจสิทธิ์รายตัว
     *
     * CLEANUP-02 (D5) — เทียบ "ชื่อมาตรฐาน" ชื่อเดียวพอ
     *
     * `/api/user` ส่ง `permission_names` ที่รวมชื่อที่เทียบเท่ากันมาให้แล้ว
     * (ผู้ที่ถือ `manage_settings` จะได้ `system.setting.manage` ติดมาด้วย)
     * หน้าเว็บจึงไม่ต้องเขียนชื่อชุดเดิมคู่กับชุดมาตรฐานทุกจุดอีกต่อไป
     *
     * หมายเหตุ: ฝั่งหน้าเว็บเป็นเพียง UX ไม่ใช่การควบคุมสิทธิ์ — backend บังคับเสมอ
     */
    hasPermission: (state) => (permissionName) => {
      if (isSuperAdminUser(state)) return true;

      return getPermissionNames(state).includes(permissionName);
    },

    // Check if user has ANY of the specified permissions
    hasAnyPermission: (state) => (permissionsArray = []) => {
      if (isSuperAdminUser(state)) return true;
      if (!Array.isArray(permissionsArray) || permissionsArray.length === 0) return false;
      const perms = getPermissionNames(state);
      return permissionsArray.some(p => perms.includes(p));
    },

    // Check if user has ALL of the specified permissions
    hasAllPermissions: (state) => (permissionsArray = []) => {
      if (isSuperAdminUser(state)) return true;
      if (!Array.isArray(permissionsArray) || permissionsArray.length === 0) return false;
      const perms = getPermissionNames(state);
      return permissionsArray.every(p => perms.includes(p));
    },

    // Check if permission is a Direct User Permission (granted specifically to user)
    isDirectPermission: (state) => (permissionName) => {
      const direct = state.user?.direct_permissions || state.user?.direct_permission_names || [];
      return direct.includes(permissionName);
    },

    /*
     * CLEANUP-02 — ถอด getter ที่เหลือไว้ช่วยแถบเมนูที่เขียนตายตัวออกทั้งหมด
     *
     * `canCheckIn`, `canViewSelfAttendance`, `canViewAllAttendance`, `canManageOT`,
     * `hasAttendanceAccess`, `hasAnyAdminMenu`, `canManageSettings`,
     * `canManageDocumentCenter`, `hasAnySettingsMenu`, `isUserManager`
     * ทั้งหมดมีไว้ตัดสินว่าจะแสดงเมนูใดใน `App.vue` เมื่อแถบเมนูเปลี่ยนไปอ่านจาก
     * `/api/user/menus` (ซึ่งกรองด้วยสิทธิ์ฝั่ง server ให้แล้ว) ก็ไม่มีที่ใดเรียกอีกเลย
     */
  },
  actions: {
    async login(email, password) {
      try {
        const response = await api.post('/login', { email, password });
        this.token = response.data.access_token;
        this.user = response.data.user;
        localStorage.setItem('token', this.token);
        this.tokenExpiresAt = Date.now() + (response.data.expires_in * 1000);
        localStorage.setItem('token_expires_at', String(this.tokenExpiresAt));
        return true;
      } catch (error) {
        console.error('Login failed', error);
        throw error;
      }
    },
    /**
     * โหลดข้อมูลผู้ใช้ปัจจุบัน
     *
     * SEC-025 — เดิม `catch` ตัวนี้เรียก `logout()` กับความล้มเหลวทุกชนิด
     * ผู้ใช้จึงถูกเตะออกจากระบบเมื่อเจอ 429 (คำขอถี่) หรือ 5xx (ระบบขัดข้องชั่วคราว)
     * ทั้งที่โทเคนยังใช้ได้ · ตอนนี้ล้างเซสชันเฉพาะเมื่อ server ตอบ 401 เท่านั้น
     * กรณีอื่นคืน `null` และคงเซสชันไว้ให้หน้าถัดไปลองใหม่ได้
     */
    async fetchUser({ force = false } = {}) {
      if (!this.token) return null;
      if (this.tokenExpiresAt && this.tokenExpiresAt - Date.now() < 30 * 60 * 1000) {
        await this.refreshToken();
      }
      if (this.user && !force) return this.user;
      if (fetchUserPromise) return fetchUserPromise;

      fetchUserPromise = (async () => {
        try {
          const response = await api.get('/user');
          this.user = response.data;
          return this.user;
        } catch (error) {
          if (isAuthenticationExpired(error)) {
            this.logout();
          }
          return null;
        } finally {
          fetchUserPromise = null;
        }
      })();

      return fetchUserPromise;
    },
    logout() {
      const token = this.token;
      this.user = null;
      this.token = null;
      localStorage.removeItem('token');
      localStorage.removeItem('token_expires_at');
      this.tokenExpiresAt = null;

      useLeaveStore().reset();

      import('./menuStore').then(({ useMenuStore }) => {
        useMenuStore().reset();
      }).catch(() => {});

      if (token) {
        api.post('/logout').catch(() => {});
      }
    },
    /**
     * ต่ออายุโทเคน
     *
     * SEC-025 — รวมคำขอที่วิ่งพร้อมกันให้เหลือใบเดียว (การสลับหน้าเร็ว ๆ เคยยิงซ้ำ
     * จนชนเพดาน `token-refresh` แล้วได้ 429) และกลืนความล้มเหลวชั่วคราวทิ้ง
     * โทเคนใบเดิมยังไม่หมดอายุ ณ จุดที่เรียก จึงใช้ต่อได้จนกว่าจะต่ออายุสำเร็จ
     * ถ้าโทเคนหมดอายุจริง คำขอถัดไปจะได้ 401 แล้วเส้นทาง logout ปกติทำงานเอง
     */
    async refreshToken() {
      if (refreshTokenPromise) return refreshTokenPromise;

      refreshTokenPromise = (async () => {
        try {
          const response = await api.post('/refresh-token');
          this.adoptToken(response.data.access_token, response.data.expires_in);
        } catch (error) {
          if (isAuthenticationExpired(error)) {
            this.logout();
          }
        } finally {
          refreshTokenPromise = null;
        }
      })();

      return refreshTokenPromise;
    },

    /** รับโทเคนใบใหม่มาใช้ต่อ (ต่ออายุ หรือหลังเปลี่ยนรหัสผ่านตาม SEC-024) */
    adoptToken(accessToken, expiresInSeconds) {
      if (!accessToken) return;
      this.token = accessToken;
      this.tokenExpiresAt = Date.now() + (Number(expiresInSeconds) || 0) * 1000;
      localStorage.setItem('token', this.token);
      localStorage.setItem('token_expires_at', String(this.tokenExpiresAt));
    }
  }
});
