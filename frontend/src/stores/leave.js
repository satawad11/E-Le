import { defineStore } from 'pinia';
import api from '../axios';

export const useLeaveStore = defineStore('leave', {
  state: () => ({
    leaves: [],
    leavesMeta: null,
    balances: [],
    types: [],
    approvals: [],
    /*
     * Guard กัน fetch ซ้ำซ้อน — เก็บ "promise ที่กำลังวิ่งอยู่" ไม่ใช่แค่ธง true/false
     *
     * ของเดิมเป็นธงและ `return` เปล่า ๆ ผู้เรียกที่ชนกันจึงได้ promise ที่ resolve ทันที
     * ทั้งที่ข้อมูลยังเป็นชุดเก่า — `await` แล้วอ่านค่าต่อได้ตัวเลขก่อนอัปเดต
     */
    _fetchingLeaves: null,
    _fetchingBalances: null,
  }),
  actions: {
    async fetchLeaves(params = {}) {
      if (this._fetchingLeaves) return this._fetchingLeaves;
      this._fetchingLeaves = this._doFetchLeaves(params);
      try {
        return await this._fetchingLeaves;
      } finally {
        this._fetchingLeaves = null;
      }
    },
    async _doFetchLeaves(params = {}) {
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
    },
    async fetchBalances(fiscalYear = null) {
      if (this._fetchingBalances) return this._fetchingBalances;
      this._fetchingBalances = this._doFetchBalances(fiscalYear);
      try {
        return await this._fetchingBalances;
      } finally {
        this._fetchingBalances = null;
      }
    },
    async _doFetchBalances(fiscalYear = null) {
      const params = {};
      if (fiscalYear) params.fiscal_year = fiscalYear;
      const res = await api.get('/leaves/balances', { params });
      this.balances = res.data;
    },
    /*
     * ทุกคำสั่งที่ทำให้ "วันลาคงเหลือ" เปลี่ยน ต้องดึงทั้งรายการใบลาและโควตาใหม่พร้อมกัน
     *
     * เดิมมีแต่ `cancelLeave` ที่ดึงโควตาใหม่ ที่เหลือ (ยื่นใบลา อนุมัติ ปฏิเสธ
     * และโดยเฉพาะ "อนุมัติคำขอยกเลิก") ดึงเฉพาะรายการใบลา ตัวเลขคงเหลือบนหน้าจอ
     * จึงค้างอยู่ที่ค่าเดิมจนกว่าจะรีเฟรชทั้งหน้า — เห็นเป็นว่าระบบไม่คืนวันลาให้
     */
    async _refreshAfterBalanceChange() {
      await Promise.all([this.fetchLeaves(), this.fetchBalances()]);
    },
    async fetchTypes() {
      // Cache: types ไม่ค่อยเปลี่ยน ไม่ต้อง fetch ซ้ำ
      if (this.types.length > 0) return;
      const res = await api.get('/leaves/types');
      this.types = res.data;
    },
    async submitLeave(payload) {
      const res = await api.post('/leaves', payload);
      await this._refreshAfterBalanceChange();
      return res.data;
    },
    async submitLeaveFormData(formData) {
      const res = await api.post('/leaves', formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
      });
      await this._refreshAfterBalanceChange();
      return res.data;
    },
    /*
     * แก้ร่างที่บันทึกไว้ — ใช้ multipart เหมือนตอนยื่น เพราะต้องรับไฟล์แนบและลายเซ็นได้
     * ร่างไม่กันโควตา จึงยังไม่ต้องดึงยอดวันลาใหม่ตรงนี้
     */
    async updateDraft(leaveId, formData) {
      const res = await api.post(`/leaves/${leaveId}/draft`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
      });
      await this.fetchLeaves();
      return res.data;
    },
    /** ส่งร่างเข้าสายอนุมัติ — ตรงนี้โควตาถูกกันจริง จึงต้องดึงยอดใหม่ด้วย */
    async submitDraft(leaveId) {
      const res = await api.post(`/leaves/${leaveId}/submit`);
      await this._refreshAfterBalanceChange();
      return res.data;
    },
    // fetchApprovals: ใช้ข้อมูลจาก leaves ที่มีอยู่ ไม่ยิง API ซ้ำ
    async fetchApprovals() {
      try {
        const res = await api.get('/approvals/pending');
        this.approvals = res.data;
      } catch (e) {
        console.error(e);
        throw e;
      }
    },
    async approveLeave(leaveId, comment = '', signature_base64 = null) {
      await api.post(`/approvals/${leaveId}/approve`, { comment, signature_base64 });
      await this._refreshAfterBalanceChange();
    },
    async rejectLeave(leaveId, comment = '') {
      await api.post(`/approvals/${leaveId}/reject`, { comment });
      await this._refreshAfterBalanceChange();
    },
    async cancelLeave(leaveId, reason = '') {
      if (reason) {
        await api.post(`/leaves/${leaveId}/cancel`, { cancellation_reason: reason });
      } else {
        await api.post(`/leaves/${leaveId}/cancel`);
      }
      await this._refreshAfterBalanceChange();
    },
    async approveCancellation(leaveId, comment = '', signature_base64 = null) {
      await api.post(`/leaves/${leaveId}/approve-cancellation`, { comment, signature_base64 });
      await this._refreshAfterBalanceChange();
    },
    async rejectCancellation(leaveId, comment = '') {
      await api.post(`/leaves/${leaveId}/reject-cancellation`, { comment });
      await this._refreshAfterBalanceChange();
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
      this._fetchingLeaves = null;
      this._fetchingBalances = null;
    },
  }
});
