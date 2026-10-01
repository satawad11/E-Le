import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RegisterReportsView from '../src/views/saraban/RegisterReportsView.vue'
import ComplianceEvidenceView from '../src/views/admin/operations/ComplianceEvidenceView.vue'

vi.mock('../src/axios', () => ({
  default: {
    get: vi.fn((url) => {
      if (url === '/saraban/reports/summary') {
        return Promise.resolve({
          data: {
            total: 10,
            monthly: [
              { month: '2026-08', label: 'ส.ค.', incoming: 2, outgoing: 3, internal: 5 },
            ],
            by_status: [
              { status: 'signed', count: 6 },
              { status: 'registered', count: 4 },
            ],
            by_division: [
              { division_id: 1, code: 'DIR_RES', name: 'ฝ่ายบริหารทรัพยากร', count: 4 },
              { division_id: 2, code: 'DIR_PLAN', name: 'ฝ่ายแผนงานและความร่วมมือ', count: 2 },
              { division_id: 3, code: 'DIR_STD', name: 'ฝ่ายพัฒนากิจการนักเรียนนักศึกษา', count: 1 },
              { division_id: 4, code: 'DIR_ACAD', name: 'ฝ่ายวิชาการ', count: 3 },
            ],
            since: '2025-10-01',
          },
        })
      }
      if (url === '/saraban/reports/register') {
        return Promise.resolve({
          data: {
            total: 2,
            rows: [
              {
                id: 1,
                subject: 'หนังสือราชการฝ่ายบริหารทรัพยากร',
                direction: 'incoming',
                document_number: 'ว 123/2569',
                document_type: 'หนังสือภายนอก',
                owner_org_unit: 'งานบุคลากร',
                status: 'registered',
              },
            ],
          },
        })
      }
      if (url === '/admin/operations/compliance') {
        return Promise.resolve({
          data: {
            generated_at: '2026-09-16T10:00:00+07:00',
            summary: { PASS: 5, FAIL: 0, NO_EVIDENCE: 1 },
            topics: [
              {
                key: 'CRYPTOGRAPHIC_SEAL',
                requirement: 'การปิดผนึกแฮชเอกสาร (SHA-256 / SHA-512)',
                method: 'ตรวจแฮชในฐานข้อมูลและไฟล์จริง',
                status: 'PASS',
                evidence: { match_rate: 100 },
              },
            ],
          },
        })
      }
      return Promise.resolve({ data: {} })
    }),
  },
}))

describe('SARABAN Phase 7 — Reporting, Analytics & Legal Compliance Evidence', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('RegisterReportsView — เรนเดอร์สรุปภาพรวมและสัดส่วนหนังสือแยกตาม ๔ ฝ่ายหลัก สอศ.', async () => {
    const wrapper = mount(RegisterReportsView, {
      global: {
        stubs: {
          Bar: true,
          Doughnut: true,
        },
        directives: {
          can: () => {},
        },
      },
    })

    await new Promise((r) => setTimeout(r, 50))

    expect(wrapper.text()).toContain('รายงานสารบรรณ')
    expect(wrapper.text()).toContain('สรุปภาพรวม')
    expect(wrapper.text()).toContain('สัดส่วนหนังสือแยกตาม ๔ ฝ่ายหลัก (สอศ.)')
    expect(wrapper.text()).toContain('ฝ่ายบริหารทรัพยากร')
    expect(wrapper.text()).toContain('ฝ่ายวิชาการ')
  })

  it('ComplianceEvidenceView — แสดงผลทะเบียนหลักฐานความสอดคล้องตามระเบียบ', async () => {
    const wrapper = mount(ComplianceEvidenceView, {
      global: {
        directives: {
          can: () => {},
        },
      },
    })

    await new Promise((r) => setTimeout(r, 50))

    expect(wrapper.text()).toContain('หลักฐานตามระเบียบ')
    expect(wrapper.text()).toContain('การปิดผนึกแฮชเอกสาร')
  })
})
