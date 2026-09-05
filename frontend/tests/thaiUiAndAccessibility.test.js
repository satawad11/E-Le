import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * UI-01 — ข้อความที่ผู้ใช้เห็นต้องเป็นภาษาไทย และตัวควบคุมต้องมีป้ายกำกับ
 *
 * ตรวจจากซอร์สของหน้าจอโดยตรง เพราะเป็นสิ่งที่ตรวจอัตโนมัติได้จริงและกันการ
 * ถอยกลับ (regression) ได้ · ไม่ได้แทนการตรวจด้วยตาบนเบราว์เซอร์ แต่กันไม่ให้
 * ข้อความอังกฤษที่เคยแก้ไปแล้วกลับเข้ามาใหม่โดยไม่มีใครเห็น
 */

const SRC = join(dirname(fileURLToPath(import.meta.url)), '..', 'src')

const walk = (dir) => readdirSync(dir).flatMap((entry) => {
  const full = join(dir, entry)
  return statSync(full).isDirectory() ? walk(full) : [full]
})

const vueFiles = walk(SRC).filter((file) => file.endsWith('.vue'))

const readAll = () => vueFiles.map((file) => ({
  file: relative(SRC, file).replace(/\\/g, '/'),
  source: readFileSync(file, 'utf8'),
}))

describe('ข้อความที่ผู้ใช้เห็นเป็นภาษาไทย', () => {
  /*
   * คำที่เคยโผล่เป็นภาษาอังกฤษบนหน้าจอและแก้ไปแล้ว
   *
   * `Document Engine` / `Field Dictionary` ยังปรากฏได้ในฐานะ "ตัวระบุทางเทคนิค"
   * ที่มีคำอธิบายไทยกำกับ จึงตรวจแยกด้านล่างแทนการห้ามทั้งคำ
   */
  const forbidden = [
    'Real-time',
    'Personnel & Org Structure',
    '>Close<',
    'sr-only">Close',
  ]

  it.each(forbidden)('ไม่มีข้อความ "%s" หลงเหลืออยู่ในหน้าจอใด', (phrase) => {
    const offenders = readAll()
      .filter(({ source }) => source.includes(phrase))
      .map(({ file }) => file)

    expect(offenders).toEqual([])
  })

  it('ตัวระบุทางเทคนิคที่ผู้ดูแลต้องเห็น มีคำอธิบายภาษาไทยกำกับเสมอ', () => {
    const source = readFileSync(join(SRC, 'views/admin/DocumentCenterView.vue'), 'utf8')

    // ปรากฏได้ แต่ต้องอยู่ในวงเล็บหลังคำอธิบายไทย
    const occurrences = source.match(/Document Engine/g) ?? []
    const explained = source.match(/ระบบจัดทำเอกสารอัตโนมัติ \(Document Engine\)/g) ?? []

    expect(occurrences.length).toBeGreaterThan(0)
    expect(explained.length).toBe(occurrences.length)
  })

  it('ปุ่มปิดการแจ้งเตือนอ่านออกเป็นภาษาไทยสำหรับโปรแกรมอ่านหน้าจอ', () => {
    const source = readFileSync(join(SRC, 'components/AlertList.vue'), 'utf8')

    expect(source).toContain('<span class="sr-only">ปิดการแจ้งเตือน</span>')
  })
})

describe('ป้ายกำกับของตัวควบคุมบนหน้าจอหลัก', () => {
  const mainScreens = [
    'views/LoginView.vue',
    'views/DashboardView.vue',
    'views/LeaveRequestView.vue',
    'views/MyLeavesView.vue',
    'views/ApprovalsView.vue',
    'views/admin/UsersManagementView.vue',
    'views/admin/DocumentCenterView.vue',
    'views/admin/WorkflowManagementView.vue',
    'views/Attendance/CheckInView.vue',
  ]

  /** input/select/textarea ที่ไม่มีทั้ง aria-label, id และไม่ได้อยู่ใน <label> ครอบ */
  const unlabelledControls = (source) => {
    const withoutLabelWrappers = source.replace(/<label\b[^>]*>[\s\S]*?<\/label>/g, '')
    const controls = withoutLabelWrappers.match(/<(input|select|textarea)\b[^>]*>/g) ?? []

    return controls.filter((tag) =>
      !/aria-label/.test(tag) &&
      !/\bid=/.test(tag) &&
      !/type="hidden"/.test(tag) &&
      !/class="[^"]*\bhidden\b/.test(tag) &&
      !/class="[^"]*\bsr-only\b/.test(tag)
    )
  }

  it.each(mainScreens)('ทุกช่องกรอกใน %s มีป้ายกำกับหรือ ARIA ภาษาไทย', (screen) => {
    const source = readFileSync(join(SRC, screen), 'utf8')

    expect(unlabelledControls(source)).toEqual([])
  })

  it.each(mainScreens)('ป้าย ARIA ใน %s เป็นภาษาไทย', (screen) => {
    const source = readFileSync(join(SRC, screen), 'utf8')
    const labels = [...source.matchAll(/aria-label="([^"{][^"]*)"/g)].map((match) => match[1])

    // ยอมให้มีตัวย่อ/หน่วยเป็นอังกฤษปนได้ แต่ต้องมีอักษรไทยอยู่ในป้ายเสมอ
    const withoutThai = labels.filter((label) => !/[ก-๙]/.test(label))

    expect(withoutThai).toEqual([])
  })
})
