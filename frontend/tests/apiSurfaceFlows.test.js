import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))
const access = vi.hoisted(() => ({ allowed: true }))
vi.mock('../src/axios', () => ({ default: api }))
vi.mock('../src/stores/auth', () => ({ useAuthStore: () => ({ hasPermission: () => access.allowed, hasAnyPermission: () => access.allowed }) }))
vi.mock('../src/utils/secureFile', () => ({ fetchObjectUrl: vi.fn(async () => 'blob:preview') }))
import ConfirmOperation from '../src/components/saraban/ConfirmOperation.vue'
import DocumentTools from '../src/components/saraban/DocumentTools.vue'
import RetentionPanel from '../src/components/saraban/RetentionPanel.vue'
import OperationsTools from '../src/components/saraban/OperationsTools.vue'
import SavedViews from '../src/components/saraban/SavedViews.vue'
const global = {
  stubs: { AppModal: { template: '<div><slot/><slot name="footer"/></div>' }, PdfWorkspace: true, RouterLink: { template: '<a><slot/></a>' } },
  directives: { can: { mounted(el) { if (!access.allowed) el.remove() } } },
}
const render = (component, props = {}) => mount(component, { props, global })
async function click(w, text) { const button = w.findAll('button').find(b => b.text() === text); expect(button, text).toBeTruthy(); await button.trigger('click'); await flushPromises() }
const doc = { id: 7, status: 'signed', finalized_at: null, versions: [{ id: 10, version_no: 1, pdf_file_path: 'saraban/one.pdf' }, { id: 20, version_no: 2, pdf_file_path: 'saraban/two.pdf' }] }
beforeEach(() => { for (const fn of Object.values(api)) fn.mockReset(); api.post.mockResolvedValue({ data: {} }); access.allowed = true; URL.revokeObjectURL = vi.fn() })

describe('ยืนยันการทำงานที่มีผลต่อเอกสาร', () => {
  it('ไม่ส่งคำขอก่อนยืนยัน และยืนยันรหัสผ่านใหม่เมื่อ server ตอบ 423', async () => {
    const action = vi.fn().mockRejectedValueOnce({ response: { status: 423, data: { message: 'ต้องยืนยันตัวตน' } } }).mockResolvedValueOnce({})
    const w = render(ConfirmOperation, { title: 'ออกฉบับ', action })
    expect(action).not.toHaveBeenCalled()
    await click(w, 'ยืนยันออกฉบับ')
    expect(w.find('input[type=password]').exists()).toBe(true)
    await w.find('input').setValue('test-password')
    await click(w, 'ยืนยันออกฉบับ')
    expect(api.post).toHaveBeenCalledWith('/auth/confirm-password', { password: 'test-password' })
    expect(action).toHaveBeenCalledTimes(2)
    expect(w.emitted('done')).toHaveLength(1)
    w.unmount()
  })
  it('รหัสผ่านไม่ผ่านต้องไม่ลอง mutation ซ้ำ', async () => {
    const action = vi.fn().mockRejectedValue({ response: { status: 423 } })
    const w = render(ConfirmOperation, { title: 'ทำลาย', action })
    await click(w, 'ยืนยันทำลาย')
    api.post.mockRejectedValue({ response: { status: 422, data: { message: 'รหัสผ่านไม่ถูกต้อง' } } })
    await w.find('input').setValue('wrong')
    await click(w, 'ยืนยันทำลาย')
    expect(action).toHaveBeenCalledTimes(1)
    expect(w.text()).toContain('รหัสผ่านไม่ถูกต้อง')
    expect(w.find('input').element.value).toBe('')
  })
})

describe('เครื่องมือรายฉบับ', () => {
  it('โหลดและแสดงหลักฐานลายเซ็น', async () => {
    api.get.mockResolvedValue({ data: { evidences: [{ id: 1, signer_name_snapshot: 'ผู้ลงนามทดสอบ', file_intact: true }] } })
    const w = render(DocumentTools, { document: doc })
    await click(w, 'ตรวจหลักฐานการลงนาม')
    expect(api.get).toHaveBeenCalledWith('/saraban/documents/7/signatures')
    expect(w.text()).toContain('ผู้ลงนามทดสอบ')
  })
  it('ออกฉบับสมบูรณ์หลังยืนยันเท่านั้น', async () => {
    const w = render(DocumentTools, { document: doc })
    await click(w, 'ออกฉบับสมบูรณ์พร้อมรหัสตรวจสอบ')
    expect(api.post).not.toHaveBeenCalled()
    await click(w, 'ยืนยันออกฉบับสมบูรณ์')
    expect(api.post).toHaveBeenCalledWith('/saraban/documents/7/finalize')
    expect(w.emitted('changed')).toHaveLength(1)
  })
  it('ร่างยังออกฉบับสมบูรณ์ไม่ได้', () => {
    const w = render(DocumentTools, { document: { ...doc, status: 'draft' } })
    expect(w.findAll('button').find(b => b.text() === 'ออกฉบับสมบูรณ์พร้อมรหัสตรวจสอบ').attributes('disabled')).toBeDefined()
  })
  it('ส่งข้อความเกษียนพร้อมตำแหน่งที่เลือก', async () => {
    const w = render(DocumentTools, { document: doc })
    await w.find('input:not([type])').setValue('โปรดพิจารณาดำเนินการ')
    await click(w, 'บันทึกเกษียน'); await click(w, 'ยืนยันบันทึกเกษียน')
    expect(api.post).toHaveBeenCalledWith('/saraban/documents/7/endorse', expect.objectContaining({ objects: [expect.objectContaining({ type: 'note', payload: { text: 'โปรดพิจารณาดำเนินการ' } })] }))
  })
  it('เปรียบเทียบด้วย id ของสองฉบับและแสดงผลจาก server', async () => {
    api.get.mockResolvedValue({ data: { identical_file: false, file: { left_pages: 1, right_pages: 2 } } })
    const w = render(DocumentTools, { document: doc })
    await w.findAll('select')[0].setValue(10); await w.findAll('select')[1].setValue(20)
    await click(w, 'เปรียบเทียบ')
    expect(api.get).toHaveBeenCalledWith('/saraban/documents/7/compare', { params: { left: 10, right: 20 } })
    expect(w.text()).toContain('ไฟล์ทั้งสองแตกต่างกัน')
  })
  it('สร้างสำเนาปกปิดจากฉบับที่เลือกพร้อมพื้นที่ ไม่ส่งข้อมูลวัตถุ UI', async () => {
    api.get.mockResolvedValue({ data: { available: true, redactions: [] } })
    const w = render(DocumentTools, { document: doc })
    const details = w.findAll('details').at(-1)
    details.element.open = true; await details.trigger('toggle'); await flushPromises()
    await w.findAll('select')[2].setValue(10); await flushPromises()
    await click(w, 'เพิ่มพื้นที่ปกปิด')
    await w.findAll('input:not([type])').at(-1).setValue('ปกปิดข้อมูลส่วนบุคคล')
    await click(w, 'สร้างสำเนาปกปิด'); await click(w, 'ยืนยันสร้างสำเนาปกปิด')
    expect(api.post).toHaveBeenCalledWith('/saraban/documents/7/redactions', { source_version_id: 10, reason: 'ปกปิดข้อมูลส่วนบุคคล', areas: [{ page: 1, x: 60, y: 100, width: 160, height: 30 }] })
    w.unmount()
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview')
  })
})

describe('การเก็บรักษา', () => {
  it('โหลดรายละเอียดและส่งคำขออายัดพร้อมเหตุผล', async () => {
    api.get.mockResolvedValue({ data: { holds: [], schedule: null, on_hold: false } })
    const w = render(RetentionPanel, { documentId: 7 })
    await click(w, 'ตรวจสอบรายการ')
    const text = w.findAll('input:not([type])')
    await text[0].setValue('อ้างอิง-01'); await text[1].setValue('รอตรวจสอบเอกสาร')
    await click(w, 'อายัดหนังสือ'); await click(w, 'ยืนยันอายัดหนังสือ')
    expect(api.post).toHaveBeenCalledWith('/saraban/documents/7/holds', { reference: 'อ้างอิง-01', reason: 'รอตรวจสอบเอกสาร' })
  })
  it('คิวครบกำหนดแยกอนุมัติกับทำลายและรอการยืนยัน', async () => {
    api.get.mockResolvedValue({ data: { due: [{ id: 9, status: 'approved', document: { id: 7, subject: 'หนังสือทดสอบ' } }] } })
    const w = render(RetentionPanel)
    await click(w, 'ตรวจสอบรายการ'); await click(w, 'ทำลายไฟล์ตามอนุมัติ')
    expect(api.post).not.toHaveBeenCalled()
    expect(w.text()).toContain('ย้อนกลับไม่ได้')
    await click(w, 'ยืนยันทำลายไฟล์')
    expect(api.post).toHaveBeenCalledWith('/saraban/retention/9/dispose')
  })
  it('หนังสือถูกอายัดไม่เปิดปุ่มทำลาย', async () => {
    api.get.mockResolvedValue({ data: { holds: [], on_hold: true, schedule: { id: 1, status: 'approved' } } })
    const w = render(RetentionPanel, { documentId: 7 }); await click(w, 'ตรวจสอบรายการ')
    expect(w.findAll('button').find(b => b.text() === 'ทำลายไฟล์ตามอนุมัติ').attributes('disabled')).toBeDefined()
  })
})

describe('ศูนย์ดูแลและมุมมองส่วนตัว', () => {
  it('แสดงผลส่งจริงและสั่งส่งรายการที่เลือกใหม่', async () => {
    api.get.mockImplementation(async url => url.includes('channel-health') ? { data: { channels: [{ channel: 'email', sent: 2, failed: 1, pending: 0 }] } } : { data: { data: [{ id: 4, recipient: 'ผู้รับทดสอบ', channel: 'email' }], meta: { current_page: 1, last_page: 1 } } })
    const w = render(OperationsTools)
    await click(w, 'ตรวจช่องทางและข้อความค้าง')
    expect(w.text()).toContain('ผู้รับทดสอบ')
    await click(w, 'ส่งข้อความนี้ใหม่'); await click(w, 'ยืนยันส่งข้อความใหม่')
    expect(api.post).toHaveBeenCalledWith('/admin/notifications/dead-letters/4/requeue')
  })
  it('ไม่มีสิทธิ์ต้องไม่มีเครื่องมือดูแลและไม่เรียก API', () => {
    access.allowed = false
    const w = render(OperationsTools)
    expect(w.findAll('details')).toHaveLength(0)
    expect(api.get).not.toHaveBeenCalled()
  })
  it('บันทึกและนำตัวกรองกลับมาใช้จริง', async () => {
    api.get.mockResolvedValue({ data: { views: [{ id: 2, name: 'หนังสือร่าง', filters: { status: 'draft' }, is_mine: true }] } })
    const w = render(SavedViews, { filters: { status: 'draft', direction: '' } })
    await w.find('input:not([type])').setValue('หนังสือร่าง')
    await click(w, 'บันทึกมุมมองใหม่')
    expect(api.post).toHaveBeenCalledWith('/saraban/saved-views', expect.objectContaining({ resource: 'documents', filters: { status: 'draft', direction: '' } }))
    await click(w, 'หนังสือร่าง')
    expect(w.emitted('apply')[0][0]).toEqual({ status: 'draft' })
    await click(w, 'ลบมุมมอง'); await click(w, 'ยืนยันลบมุมมอง')
    expect(api.delete).toHaveBeenCalledWith('/saraban/saved-views/2')
  })
})
