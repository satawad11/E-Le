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

const VocationalEvaluationReportView = (await import('../src/views/admin/VocationalEvaluationReportView.vue')).default

const mockSummaryPayload = {
  success: true,
  data: {
    cycle: 'round_1',
    year: 2569,
    period: {
      cycle: 'round_1',
      fiscal_year: 2569,
      start_date: '2025-10-01',
      end_date: '2026-03-31',
    },
    summary: {
      total_personnel: 2,
      passed_count: 1,
      exceeded_count: 1,
      threshold_exceeded_count: 1,
    },
    records: [
      {
        user_id: 101,
        name: 'ครู สมศักดิ์ สุขใจ',
        position: 'ครู ค.ศ. 2',
        personnel_type: 'ข้าราชการครู',
        personnel_type_id: 1,
        org_unit_name: 'แผนกวิชาช่างยนต์',
        division_name: 'ฝ่ายวิชาการ',
        sick_days: 5,
        personal_days: 2,
        vacation_days: 0,
        late_count: 0,
        total_countable_days: 7,
        max_allowed_days: 23,
        threshold_exceeded: false,
        evaluation_status: 'ปกติ (ผ่านเกณฑ์)',
      },
      {
        user_id: 102,
        name: 'ครู สมพร มานะ',
        position: 'ครู ค.ศ. 1',
        personnel_type: 'ข้าราชการครู',
        personnel_type_id: 1,
        org_unit_name: 'งานพัสดุ',
        division_name: 'ฝ่ายบริหารทรัพยากร',
        sick_days: 15,
        personal_days: 10,
        vacation_days: 0,
        late_count: 3,
        total_countable_days: 25,
        max_allowed_days: 23,
        threshold_exceeded: true,
        evaluation_status: 'เกินเกณฑ์วันลา',
      },
    ],
  },
}

describe('VocationalEvaluationReportView — รายงานประเมินวันลา ก.ค.ศ.', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    post.mockReset()

    get.mockImplementation((url) => {
      if (url === '/org-units') {
        return Promise.resolve({ data: [{ id: 1, name: 'ฝ่ายวิชาการ' }] })
      }
      if (url === '/personnel-types') {
        return Promise.resolve({ data: [{ id: 1, name: 'ข้าราชการครู' }] })
      }
      if (url === '/v1/reports/vocational/evaluation-summary') {
        return Promise.resolve({ data: mockSummaryPayload })
      }
      if (url === '/v1/reports/vocational/teacher-pa-evidence') {
        return Promise.resolve({
          data: {
            success: true,
            data: {
              fiscal_year: 2569,
              user: {
                id: 101,
                name: 'ครู สมศักดิ์ สุขใจ',
                position: 'ครู ค.ศ. 2',
                org_unit: 'แผนกวิชาช่างยนต์',
                division: 'ฝ่ายวิชาการ',
                personnel_type: 'ข้าราชการครู',
              },
              leave_breakdown: { sick: 5, personal: 2, vacation: 0 },
              attendance_summary: { late_count: 0, absent_count: 0 },
              workload_summary: { total_days_worked: 120, total_ot_hours: 10 },
              evaluation: {
                total_countable_days: 7,
                max_allowed_days: 23,
                threshold_exceeded: false,
                evaluation_status: 'ปกติ (ผ่านเกณฑ์)',
              },
            },
          },
        })
      }
      return Promise.resolve({ data: {} })
    })
  })

  const mountView = async () => {
    const wrapper = mount(VocationalEvaluationReportView)
    await flushPromises()
    return wrapper
  }

  it('เรนเดอร์หัวข้อรายงานประเมินวันลา ก.ค.ศ. และปุ่มลัดรอบเวลาครบถ้วน', async () => {
    const wrapper = await mountView()
    const text = wrapper.text()

    expect(text).toContain('รายงานประเมินวันลาและเวลาปฏิบัติราชการ (ก.ค.ศ.)')
    expect(text).toContain('รอบที่ ๑ (๑ ต.ค. – ๓๑ มี.ค.)')
    expect(text).toContain('รอบที่ ๒ (๑ เม.ย. – ๓๐ ก.ย.)')
    expect(text).toContain('ตลอดปีงบประมาณ')
  })

  it('แสดงข้อมูลสถิติ KPI และรายการในตารางพร้อมผลการประเมิน ๒๓ วัน', async () => {
    const wrapper = await mountView()
    const text = wrapper.text()

    expect(text).toContain('ครู สมศักดิ์ สุขใจ')
    expect(text).toContain('ปกติ (ผ่านเกณฑ์)')
    expect(text).toContain('ครู สมพร มานะ')
    expect(text).toContain('เกินเกณฑ์วันลา')
    expect(text).toContain('๒๓')
  })

  it('คลิกดูแฟ้ม ว PA แล้วเปิด Modal แสดงข้อมูลหลักฐานรายบุคคล', async () => {
    const wrapper = await mountView()

    const paButtons = wrapper.findAll('button').filter((b) => b.text().includes('ดูแฟ้ม ว PA'))
    expect(paButtons.length).toBeGreaterThan(0)

    await paButtons[0].trigger('click')
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/v1/reports/vocational/teacher-pa-evidence', expect.objectContaining({
      params: expect.objectContaining({ target_user_id: 101 }),
    }))

    expect(document.body.textContent).toContain('แฟ้มหลักฐาน ว PA และภาระงาน')
    expect(document.body.textContent).toContain('สรุปเวลาปฏิบัติงานและภาระงานราชการ')
  })
})
