import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import DistributeDocumentModal from '../src/components/saraban/DistributeDocumentModal.vue'

vi.mock('../src/axios', () => ({
  default: {
    get: vi.fn((url) => {
      if (url === '/saraban/distribution-targets') {
        return Promise.resolve({
          data: {
            users: [
              { id: 1, name: 'นายประเสริฐ ผู้อำนวยการ' },
              { id: 2, name: 'นางกัญญาภัทร รองวิชาการ' },
            ],
            org_units: [
              { id: 10, name: 'ฝ่ายบริหารทรัพยากร' },
              { id: 20, name: 'ฝ่ายวิชาการ' },
              { id: 30, name: 'ฝ่ายพัฒนากิจการนักเรียนนักศึกษา' },
              { id: 40, name: 'ฝ่ายแผนงานและความร่วมมือ' },
            ],
          },
        })
      }
      return Promise.resolve({ data: {} })
    }),
    post: vi.fn(() => Promise.resolve({ data: { success: true } })),
  },
}))

describe('ระบบการไหลเวียนหนังสือและการเวียนหนังสือ ๔ ฝ่าย (Vocational Routing & Distribution)', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
  })

  it('DistributeDocumentModal เรนเดอร์ปุ่มเวียนด่วน ๔ ฝ่ายหลัก สอศ. ครบถ้วน', async () => {
    const wrapper = mount(DistributeDocumentModal, {
      props: {
        documentId: 101,
        confidentiality: 'normal',
      },
      global: {
        stubs: {
          teleport: true,
          transition: true,
        },
      },
    })

    await flushPromises()

    const text = wrapper.text()
    expect(text).toContain('ฝ่ายบริหารทรัพยากร')
    expect(text).toContain('ฝ่ายวิชาการ')
    expect(text).toContain('ฝ่ายพัฒนากิจการนักเรียนนักศึกษา')
    expect(text).toContain('ฝ่ายแผนงานและความร่วมมือ')
  })

  it('คลิกปุ่มเวียนด่วนฝ่ายวิชาการ จะแทรกเป้าหมายแบบ org_subtree อัตโนมัติ', async () => {
    const wrapper = mount(DistributeDocumentModal, {
      props: {
        documentId: 101,
        confidentiality: 'normal',
      },
      global: {
        stubs: {
          teleport: true,
          transition: true,
        },
      },
    })

    await flushPromises()

    const divisionBtn = wrapper.findAll('button').find((b) => b.text().includes('ฝ่ายวิชาการ'))
    expect(divisionBtn).toBeTruthy()
    await divisionBtn.trigger('click')
    await flushPromises()

    expect(wrapper.vm.targets.some((t) => t.type === 'org_subtree' && t.id === 20)).toBe(true)
  })

  it('แสดงคำเตือนและปิดกั้นการเวียนหนังสือชั้นลับที่สุด (Top Secret)', async () => {
    const wrapper = mount(DistributeDocumentModal, {
      props: {
        documentId: 102,
        confidentiality: 'top_secret',
      },
      global: {
        stubs: {
          teleport: true,
          transition: true,
        },
      },
    })

    await flushPromises()

    expect(wrapper.text()).toContain('ชั้นความลับ: ลับที่สุด')
    expect(wrapper.text()).toContain('ไม่อาจจัดส่งหรือเวียนด้วยระบบสารบรรณอิเล็กทรอนิกส์ได้')
  })
})
