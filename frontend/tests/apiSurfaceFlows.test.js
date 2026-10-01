import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))
const access = vi.hoisted(() => ({ allowed: true }))
vi.mock('../src/axios', () => ({ default: api }))
vi.mock('../src/stores/auth', () => ({ useAuthStore: () => ({ hasPermission: () => access.allowed, hasAnyPermission: () => access.allowed }) }))
vi.mock('../src/utils/secureFile', () => ({ fetchObjectUrl: vi.fn(async () => 'blob:preview') }))
const push = vi.hoisted(() => vi.fn())
vi.mock('vue-router', () => ({ useRouter: () => ({ push }), useRoute: () => ({ params: {} }) }))
import ConfirmOperation from '../src/components/ui/ConfirmOperation.vue'
import OperationsTools from '../src/components/admin/OperationsTools.vue'
import { fetchObjectUrl } from '../src/utils/secureFile'
const global = {
  stubs: { AppModal: { template: '<div><slot/><slot name="footer"/></div>' }, PdfWorkspace: true, RouterLink: { template: '<a><slot/></a>' } },
  directives: { can: { mounted(el) { if (!access.allowed) el.remove() } } },
}
const render = (component, props = {}) => mount(component, { props, global })
async function click(w, text) { const button = w.findAll('button').find(b => b.text() === text); expect(button, text).toBeTruthy(); await button.trigger('click'); await flushPromises() }
beforeEach(() => { for (const fn of Object.values(api)) fn.mockReset(); push.mockReset(); fetchObjectUrl.mockReset().mockResolvedValue('blob:preview'); api.post.mockResolvedValue({ data: {} }); access.allowed = true; URL.revokeObjectURL = vi.fn() })

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
})
