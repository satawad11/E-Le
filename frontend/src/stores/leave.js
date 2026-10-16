import { defineStore } from 'pinia';
import api from '../axios';

export const useLeaveStore = defineStore('leave', {
  state: () => ({
    leaves: [],
    leavesMeta: null,
    balances: [],
    types: [],
    approvals: [],
    // Guard ป้องกัน concurrent fetch
    _fetchingLeaves: false,
    _fetchingBalances: false,
  }),
  actions: {
    async fetchLeaves(params = {}) {
      if (this._fetchingLeaves) return;
      this._fetchingLeaves = true;
      try {
        const res = await api.get('/leaves', { params });
        // รองรับทั้ง paginated response {data, current_page, ...} และ plain array
        if (Array.isArray(res.data)) {
          this.leaves = res.data;
          this.leavesMeta = null;
        } else {
          this.leaves = res.data.data ?? [];
          this.leavesMeta = {
            currentPage: res.data.current_page,
            lastPage: res.data.last_page,
            total: res.data.total,
            perPage: res.data.per_page,
          };
        }
      } finally {
        this._fetchingLeaves = false;
      }
    },
    async fetchBalances(fiscalYear = null) {
      if (this._fetchingBalances) return;
      this._fetchingBalances = true;
      try {
        const params = {};
        if (fiscalYear) params.fiscal_year = fiscalYear;
        const res = await api.get('/leaves/balances', { params });
        this.balances = res.data;
      } finally {
        this._fetchingBalances = false;
      }
    },
    async fetchTypes() {
      // Cache: types ไม่ค่อยเปลี่ยน ไม่ต้อง fetch ซ้ำ
      if (this.types.length > 0) return;
      const res = await api.get('/leaves/types');
      this.types = res.data;
    },
    async submitLeave(payload) {
      const res = await api.post('/leaves', payload);
      await this.fetchLeaves();
      return res.data;
    },
    async submitLeaveFormData(formData) {
      const res = await api.post('/leaves', formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
      });
      await this.fetchLeaves();
      return res.data;
    },
    // fetchApprovals: ใช้ข้อมูลจาก leaves ที่มีอยู่ ไม่ยิง API ซ้ำ
    // ส่ง currentUserId มาจาก caller เพื่อกรอง
    async fetchApprovals(currentUserId) {
      try {
        const res = await api.get('/approvals/pending');
        this.approvals = res.data;
      } catch (e) {
        console.error(e);
        throw e;
      }
    },
    // onBehalfOf: ผู้มีอำนาจตัวจริงเมื่อผู้ดูแลบันทึกแทนตามเอกสารที่ลงนามแล้ว (DELEGATION-01)
    async approveLeave(leaveId, comment = '', signature_base64 = null, onBehalfOf = null) {
      await api.post(`/approvals/${leaveId}/approve`, { comment, signature_base64, on_behalf_of: onBehalfOf || undefined });
      await this.fetchLeaves();
    },
    async rejectLeave(leaveId, comment = '', onBehalfOf = null) {
      await api.post(`/approvals/${leaveId}/reject`, { comment, on_behalf_of: onBehalfOf || undefined });
      await this.fetchLeaves();
    },
    async cancelLeave(leaveId, reason = '') {
      if (reason) {
        await api.post(`/leaves/${leaveId}/cancel`, { cancellation_reason: reason });
      } else {
        await api.post(`/leaves/${leaveId}/cancel`);
      }
      await Promise.all([this.fetchLeaves(), this.fetchBalances()]);
    },
    async approveCancellation(leaveId, comment = '', signature_base64 = null, onBehalfOf = null) {
      await api.post(`/leaves/${leaveId}/approve-cancellation`, { comment, signature_base64, on_behalf_of: onBehalfOf || undefined });
      await this.fetchLeaves();
    },
    async rejectCancellation(leaveId, comment = '', onBehalfOf = null) {
      await api.post(`/leaves/${leaveId}/reject-cancellation`, { comment, on_behalf_of: onBehalfOf || undefined });
      await this.fetchLeaves();
    },
    async hrVerify(leaveId) {
      await api.post(`/leaves/${leaveId}/hr-verify`);
      await this.fetchLeaves();
    },
    async acceptSubstitute(leaveId) {
      await api.post(`/leaves/${leaveId}/substitute-accept`);
      await this.fetchLeaves();
    },
    async rejectSubstitute(leaveId) {
      await api.post(`/leaves/${leaveId}/substitute-reject`);
      await this.fetchLeaves();
    },
    // เรียกตอน logout เพื่อ clear state
    reset() {
      this.leaves = [];
      this.leavesMeta = null;
      this.balances = [];
      this.types = [];
      this.approvals = [];
      this._fetchingLeaves = false;
      this._fetchingBalances = false;
    },
  }
});
