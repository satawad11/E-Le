import { defineStore } from 'pinia';
import api from '../axios';
import { useLeaveStore } from './leave';

let fetchUserPromise = null;

// Helper to extract flat string array of permission names regardless of whether permissions is array of objects or strings
const getPermissionNames = (state) => {
  if (!state.user) return [];
  const list = state.user.permission_names || state.user.permissions_list || state.user.permissions || [];
  if (!Array.isArray(list)) return [];
  return list.map(p => typeof p === 'string' ? p : p?.name).filter(Boolean);
};

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: localStorage.getItem('token') || null,
  }),
  getters: {
    isAuthenticated: (state) => !!state.token,

    // Role name helper (returns first role or empty)
    roleName: (state) => state.user?.role_names?.[0] || state.user?.role?.name || '',

    // Individual role checks for 7 Standard Roles
    isSuperAdmin: (state) => state.user?.role_names?.some(r => ['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'SuperAdmin'].includes(r)),
    isDirector:   (state) => state.user?.role_names?.some(r => ['ผู้อำนวยการ', 'Director'].includes(r)),
    isDeputy:     (state) => state.user?.role_names?.some(r => ['รองผู้อำนวยการ', 'Deputy Director', 'Deputy'].includes(r)),
    isDeptHead:   (state) => state.user?.role_names?.some(r => ['หัวหน้าแผนกวิชา / หัวหน้าฝ่าย', 'HeadOfDepartment'].includes(r)),
    isHRHead:     (state) => state.user?.role_names?.some(r => ['เจ้าหน้าที่บุคลากร (HR)', 'HR Head', 'HROfficer'].includes(r)),
    isSarabanOfficer: (state) => state.user?.role_names?.some(r => ['เจ้าหน้าที่สารบรรณกลาง', 'Saraban Officer', 'SarabanOfficer', 'Document Officer'].includes(r)),
    isEmployee:   (state) => state.user?.role_names?.some(r => ['บุคลากร / ครู / เจ้าหน้าที่', 'Employee'].includes(r)),

    // Can approve leaves (Director, Deputy, DeptHead, HRHead, SuperAdmin)
    isManager: (state) => {
      const perms = getPermissionNames(state);
      return state.user?.role_names?.some(r => [
        'ผู้ดูแลระบบสูงสุด', 'ผู้อำนวยการ', 'รองผู้อำนวยการ', 'หัวหน้าแผนกวิชา / หัวหน้าฝ่าย', 'เจ้าหน้าที่บุคลากร (HR)',
        'Super Admin', 'SuperAdmin', 'Director', 'Deputy Director', 'Deputy', 'HR Head', 'HRHead', 'HeadOfDepartment'
      ].includes(r)) || perms.includes('approve_leaves') || perms.includes('leave.approval.dept');
    },

    // Can access admin settings (SuperAdmin only)
    isAdmin: (state) => {
      return state.user?.role_names?.some(r => ['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'SuperAdmin'].includes(r));
    },

    // Can access user management (Admin and HR)
    isUserManager: (state) => {
      if (state.user?.role_names?.some(r => ['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'SuperAdmin', 'เจ้าหน้าที่บุคลากร (HR)', 'HR Head'].includes(r))) return true;
      const perms = getPermissionNames(state);
      return perms.includes('view_users') || perms.includes('edit_users') || perms.includes('system.user.manage');
    },

    // Check specific custom permission with alias resolution (SuperAdmin bypasses all)
    hasPermission: (state) => (permissionName) => {
      if (state.user?.role_names?.some(r => ['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'SuperAdmin'].includes(r))) return true;
      const userPerms = getPermissionNames(state);

      const aliases = {
        'view_all_attendances': ['view_all_attendances', 'attendance.view_all', 'attendance.report.view_all'],
        'attendance.view_all': ['view_all_attendances', 'attendance.view_all', 'attendance.report.view_all'],
        'attendance.checkin': ['attendance.checkin', 'attendance.record.create'],
        'attendance.view_self': ['attendance.view_self', 'attendance.record.view_my'],
        'view_users': ['view_users', 'system.user.view', 'system.user.manage'],
        'edit_users': ['edit_users', 'system.user.manage', 'admin.users.manage_permissions', 'system.role.manage', 'system.menu.manage'],
        'approve_leave': ['approve_leave', 'approve_leaves', 'leave.approval.dept', 'leave.approval.final', 'leave.management.manage', 'leave.hr_verify'],
        'manage_departments': ['manage_departments', 'system.setting.manage'],
        'manage_workflow_steps': ['manage_workflow_steps', 'saraban.config.manage', 'system.setting.manage'],
        'manage_leave_types': ['manage_leave_types', 'system.setting.manage'],
        'manage_settings': ['manage_settings', 'system.setting.manage', 'saraban.setting.manage'],
      };

      const matchKeys = aliases[permissionName] || [permissionName];
      return matchKeys.some(key => userPerms.includes(key));
    },

    // Check if user has ANY of the specified permissions
    hasAnyPermission: (state) => (permissionsArray = []) => {
      if (state.user?.role_names?.some(r => ['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'SuperAdmin'].includes(r))) return true;
      if (!Array.isArray(permissionsArray) || permissionsArray.length === 0) return false;
      const perms = getPermissionNames(state);
      return permissionsArray.some(p => perms.includes(p));
    },

    // Check if user has ALL of the specified permissions
    hasAllPermissions: (state) => (permissionsArray = []) => {
      if (state.user?.role_names?.some(r => ['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'SuperAdmin'].includes(r))) return true;
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
      if (state.user?.role_names?.some(r => ['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'SuperAdmin'].includes(r))) return true;
      const perms = getPermissionNames(state);
      return perms.some(p => ['attendance.checkin', 'attendance.record.create'].includes(p));
    },
    // ผู้ที่ดูประวัติการลงเวลาตนเองได้
    canViewSelfAttendance: (state) => {
      if (state.user?.role_names?.some(r => ['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'SuperAdmin'].includes(r))) return true;
      const perms = getPermissionNames(state);
      return perms.some(p => ['attendance.view_self', 'attendance.record.view_my'].includes(p));
    },
    // ดูรายงานการลงเวลาทั้งหมด (สำหรับ Admin/HR)
    canViewAllAttendance: (state) => {
      if (state.user?.role_names?.some(r => ['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'SuperAdmin'].includes(r))) return true;
      const perms = getPermissionNames(state);
      return perms.some(p => ['attendance.view_all', 'view_all_attendances', 'attendance.report.view_all'].includes(p));
    },
    // ดู/บันทึก OT
    canManageOT: (state) => {
      if (state.user?.role_names?.some(r => ['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'SuperAdmin'].includes(r))) return true;
      const perms = getPermissionNames(state);
      return perms.some(p => ['attendance.ot', 'attendance.ot_summary', 'attendance.summary.export'].includes(p));
    },
    // ใช้โมดูล attendance (ใช้แสดง/ซ่อนเมนูหลัก)
    hasAttendanceAccess: (state) => {
      if (state.user?.role_names?.some(r => ['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'SuperAdmin'].includes(r))) return true;
      const attendancePerms = [
        'attendance.checkin', 'attendance.view_self', 'attendance.view_all',
        'attendance.ot', 'attendance.ot_summary', 'attendance.export',
        'attendance.record.create', 'attendance.record.view_my', 'attendance.report.view_all', 'attendance.summary.export'
      ];
      const perms = getPermissionNames(state);
      return perms.some(p => attendancePerms.includes(p));
    },

    // Check if user has any admin menu permission
    hasAnyAdminMenu: (state) => {
      if (state.user?.role_names?.some(r => ['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'SuperAdmin', 'เจ้าหน้าที่บุคลากร (HR)'].includes(r))) return true;
      const adminPerms = ['view_users', 'edit_users', 'manage_departments', 'manage_leave_types', 'manage_settings', 'attendance.view_all', 'view_all_attendances', 'system.user.view', 'system.role.manage', 'system.menu.manage'];
      const perms = getPermissionNames(state);
      return perms.some(p => adminPerms.includes(p));
    },

    // Check if user has any settings menu permission
    hasAnySettingsMenu: (state) => {
      if (state.user?.role_names?.some(r => ['ผู้ดูแลระบบสูงสุด', 'Super Admin', 'SuperAdmin'].includes(r))) return true;
      const settingsPerms = ['manage_departments', 'manage_workflow_steps', 'manage_leave_types', 'manage_settings', 'system.setting.manage', 'system.menu.manage'];
      const perms = getPermissionNames(state);
      return perms.some(p => settingsPerms.includes(p));
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
    async fetchUser({ force = false } = {}) {
      if (!this.token) return;
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

      useLeaveStore().reset();

      import('./menuStore').then(({ useMenuStore }) => {
        useMenuStore().reset();
      }).catch(() => {});

      if (token) {
        api.post('/logout').catch(() => {});
      }
    }
  }
});
