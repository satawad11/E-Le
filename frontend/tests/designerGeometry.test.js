import { describe, it, expect } from 'vitest'
import {
  snapValue, clampBox, clampSize, isInsidePage, alignBoxes, distributeBoxes, nudgeBox, pageSize, DEFAULT_PAGE,
} from '../src/utils/designerGeometry'

/*
 * เรขาคณิตของหน้าออกแบบแบบฟอร์ม
 *
 * บั๊กเดิม: หน้าออกแบบตรึงขนาดกระดาษไว้ที่ A4 (595×842) เสมอ ขณะที่ตอนพิมพ์
 * ระบบวางฟิลด์บนขนาดหน้าจริงของไฟล์ต้นฉบับ แบบฟอร์มขนาดอื่นจึงพิมพ์ออกมาไม่ตรงกับที่เห็น
 * เทสต์ชุดนี้ยึด "ขนาดหน้าที่ส่งเข้ามา" เป็นระบบพิกัดเสมอ ไม่มีค่าคงที่ A4 ในการคำนวณใด
 */

const A4 = { width: 595.28, height: 841.89 }
const LANDSCAPE_F4 = { width: 935.43, height: 595.28 }

describe('snapValue', () => {
  it('ปัดค่าเข้าเส้นตารางตามระยะที่กำหนด', () => {
    expect(snapValue(12.4, 5)).toBe(10)
    expect(snapValue(13, 5)).toBe(15)
  })

  it('ไม่ดูดเข้าเส้นเมื่อปิดการใช้งาน แต่ยังตัดทศนิยมส่วนเกินทิ้ง', () => {
    expect(snapValue(12.4567, 0)).toBe(12.46)
  })
})

describe('clampBox', () => {
  it('ดันกล่องที่หลุดขอบขวาล่างกลับเข้าหน้ากระดาษ', () => {
    const box = clampBox({ x: 900, y: 1000, width: 100, height: 20 }, A4)

    expect(box.x).toBeCloseTo(A4.width - 100, 2)
    expect(box.y).toBeCloseTo(A4.height - 20, 2)
  })

  it('ใช้ขนาดหน้าจริงของแบบฟอร์มแนวนอน ไม่ใช่ A4', () => {
    const box = clampBox({ x: 900, y: 10, width: 30, height: 20 }, LANDSCAPE_F4)

    expect(box.x).toBeCloseTo(900, 2)
    expect(clampBox({ x: 900, y: 10, width: 30, height: 20 }, A4).x).toBeLessThan(900)
  })

  it('ไม่ย่อขนาดกล่องที่ใหญ่กว่าหน้ากระดาษ เพียงชิดมุมซ้ายบน', () => {
    const box = clampBox({ x: 50, y: 50, width: 2000, height: 2000 }, A4)

    expect(box).toMatchObject({ x: 0, y: 0, width: 2000, height: 2000 })
  })
})

describe('clampSize', () => {
  it('ไม่ให้ปรับขนาดจนล้นขอบกระดาษ', () => {
    const box = clampSize({ x: 500, y: 800, width: 400, height: 300 }, A4)

    expect(box.x + box.width).toBeLessThanOrEqual(A4.width + 0.01)
    expect(box.y + box.height).toBeLessThanOrEqual(A4.height + 0.01)
  })
})

describe('isInsidePage', () => {
  it('ยอมรับความคลาดเคลื่อน 1 pt เท่ากับที่ฝั่งเซิร์ฟเวอร์ตรวจ', () => {
    expect(isInsidePage({ x: 0, y: 0, width: A4.width + 0.5, height: 10 }, A4)).toBe(true)
    expect(isInsidePage({ x: 0, y: 0, width: A4.width + 5, height: 10 }, A4)).toBe(false)
  })
})

describe('alignBoxes', () => {
  const boxes = [
    { x: 10, y: 10, width: 100, height: 20 },
    { x: 40, y: 60, width: 60, height: 20 },
    { x: 25, y: 100, width: 80, height: 20 },
  ]

  it('จัดชิดซ้ายด้วยขอบซ้ายสุด', () => {
    expect(alignBoxes(boxes, 'left').map(b => b.x)).toEqual([10, 10, 10])
  })

  it('จัดชิดขวาด้วยขอบขวาสุด', () => {
    expect(alignBoxes(boxes, 'right').map(b => b.x + b.width)).toEqual([110, 110, 110])
  })

  it('จัดกึ่งกลางแนวตั้งให้จุดกึ่งกลางตรงกัน', () => {
    const centers = alignBoxes(boxes, 'center').map(b => b.x + b.width / 2)

    expect(new Set(centers.map(v => Math.round(v * 100)))).toHaveProperty('size', 1)
  })

  it('ไม่แตะอะไรเมื่อเลือกน้อยกว่าสองกล่อง', () => {
    expect(alignBoxes([boxes[0]], 'left')).toEqual([boxes[0]])
  })
})

describe('distributeBoxes', () => {
  it('กระจายระยะให้เท่ากันโดยกล่องหัวท้ายอยู่กับที่', () => {
    const result = distributeBoxes([
      { x: 0, y: 0, width: 10, height: 10 },
      { x: 30, y: 0, width: 10, height: 10 },
      { x: 100, y: 0, width: 10, height: 10 },
    ], 'horizontal')

    expect(result.map(b => b.x)).toEqual([0, 50, 100])
  })

  it('คืนค่าเดิมเมื่อมีน้อยกว่าสามกล่อง', () => {
    const boxes = [{ x: 0, y: 0, width: 10, height: 10 }, { x: 50, y: 0, width: 10, height: 10 }]

    expect(distributeBoxes(boxes, 'horizontal')).toEqual(boxes)
  })
})

describe('nudgeBox', () => {
  const box = { x: 100, y: 100, width: 50, height: 20 }

  it('ขยับ 1 pt ต่อการกดหนึ่งครั้ง และ 10 pt เมื่อกด Shift', () => {
    expect(nudgeBox(box, 'ArrowRight', A4).x).toBe(101)
    expect(nudgeBox(box, 'ArrowUp', A4, true).y).toBe(90)
  })

  it('ไม่ขยับออกนอกหน้ากระดาษ', () => {
    expect(nudgeBox({ x: 0, y: 0, width: 10, height: 10 }, 'ArrowLeft', A4).x).toBe(0)
  })
})

describe('pageSize', () => {
  it('ใช้ขนาดหน้าที่อ่านจากไฟล์จริงเมื่อมี', () => {
    expect(pageSize([A4, LANDSCAPE_F4], 2)).toEqual(LANDSCAPE_F4)
  })

  it('ถอยไปใช้ A4 เฉพาะตอนที่ยังไม่รู้ขนาดจริง', () => {
    expect(pageSize([], 1)).toEqual(DEFAULT_PAGE)
  })
})
