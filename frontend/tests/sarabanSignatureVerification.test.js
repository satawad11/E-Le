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

vi.mock('../src/utils/secureFile', () => ({ fetchObjectUrl: vi.fn() }))

const DocumentTools = (await import('../src/components/saraban/DocumentTools.vue')).default

/**
 * SIGNATURE-VERIFICATION — ชั้นหน้าจอของการตรวจสอบลายเซ็น (SoT §14)
 *
 * เรื่องที่ตรึงไว้:
 *
 *   1. **หน้าจอแสดงสิ่งที่ server ตัดสิน ไม่ตัดสินเอง** — เหตุผลรายข้อมาจาก payload
 *   2. **ไม่มีใบรับรอง = ไม่มีหัวข้อใบรับรองบนหน้าจอ** ไม่ใช่แสดงว่าผ่าน
 *   3. **ไม่เรียกลายเซ็นอิเล็กทรอนิกส์ว่าลายเซ็นดิจิทัล**
 *   4. ปุ่มขอเพิกถอนต้องบังคับให้กรอกเหตุผลก่อน
 */
const documentStub = {
  id: 7,
  subject: 'หนังสือทดสอบ',
  status: 'signed',
  finalized_at: '2026-09-05T10:00:00+07:00',
  versions: [{ id: 1, version_no: 1 }],
}

const verificationPayload = (overrides = {}) => ({
  status: 'VALID',
  signature_count: 1,
  signatures: [
    {
      id: 11,
      sequence: 1,
      signer: 'นางสาวผู้ลงนาม',
      position: 'ผู้อำนวยการ',
      signed_at: '2026-09-05T10:00:00+07:00',
      signature_type: 'electronic',
      has_certificate: false,
      has_trusted_timestamp: false,
      status: 'VALID',
      checks: [
        { check: 'version_binding', status: 'VALID', detail: 'ผูกกับเวอร์ชันที่ 2 และแฮชตรงกัน' },
        { check: 'integrity', status: 'VALID', detail: 'ไฟล์ตรงกับแฮชที่บันทึกไว้ตอนลงนาม' },
      ],
    },
  ],
  document_checks: [
    { check: 'metadata_seal', status: 'VALID', detail: 'ข้อมูลทะเบียนตรงกับที่ผนึกไว้' },
  ],
  revocation: null,
  ...overrides,
})

const mountTools = () => mount(DocumentTools, {
  props: { document: documentStub },
  global: { directives: { can: {} } },
})

describe('DocumentTools — ตรวจสอบความน่าเชื่อถือของลายเซ็น', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    get.mockReset()
    post.mockReset()
  })

  it('แสดงผลรวมและเหตุผลรายข้อที่ server ส่งมา', async () => {
    get.mockResolvedValue({ data: verificationPayload() })

    const wrapper = mountTools()
    await wrapper.vm.loadVerification()
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/saraban/documents/7/verification')

    const text = wrapper.text()
    expect(text).toContain('ผ่านการตรวจสอบ')
    expect(text).toContain('ความสมบูรณ์ของไฟล์')
    expect(text).toContain('ไฟล์ตรงกับแฮชที่บันทึกไว้ตอนลงนาม')
  })

  /** **ข้อบังคับตรงตัว:** ห้ามแสดง certificate validity เมื่อไม่มี certificate จริง */
  it('ไม่แสดงหัวข้อใบรับรองเมื่อลายเซ็นไม่มีใบรับรอง', async () => {
    get.mockResolvedValue({ data: verificationPayload() })

    const wrapper = mountTools()
    await wrapper.vm.loadVerification()
    await flushPromises()

    expect(wrapper.text()).not.toContain('ใบรับรอง')
  })

  it('บอกชนิดลายเซ็นตรงไปตรงมา ไม่เรียกลายเซ็นอิเล็กทรอนิกส์ว่าลายเซ็นดิจิทัล', async () => {
    get.mockResolvedValue({ data: verificationPayload() })

    const wrapper = mountTools()
    await wrapper.vm.loadVerification()
    await flushPromises()

    const text = wrapper.text()
    expect(text).toContain('ลายเซ็นอิเล็กทรอนิกส์')
    expect(text).toContain('ไม่ใช่ลายเซ็นดิจิทัล')
  })

  it('แสดงว่าไฟล์ถูกแก้หลังลงนามเมื่อ server รายงานเช่นนั้น', async () => {
    get.mockResolvedValue({
      data: verificationPayload({
        status: 'DOCUMENT_MODIFIED_AFTER_SIGNING',
        // ไม่มีข้อตรวจระดับหนังสือที่ผ่าน เพื่อให้ยืนยันได้ว่าคำว่า "ผ่าน" หายไปจริง
        document_checks: [],
        signatures: [{
          ...verificationPayload().signatures[0],
          status: 'DOCUMENT_MODIFIED_AFTER_SIGNING',
          checks: [{ check: 'integrity', status: 'DOCUMENT_MODIFIED_AFTER_SIGNING', detail: 'ไฟล์ถูกแก้หลังการลงนาม' }],
        }],
      }),
    })

    const wrapper = mountTools()
    await wrapper.vm.loadVerification()
    await flushPromises()

    expect(wrapper.text()).toContain('ไฟล์ถูกแก้หลังการลงนาม')
    expect(wrapper.text()).not.toContain('ผ่านการตรวจสอบ')
  })

  it('แสดงสถานะคำขอเพิกถอนที่รอการตัดสิน', async () => {
    get.mockResolvedValue({
      data: verificationPayload({
        status: 'REVOCATION_PENDING',
        revocation: { id: 3, status: 'REVOCATION_PENDING', reason: 'ลงนามผิดฉบับ' },
      }),
    })

    const wrapper = mountTools()
    await wrapper.vm.loadVerification()
    await flushPromises()

    expect(wrapper.text()).toContain('มีคำขอเพิกถอนรอการตัดสิน')
    expect(wrapper.text()).toContain('ลงนามผิดฉบับ')
  })

  /** เหตุผลสั้นเกินไปต้องกดไม่ได้ — server ปฏิเสธอยู่แล้ว แต่หน้าจอไม่ควรพาไปชนกำแพง */
  it('ต้องกรอกเหตุผลอย่างน้อยสิบตัวอักษรก่อนขอเพิกถอนได้', async () => {
    get.mockResolvedValue({ data: verificationPayload() })

    const wrapper = mountTools()
    await wrapper.vm.loadVerification()
    await flushPromises()

    const revokeButton = wrapper.findAll('button').find((b) => b.text().includes('ขอเพิกถอน'))
    expect(revokeButton?.attributes('disabled')).toBeDefined()

    await wrapper.find('input').setValue('ลงนามผิดฉบับ ต้องออกใหม่')
    await flushPromises()
  })

  it('โหลดชุดหลักฐานฉบับเต็มจาก endpoint รายงาน', async () => {
    get.mockResolvedValue({ data: verificationPayload() })

    const wrapper = mountTools()
    await wrapper.vm.loadVerification()
    await flushPromises()

    get.mockResolvedValue({
      data: {
        reported_at: '2026-09-05T12:00:00+07:00',
        evidence_package: [{
          id: 11,
          signer_name_snapshot: 'นางสาวผู้ลงนาม',
          position_snapshot: 'ผู้อำนวยการ',
          signed_at: '2026-09-05T10:00:00+07:00',
          document_hash: 'abc123',
          reauthenticated_at: '2026-09-05T09:58:00+07:00',
          certificate: null,
          trusted_timestamp: null,
        }],
        revocations: [],
      },
    })

    await wrapper.vm.loadReport()
    await flushPromises()

    expect(get).toHaveBeenLastCalledWith('/saraban/documents/7/verification/report')
    expect(wrapper.text()).toContain('abc123')
    // ไม่มีใบรับรอง = ไม่มีบรรทัดใบรับรอง
    expect(wrapper.text()).not.toContain('หมายเลข')
  })
})
