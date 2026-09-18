import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import LiveDocumentPreview from '../src/components/saraban/LiveDocumentPreview.vue'
import { ThaiDateFormatter } from '../src/utils/ThaiDateFormatter'

describe('ระบบร่างและปรับปรุงเอกสารหนังสือราชการ (Document Drafting & Live Preview)', () => {
  it('ThaiDateFormatter.toThaiDigits แปลงตัวเลขอารบิกเป็นเลขไทยอย่างถูกต้อง', () => {
    expect(ThaiDateFormatter.toThaiDigits('16 กันยายน 2569')).toBe('๑๖ กันยายน ๒๕๖๙')
    expect(ThaiDateFormatter.toThaiDigits('ศธ 0601/1234')).toBe('ศธ ๐๖๐๑/๑๒๓๔')
    expect(ThaiDateFormatter.toThaiDigits('0123456789')).toBe('๐๑๒๓๔๕๖๗๘๙')
    expect(ThaiDateFormatter.toThaiDigits('')).toBe('')
    expect(ThaiDateFormatter.toThaiDigits(null)).toBe('')
  })

  it('LiveDocumentPreview เรนเดอร์แม่แบบบันทึกข้อความ (ภายใน) พร้อมครุฑซ้ายและหัวเรื่องบันทึกข้อความ', () => {
    const wrapper = mount(LiveDocumentPreview, {
      props: {
        templateId: 'internal_memo',
        subject: 'ขออนุมัติจัดโครงการพัฒนาทักษะวิชาชีพ',
        summary: 'ด้วย มีความจำเป็นต้องดำเนินโครงการ...',
        agency: 'ฝ่ายวิชาการ แผนกวิชาเทคโนโลยีสารสนเทศ',
        salutation: 'ผู้อำนวยการวิทยาลัย',
        documentNumber: 'ศธ ๐๖๐๑/๑๒๓๔',
      },
    })

    expect(wrapper.text()).toContain('บันทึกข้อความ')
    expect(wrapper.text()).toContain('ส่วนราชการ')
    expect(wrapper.text()).toContain('ฝ่ายวิชาการ แผนกวิชาเทคโนโลยีสารสนเทศ')
    expect(wrapper.text()).toContain('ขออนุมัติจัดโครงการพัฒนาทักษะวิชาชีพ')

    // ตรวจสอบรูปภาพตราครุฑ
    const garudaImg = wrapper.find('img[alt="ตราครุฑ"]')
    expect(garudaImg.exists()).toBe(true)
  })

  it('LiveDocumentPreview เรนเดอร์แม่แบบหนังสือภายนอก พร้อมครุฑกึ่งกลางและคำลงท้าย', () => {
    const wrapper = mount(LiveDocumentPreview, {
      props: {
        templateId: 'external_letter',
        subject: 'ขอความอนุเคราะห์วิทยากร',
        summary: 'ด้วย วิทยาลัยมีความประสงค์จะขอความอนุเคราะห์...',
        agency: 'วิทยาลัยเทคนิคตัวอย่าง สังกัดสำนักงานคณะกรรมการการอาชีวศึกษา',
        salutation: 'ผู้จัดการฝ่ายทรัพยากรบุคคล',
        contact: 'งานสารบรรณ โทร. ๐-๒xxx-xxxx',
      },
    })

    expect(wrapper.text()).toContain('ขอแสดงความนับถือ')
    expect(wrapper.text()).toContain('ขอความอนุเคราะห์วิทยากร')
    expect(wrapper.text()).toContain('วิทยาลัยเทคนิคตัวอย่าง')
    expect(wrapper.text()).toContain('ผู้จัดการฝ่ายทรัพยากรบุคคล')
  })

  it('LiveDocumentPreview เรนเดอร์แม่แบบคำสั่งวิทยาลัย พร้อมหัวเรื่องคำสั่งและข้อความสั่ง ณ วันที่', () => {
    const wrapper = mount(LiveDocumentPreview, {
      props: {
        templateId: 'college_order',
        subject: 'แต่งตั้งคณะกรรมการดำเนินงาน',
        summary: 'เพื่อให้การดำเนินงานเป็นไปด้วยความเรียบร้อย...',
        agency: 'วิทยาลัยเทคนิคตัวอย่าง',
        documentNumber: '๑๒๓/๒๕๖๙',
      },
    })

    expect(wrapper.text()).toContain('คำสั่งวิทยาลัยเทคนิคตัวอย่าง')
    expect(wrapper.text()).toContain('เรื่อง แต่งตั้งคณะกรรมการดำเนินงาน')
    expect(wrapper.text()).toContain('สั่ง ณ วันที่')
  })

  it('LiveDocumentPreview เรนเดอร์แม่แบบประกาศวิทยาลัย พร้อมหัวเรื่องประกาศและข้อความประกาศ ณ วันที่', () => {
    const wrapper = mount(LiveDocumentPreview, {
      props: {
        templateId: 'college_announcement',
        subject: 'รับสมัครนักศึกษาใหม่ ประจำปีการศึกษา ๒๕๗๐',
        summary: 'ด้วย วิทยาลัยมีความประสงค์จะรับสมัคร...',
        agency: 'วิทยาลัยเทคนิคตัวอย่าง',
      },
    })

    expect(wrapper.text()).toContain('ประกาศวิทยาลัยเทคนิคตัวอย่าง')
    expect(wrapper.text()).toContain('เรื่อง รับสมัครนักศึกษาใหม่')
    expect(wrapper.text()).toContain('ประกาศ ณ วันที่')
  })

  it('LiveDocumentPreview สามารถสลับการแสดงผลตัวเลขไทยและเลขอารบิกได้', async () => {
    const wrapper = mount(LiveDocumentPreview, {
      props: {
        templateId: 'internal_memo',
        subject: 'ทดสอบเลข 12345',
        summary: 'เนื้อหาเลข 67890',
        agency: 'หน่วยงาน 101',
      },
    })

    // โดยเริ่มต้นเปิดเลขไทย
    expect(wrapper.text()).toContain('๑๒๓๔๕')
    expect(wrapper.text()).toContain('๖๗๘๙๐')

    // กดปุ่มสลับเป็นเลขอารบิก
    const toggleButton = wrapper.findAll('button').find((b) => b.text().includes('เลขไทย'))
    expect(toggleButton).toBeTruthy()
    await toggleButton.trigger('click')

    // ตอนนี้ควรแสดงเลขอารบิก
    expect(wrapper.text()).toContain('12345')
    expect(wrapper.text()).toContain('67890')
  })

  it('LiveDocumentPreview แสดงตราประทับชั้นความเร็วและชั้นความลับเมื่อมีการระบุ', () => {
    const wrapper = mount(LiveDocumentPreview, {
      props: {
        templateId: 'internal_memo',
        subject: 'เรื่องด่วนที่สุด',
        urgency: 'most_urgent',
        confidentiality: 'secret',
      },
    })

    expect(wrapper.text()).toContain('ด่วนที่สุด')
    expect(wrapper.text()).toContain('ลับมาก')
  })
})
