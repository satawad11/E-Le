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
 * INT-02 — โมดูลสารบรรณต้องแสดงในหน้า "โมดูลระบบ" ของระบบเดิม โดยไม่มี UI ใหม่
 *
 * `SARABAN-V1.2-EXISTING-SYSTEM-MODULE-MASTER.md` §2 บังคับว่า SARABAN ต้องใช้
 * App Shell / Theme / Component ของระบบเดิม ห้ามสร้างหน้าจอหรือชุด UI ของตัวเอง
 *
 * เทสต์นี้คือ UI QA ของ INT-02: ยืนยันว่าการลงทะเบียนโมดูลอย่างเดียว ทำให้การ์ด
 * ของสารบรรณโผล่ในหน้าจอเดิมได้ครบทุกส่วน (ชื่อ คำอธิบาย สถานะ สวิตช์เปิด-ปิด)
 * โดยไม่ต้องแตะไฟล์หน้าเว็บเลยแม้แต่บรรทัดเดียว
 *
 * payload ที่ป้อนเข้าไปมีรูปร่างเดียวกับที่ `ModuleRegistry::describe()` คืนจริง
 * ซึ่ง `SarabanModuleRegistrationTest` ฝั่ง backend ตรึงไว้อีกชั้นหนึ่ง
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
    code: 'saraban',
    config_key: 'saraban',
    name_th: 'ระบบงานสารบรรณอิเล็กทรอนิกส์',
    name: 'ระบบงานสารบรรณอิเล็กทรอนิกส์',
    description:
      'ทะเบียนหนังสือรับ-ส่ง หนังสือภายใน การเดินเรื่อง เกษียนหนังสือ ลงนาม และกระจายหนังสือ',
    enabled: true,
    can_disable: true,
    dependencies: ['system'],
    disabled_dependencies: [],
  },
]

describe('AdminModulesView — การ์ดโมดูลสารบรรณ', () => {
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

  it('แสดงชื่อและคำอธิบายของโมดูลสารบรรณด้วยการ์ดเดิมของหน้าจอ', async () => {
    const wrapper = await mountView()
    const text = wrapper.text()

    expect(text).toContain('ระบบงานสารบรรณอิเล็กทรอนิกส์')
    expect(text).toContain('ทะเบียนหนังสือรับ-ส่ง')
    expect(text).toContain('เปิดใช้งาน')
  })

  it('บอกว่าสารบรรณต้องใช้ร่วมกับโมดูลระบบกลาง', async () => {
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('ต้องใช้ร่วมกับ: system')
  })

  it('มีสวิตช์ปิดโมดูลได้ เพราะสารบรรณเป็นโมดูลธุรกิจ ไม่ใช่โมดูลแกน', async () => {
    const wrapper = await mountView()

    const labels = wrapper
      .findAll('button[role="switch"]')
      .map((button) => button.attributes('aria-label'))

    expect(labels).toContain('เปิดหรือปิดระบบงานสารบรรณอิเล็กทรอนิกส์')
  })

  /**
   * สวิตช์ต้องส่ง `config_key` ไม่ใช่ `code` — กลไกเปิด-ปิดของระบบเดิมผูกกับค่าตั้งค่า
   * `module_{configKey}_enabled` ถ้าส่งผิดคีย์ สวิตช์จะกดแล้วไม่มีอะไรเกิดขึ้น
   * ซึ่งเป็นบั๊กเดิมที่ CLEANUP-02 เพิ่งแก้ไป
   */
  it('กดสวิตช์แล้วส่ง config_key ของสารบรรณไปยัง endpoint เดิม', async () => {
    post.mockResolvedValue({
      data: { message: 'ปิดโมดูลแล้ว', affected_dependants: [], modules: modulesPayload },
    })

    const wrapper = await mountView()

    const sarabanSwitch = wrapper
      .findAll('button[role="switch"]')
      .find(
        (button) =>
          button.attributes('aria-label') === 'เปิดหรือปิดระบบงานสารบรรณอิเล็กทรอนิกส์',
      )

    await sarabanSwitch.trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/admin/modules', {
      module: 'saraban',
      enabled: false,
    })
  })

  it('ไม่ได้เพิ่มหน้าจอหรือแถบเมนูใหม่ให้สารบรรณในเช็คพอยต์นี้', async () => {
    const wrapper = await mountView()

    // หน้านี้เรียกเฉพาะ endpoint เดิมของทะเบียนโมดูล ไม่มีเส้นทางของสารบรรณโดยเฉพาะ
    const calledUrls = get.mock.calls.map(([url]) => url)

    expect(calledUrls).toEqual(['/admin/modules'])
    expect(wrapper.html()).not.toContain('/saraban/')
  })
})
