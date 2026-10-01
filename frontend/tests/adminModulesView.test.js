import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const post = vi.fn()

vi.mock('../src/axios', () => ({
  default: {
    get: (...args) => get(...args),
    post: (...args) => post(...args),
  },
}))

const AdminModulesView = (await import('../src/views/admin/AdminModulesView.vue')).default

/**
 * หน้า "โมดูลระบบ" — การลงทะเบียนโมดูลอย่างเดียวต้องทำให้การ์ดของโมดูลโผล่ครบ
 * (ชื่อ คำอธิบาย สถานะ สวิตช์เปิด-ปิด) โดยไม่ต้องแตะไฟล์หน้าเว็บ
 *
 * เดิมใช้โมดูลสารบรรณเป็นตัวอย่าง · ถอดสารบรรณแล้ว (2026-10-01) จึงใช้โมดูลงานล่วงเวลา
 * ซึ่งเป็นโมดูลธุรกิจที่ปิดได้เช่นกัน payload มีรูปร่างเดียวกับที่ `ModuleRegistry::describe()` คืนจริง
 */
const modulesPayload = [
  {
    code: 'system',
    config_key: 'admin',
    name_th: 'ระบบกลางและการบริหารระบบ',
    description: 'ระบบกลาง',
    enabled: true,
    can_disable: false,
    dependencies: [],
    disabled_dependencies: [],
  },
  {
    code: 'overtime',
    config_key: 'overtime',
    name_th: 'ระบบงานล่วงเวลา',
    name: 'ระบบงานล่วงเวลา',
    description: 'คำสั่งปฏิบัติงานนอกเวลา การลงเวลาจริง และการรับรองเวลา',
    enabled: true,
    can_disable: true,
    dependencies: ['system'],
    disabled_dependencies: [],
  },
]

describe('AdminModulesView — การ์ดโมดูลธุรกิจ', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    post.mockReset()
    get.mockResolvedValue({ data: { modules: modulesPayload } })
  })

  const mountView = async () => {
    const wrapper = mount(AdminModulesView)
    await flushPromises()

    return wrapper
  }

  it('แสดงชื่อและคำอธิบายของโมดูลด้วยการ์ดเดิมของหน้าจอ', async () => {
    const text = (await mountView()).text()

    expect(text).toContain('ระบบงานล่วงเวลา')
    expect(text).toContain('คำสั่งปฏิบัติงานนอกเวลา')
    expect(text).toContain('เปิดใช้งาน')
  })

  it('บอกว่าโมดูลต้องใช้ร่วมกับโมดูลระบบกลาง', async () => {
    expect((await mountView()).text()).toContain('ต้องใช้ร่วมกับ: system')
  })

  it('มีสวิตช์ปิดโมดูลธุรกิจได้ แต่โมดูลแกนไม่มี', async () => {
    const labels = (await mountView())
      .findAll('button[role="switch"]')
      .map((button) => button.attributes('aria-label'))

    expect(labels).toContain('เปิดหรือปิดระบบงานล่วงเวลา')
    expect(labels).not.toContain('เปิดหรือปิดระบบกลางและการบริหารระบบ')
  })

  /**
   * สวิตช์ต้องส่ง `config_key` ไม่ใช่ `code` — กลไกเปิด-ปิดผูกกับค่าตั้งค่า
   * `module_{configKey}_enabled` ถ้าส่งผิดคีย์ สวิตช์จะกดแล้วไม่มีอะไรเกิดขึ้น (บั๊กเดิมที่ CLEANUP-02 แก้)
   */
  it('กดสวิตช์แล้วส่ง config_key ไปยัง endpoint เดิม', async () => {
    post.mockResolvedValue({
      data: { message: 'ปิดโมดูลแล้ว', affected_dependants: [], modules: modulesPayload },
    })

    const wrapper = await mountView()

    await wrapper
      .findAll('button[role="switch"]')
      .find((button) => button.attributes('aria-label') === 'เปิดหรือปิดระบบงานล่วงเวลา')
      .trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/admin/modules', { module: 'overtime', enabled: false })
  })
})
