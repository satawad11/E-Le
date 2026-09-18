import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import InterAgencyDispatchModal from '../src/components/saraban/InterAgencyDispatchModal.vue'
import api from '../src/axios'

vi.mock('../src/axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
  },
}))

describe('ระบบเชื่อมโยงข้ามโมดูลและแลกเปลี่ยนหนังสือข้ามสถานศึกษา (Phase 8: Integration & Exchange)', () => {
  const dummyDocument = {
    id: 101,
    subject: 'หนังสือขอความอนุเคราะห์วิทยากรข้ามสถานศึกษา',
    document_number: 'ศธ ๐๖๐๑/๒๕๖๙',
    status: 'signed',
    finalized_at: '2026-09-16T12:00:00Z',
  }

  it('InterAgencyDispatchModal เรนเดอร์หัวข้อและรายละเอียดหนังสืออย่างถูกต้อง', () => {
    const wrapper = mount(InterAgencyDispatchModal, {
      props: {
        isOpen: true,
        document: dummyDocument,
      },
    })

    expect(wrapper.text()).toContain('ส่งหนังสือราชการข้ามสถานศึกษา / หน่วยงาน')
    expect(wrapper.text()).toContain('หนังสือขอความอนุเคราะห์วิทยากรข้ามสถานศึกษา')
    expect(wrapper.text()).toContain('ศธ ๐๖๐๑/๒๕๖๙')
    expect(wrapper.text()).toContain('ดาวน์โหลดแพ็กเกจหลักฐาน (.zip)')
    expect(wrapper.text()).toContain('ส่งตรงผ่าน Webhook API')
  })

  it('InterAgencyDispatchModal สลับโหมดนำส่งและแสดงฟิลด์ URL และ Secret', async () => {
    const wrapper = mount(InterAgencyDispatchModal, {
      props: {
        isOpen: true,
        document: dummyDocument,
      },
    })

    const webhookRadio = wrapper.find('input[value="webhook"]')
    await webhookRadio.setChecked()

    expect(wrapper.text()).toContain('Webhook Endpoint URL')
    expect(wrapper.text()).toContain('รหัสลับความปลอดภัย (Secret Key สำหรับ HMAC)')
  })

  it('InterAgencyDispatchModal ส่งข้อมูลผ่าน Webhook API พร้อม HMAC secret สำเร็จ', async () => {
    api.post.mockResolvedValueOnce({
      data: {
        success: true,
        dispatch_log: {
          destination_url: 'https://other-college.ac.th/api/saraban/incoming',
          response_code: 200,
          delivered_at: '2026-09-16T14:00:00Z',
        },
      },
    })

    const wrapper = mount(InterAgencyDispatchModal, {
      props: {
        isOpen: true,
        document: dummyDocument,
      },
    })

    const webhookRadio = wrapper.find('input[value="webhook"]')
    await webhookRadio.setChecked()

    await wrapper.find('#target-agency').setValue('วิทยาลัยเทคนิคเชียงใหม่')
    await wrapper.find('#webhook-url').setValue('https://other-college.ac.th/api/saraban/incoming')
    await wrapper.find('#secret-key').setValue('SecretKey123!')

    const buttons = wrapper.findAll('button')
    const submitBtn = buttons.find(b => b.text().includes('ส่งหนังสือผ่าน Webhook'))
    expect(submitBtn).toBeDefined()
    await submitBtn.trigger('click')

    expect(api.post).toHaveBeenCalledWith(
      '/saraban/documents/101/dispatch-webhook',
      expect.objectContaining({
        target_agency_name: 'วิทยาลัยเทคนิคเชียงใหม่',
        webhook_url: 'https://other-college.ac.th/api/saraban/incoming',
        secret_key: 'SecretKey123!',
      })
    )
  })

  it('InterAgencyDispatchModal ปล่อย event close เมื่อกดยกเลิก', async () => {
    const wrapper = mount(InterAgencyDispatchModal, {
      props: {
        isOpen: true,
        document: dummyDocument,
      },
    })

    const buttons = wrapper.findAll('button')
    const cancelBtn = buttons.find(b => b.text().includes('ยกเลิก'))
    expect(cancelBtn).toBeDefined()
    await cancelBtn.trigger('click')

    expect(wrapper.emitted('close')).toBeTruthy()
  })
})
