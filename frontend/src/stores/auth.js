import { defineStore } from 'pinia';
import api from '../axios';
import { useLeaveStore } from './leave';
import { useMenuStore } from './menuStore';
import { usePermissionStore } from './permissions';

let fetchUserPromise = null;

/**
 * รหัสอุปกรณ์สุ่ม (R-05 แจ้งเตือนอุปกรณ์ใหม่) — server เก็บเฉพาะ hash
 * "จดจำอุปกรณ์นี้" เก็บไว้ใน localStorage · ไม่ติ๊ก ใช้ sessionStorage (ปิดเบราว์เซอร์แล้วนับเป็นอุปกรณ์ใหม่)
 */
const deviceId = (remember) => {
  const make = () => (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2));
  try {
    let id = localStorage.getItem('device_id') || sessionStorage.getItem('device_id');
    if (!id) id = make();
    if (remember) {
      localStorage.setItem('device_id', id);
    } else if (!localStorage.getItem('device_id')) {
      sessionStorage.setItem('device_id', id);
    }
    return id;
  } catch (e) {
    return null;
  }
};

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

    /**
     * เจ้าหน้าที่สารบรรณ (เจ้าหน้าที่ทะเบียนรับ-ส่งหนังสือ)
     *
     * LEGACY-04 (ปิดงาน) · Decision 2 — เลิกเช็คชื่อ role แล้ว
     *
     * เดิมเทียบชื่อ role สี่ชื่อ ซึ่งมีเพียง `Document Officer` ที่มีอยู่จริง
     * และตอนนั้นบทบาทนั้นก็ไม่ได้ถือ permission `saraban.*` เลยสักตัว
     * หน้าเว็บจึงแสดงปุ่มที่ backend ปฏิเสธทุกครั้ง
     *
     * ตอนนี้ตัดสินจากสิทธิ์ "รับ/ส่งหนังสือ" ตัวเดียวกับที่ DocumentPolicy
     * ฝั่ง backend ใช้ — หน้าเว็บกับด่านจริงจึงพูดเรื่องเดียวกัน
     */
    isSarabanOfficer: (state) => {
      if (isSuperAdminUser(state)) return true;
      return getPermissionNames(state).includes('saraban.document.register');
    },

    /** ผู้มีสิทธิ์อนุมัติใบลา — เดิมคือ SuperAdmin/Director/Deputy/HR Head ซึ่งล้วนถือสิทธิ์นี้ */
    isHRHead: (state) => {
      const perms = getPermissionNames(state);
      return perms.includes('system.user.view') || perms.includes('view_users');
    },

    isManager: (state) => {
      const perms = getPermissionNames(state);
      return perms.includes('leave.approval.dept')
        || perms.includes('leave.approval.dept');
    },

    /** เข้าหน้าตั้งค่าระบบได้ — เดิมคือ Super Admin เท่านั้น */
    isAdmin: (state) => {
      const perms = getPermissionNames(state);
      return perms.includes('system.setting.manage') || perms.includes('manage_settings');
    },

    /** เข้าหน้าจัดการผู้ใช้ได้ — เดิมคือ Super Admin กับ HR Head */
    isUserManager: (state) => {
      const perms = getPermissionNames(state);
      return perms.includes('view_users')
        || perms.includes('system.user.view')
        || perms.includes('edit_users')
        || perms.includes('system.user.manage');
    },

    /**
     * ตรวจสิทธิ์รายตัว
     *
     * LEGACY-04 (04-8): ถอดตาราง alias 11 ชุดออกแล้ว
     *
     * ตารางนั้นมีไว้ชดเชยกรณีที่โค้ดเช็คชื่อ canonical แต่ผู้ใช้ถือชื่อรุ่นเก่า
     * ตอนนี้ 04-1 ทำให้ทุก role ถือ **ทั้งชื่อเก่าและชื่อ canonical** พร้อมกัน
     * การเทียบชื่อตรง ๆ จึงให้ผลเท่าเดิม (ตรวจแล้วกับชื่อทุกตัวที่หน้าเว็บใช้จริง)
     *
     * ยังคงให้ Super Admin ผ่านทุกข้อ เพื่อให้ตรงกับ Gate::before ฝั่ง backend
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

    // === Attendance Module Shortcuts ===
    // ผู้ที่สามารถลงเวลาเข้า-ออกได้ (check-in / check-out)
    canCheckIn: (state) => {
      if (isSuperAdminUser(state)) return true;
      const perms = getPermissionNames(state);
      return perms.some(p => ['attendance.checkin', 'attendance.record.create'].includes(p));
    },
    // ผู้ที่ดูประวัติการลงเวลาตนเองได้
    canViewSelfAttendance: (state) => {
      if (isSuperAdminUser(state)) return true;
      const perms = getPermissionNames(state);
      return perms.some(p => ['attendance.view_self', 'attendance.record.view_my'].includes(p));
    },
    // ดูรายงานการลงเวลาทั้งหมด (สำหรับ Admin/HR)
    canViewAllAttendance: (state) => {
      if (isSuperAdminUser(state)) return true;
      const perms = getPermissionNames(state);
      return perms.some(p => ['attendance.view_all'].includes(p));
    },
    // ดู/บันทึก OT
    canManageOT: (state) => {
      if (isSuperAdminUser(state)) return true;
      const perms = getPermissionNames(state);
      return perms.some(p => ['overtime.operation.execute', 'attendance.ot_summary'].includes(p));
    },
    // ใช้โมดูล attendance (ใช้แสดง/ซ่อนเมนูหลัก)
    hasAttendanceAccess: (state) => {
      if (isSuperAdminUser(state)) return true;
      const attendancePerms = [
        'attendance.checkin', 'attendance.view_self', 'attendance.view_all',
        'overtime.operation.execute', 'attendance.ot_summary',
        'attendance.record.create', 'attendance.record.view_my', 'attendance.report.view_all', 'attendance.summary.export',
        // คำสั่ง OT อยู่ใต้เมนูนี้ ผู้ที่มีสิทธิ์เฉพาะ OT จึงต้องเห็นกลุ่มเมนูด้วย
        'overtime.operation.execute', 'overtime.management.review'
      ];
      const perms = getPermissionNames(state);
      return perms.some(p => attendancePerms.includes(p));
    },

    // Check if user has any admin menu permission
    hasAnyAdminMenu: (state) => {
      if (isSuperAdminUser(state)) return true;
      const adminPerms = ['access.account.view', 'view_users', 'edit_users', 'manage_departments', 'manage_leave_types', 'manage_settings', 'attendance.view_all', 'system.user.view', 'system.menu.manage'];
      const perms = getPermissionNames(state);
      return perms.some(p => adminPerms.includes(p));
    },

    // สิทธิ์ตั้งค่าระบบทั่วไป รองรับทั้งชื่อเดิมและชื่อแบบโมดูล ให้ตรงกับ meta.permissions ใน router
    canManageSettings: (state) => {
      if (isSuperAdminUser(state)) return true;
      const perms = getPermissionNames(state);
      return perms.includes('manage_settings') || perms.includes('system.setting.manage');
    },

    /*
     * สิทธิ์เข้าศูนย์จัดการเอกสารและรายงาน
     *
     * รับทั้งสิทธิ์ชุดใหม่ (document.*) และสิทธิ์ตั้งค่าระบบเดิม เพื่อให้ผู้ดูแลที่ใช้งานอยู่
     * ไม่หลุดจากเมนูในวันที่อัปเกรด — ตรงกับที่ route ฝั่งเซิร์ฟเวอร์ยอมรับ
     */
    canManageDocumentCenter: (state) => {
      if (isSuperAdminUser(state)) return true;
      const documentPerms = ['document.template.view', 'document.template.manage', 'document.report.view', 'document.report.manage', 'manage_settings', 'system.setting.manage'];
      const perms = getPermissionNames(state);
      return perms.some(p => documentPerms.includes(p));
    },

    // Check if user has any settings menu permission
    hasAnySettingsMenu: (state) => {
      if (isSuperAdminUser(state)) return true;
      const settingsPerms = ['manage_departments', 'manage_workflow_steps', 'manage_leave_types', 'manage_settings', 'system.setting.manage'];
      const perms = getPermissionNames(state);
      return perms.some(p => settingsPerms.includes(p));
    },
  },
  actions: {
    /**
     * R-05 เข้าสู่ระบบ — ขั้นแรก (รหัสผ่าน)
     *
     * คืน { mfa: true, challenge, enrolled, reasons } เมื่อบัญชีต้องยืนยัน MFA (ยังไม่มี token)
     * หรือ { mfa: false } เมื่อเข้าสู่ระบบสำเร็จแล้ว
     */
    async login(identifier, password, { remember = false } = {}) {
      const response = await api.post('/login', {
        login: identifier,
        password,
        device_id: deviceId(remember),
        remember_device: remember,
      });
      if (response.data.mfa_required) {
        return { mfa: true, ...response.data };
      }
      this.setSession(response.data);
      return { mfa: false };
    },
    async verifyMfa(challenge, { code = null, recoveryCode = null } = {}) {
      const response = await api.post('/login/mfa', { challenge, code, recovery_code: recoveryCode });
      this.setSession(response.data);
      return response.data;
    },
    async startMfaSetup(challenge) {
      const response = await api.post('/login/mfa/setup', { challenge });
      return response.data;
    },
    /** ยืนยันการตั้งค่า MFA → ได้ token และรหัสสำรอง (แสดงครั้งเดียว) */
    async confirmMfaSetup(challenge, code) {
      const response = await api.post('/login/mfa/setup/confirm', { challenge, code });
      this.setSession(response.data);
      return response.data.recovery_codes || [];
    },
    setSession(data) {
      this.token = data.access_token;
      this.user = data.user;
      localStorage.setItem('token', this.token);
      this.tokenExpiresAt = Date.now() + (data.expires_in * 1000);
      localStorage.setItem('token_expires_at', String(this.tokenExpiresAt));
      // เริ่มเซสชันใหม่: สิทธิ์และเมนูต้องโหลดใหม่ของผู้ใช้คนนี้
      usePermissionStore().reset();
      useMenuStore().reset();
    },
    async fetchUser({ force = false } = {}) {
      if (!this.token) return;
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
          this.logout();
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

      // R-06: เมนูและสิทธิ์ของผู้ใช้คนก่อนต้องไม่ค้างให้ผู้ใช้คนถัดไปบนเครื่องเดียวกัน
      useMenuStore().reset();
      usePermissionStore().reset();

      if (token) {
        api.post('/logout').catch(() => {});
      }
    },
    async refreshToken() {
      const response = await api.post('/refresh-token');
      this.token = response.data.access_token;
      this.tokenExpiresAt = Date.now() + (response.data.expires_in * 1000);
      localStorage.setItem('token', this.token);
      localStorage.setItem('token_expires_at', String(this.tokenExpiresAt));
    }
  }
});
