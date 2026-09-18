import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()

vi.mock('../src/axios', () => ({
  default: {
    get: (...args) => get(...args),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
  useRoute: () => ({ query: {}, params: {} }),
}))

vi.mock('../src/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 1, name: 'ทดสอบ ระบบ', profile: {} } }),
}))

vi.mock('../src/stores/alert', () => ({
  useAlertStore: () => ({ success: vi.fn(), error: vi.fn() }),
}))

/*
 * ประเภทการลาและสิทธิที่หน้าจอใช้ — นโยบายแนบมากับสิทธิเสมอ (`/leaves/balances`)
 * ซึ่งเป็นที่มาของหลักเกณฑ์ที่หน้าจอต้องบอกผู้ใช้ก่อนกรอก
 */
const leaveTypes = [
  { id: 1, name: 'ลาป่วย', extra_fields: [] },
  { id: 2, name: 'ลาอุปสมบท', extra_fields: [] },
]

const balances = [
  {
    leave_type_id: 1, type: { id: 1, name: 'ลาป่วย' }, year: 2026,
    quota: 60, used: 0, reserved: 0, carried_forward: 0, pending: 0,
    paid_quota: null, paid_remaining: null, unlimited: false,
    policy: {
      attachment_required_after_days: 3,
      required_evidence_type: 'medical_certificate',
      max_days_per_request: 30,
      notice_period_days: 0,
      allow_half_day: true,
      allow_past_dates: true,
    },
  },
  {
    leave_type_id: 2, type: { id: 2, name: 'ลาอุปสมบท' }, year: 2026,
    quota: 120, used: 0, reserved: 0, carried_forward: 0, pending: 0,
    paid_quota: null, paid_remaining: null, unlimited: false,
    policy: {
      attachment_required_after_days: null,
      required_evidence_type: null,
      max_days_per_request: null,
      notice_period_days: 60,
      allow_half_day: false,
      allow_past_dates: false,
    },
  },
]

vi.mock('../src/stores/leave', () => ({
  useLeaveStore: () => ({
    types: leaveTypes,
    balances,
    fetchTypes: vi.fn(),
    fetchBalances: vi.fn(),
    fetchLeaves: vi.fn(),
  }),
}))

const mountRequestView = async () => {
  const { default: LeaveRequestView } = await import('../src/views/LeaveRequestView.vue')

  const wrapper = mount(LeaveRequestView, {
    global: {
      stubs: {
        ThaiDatePicker: { template: '<input class="thai-date" />', props: ['modelValue', 'minDate', 'disabled', 'id'] },
      },
      // directive ที่ลงทะเบียนไว้ระดับแอป — ใส่ตัวเปล่าไว้ให้ log ของเทสต์สะอาด
      directives: { 'secure-src': {} },
    },
  })

  await flushPromises()

  return wrapper
}

describe('หน้ายื่นใบลา', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    get.mockImplementation((url) => {
      if (url === '/users/list') return Promise.resolve({ data: [] })

      return Promise.resolve({ data: [] })
    })
  })

  /*
   * ด่านที่ขาดไปจริง ๆ (พบ 2026-09-10)
   *
   * `watch` ที่ลงทะเบียนก่อนบรรทัดสร้าง `form` อ่านค่าทันทีเพื่อเก็บ dependency
   * จึงชน temporal dead zone แล้ว **ทั้งหน้ากลายเป็นว่างเปล่า** โดย lint, typecheck
   * และเทสต์ชุดเดิมผ่านหมด เพราะไม่มีเทสต์ใด mount หน้านี้เลย
   */
  it('เรนเดอร์ฟอร์มได้จริงโดยไม่ล้มตอน setup', async () => {
    const wrapper = await mountRequestView()

    expect(wrapper.find('#leave-type').exists()).toBe(true)
    expect(wrapper.find('form').exists()).toBe(true)
    expect(wrapper.text()).toContain('ยื่นใบลา')
  })

  it('บอกหลักเกณฑ์ของนโยบายก่อนผู้ใช้กรอก', async () => {
    const wrapper = await mountRequestView()

    await wrapper.find('#leave-type').setValue(1)
    await flushPromises()

    // ชื่อหลักฐานต้องเป็นภาษาไทย ไม่ใช่รหัสดิบอย่าง medical_certificate
    expect(wrapper.text()).toContain('ต้องแนบใบรับรองแพทย์เมื่อลาตั้งแต่ 3 วันขึ้นไป')
    expect(wrapper.text()).toContain('ลาได้ครั้งละไม่เกิน 30 วัน')
    expect(wrapper.text()).not.toContain('medical_certificate')
  })

  it('ซ่อนตัวเลือกครึ่งวันเมื่อนโยบายของประเภทนั้นไม่อนุญาต', async () => {
    const wrapper = await mountRequestView()

    await wrapper.find('#leave-type').setValue(1)
    await flushPromises()
    expect(wrapper.text()).toContain('ครึ่งวันเช้า')

    await wrapper.find('#leave-type').setValue(2)
    await flushPromises()

    expect(wrapper.text()).not.toContain('ครึ่งวันเช้า')
    expect(wrapper.text()).toContain('ประเภทนี้ลาครึ่งวันไม่ได้')
    expect(wrapper.text()).toContain('ต้องยื่นล่วงหน้าอย่างน้อย 60 วัน')
  })

  it('แสดงแถบเตือนเกณฑ์วันลาประเมิน ก.ค.ศ. (เพดาน ๒๓ วัน)', async () => {
    const wrapper = await mountRequestView()

    expect(wrapper.text()).toContain('เกณฑ์วันลาประเมิน ก.ค.ศ.')
    expect(wrapper.text()).toContain('๒๓ วันทำการ')
  })
})
