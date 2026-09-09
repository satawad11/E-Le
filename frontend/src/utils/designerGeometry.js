/**
 * เรขาคณิตของหน้าออกแบบแบบฟอร์ม (Visual PDF Template Designer)
 *
 * แยกออกจากคอมโพเนนต์เพราะเป็นตรรกะล้วน ทดสอบได้โดยไม่ต้องเรนเดอร์ PDF
 * ทุกค่าที่รับและคืนเป็น **PDF point วัดจากมุมซ้ายบนของหน้า** ซึ่งเป็นหน่วยเดียว
 * กับที่ backend ใช้วางฟิลด์จริงตอนพิมพ์ (`document_field_mappings`)
 */

/** ปัดค่าเข้าเส้นตาราง — grid <= 0 คือปิดการดูดเข้าเส้น */
export function snapValue(value, grid) {
  if (!grid || grid <= 0) return Math.round(value * 100) / 100
  return Math.round(value / grid) * grid
}

/**
 * ดันกล่องให้อยู่ในหน้ากระดาษ
 *
 * ถ้ากล่องใหญ่กว่าหน้ากระดาษจะชิดมุมซ้ายบนไว้ก่อน ไม่ย่อขนาดให้เอง
 * เพราะขนาดกล่องเป็นเจตนาของผู้ดูแล ไม่ใช่ค่าที่ระบบควรเปลี่ยนเงียบ ๆ
 */
export function clampBox(box, page) {
  const maxX = Math.max(0, page.width - box.width)
  const maxY = Math.max(0, page.height - box.height)

  return {
    ...box,
    x: Math.min(Math.max(0, box.x), maxX),
    y: Math.min(Math.max(0, box.y), maxY),
  }
}

/** ขนาดกล่องที่ไม่ล้นหน้ากระดาษเมื่อวัดจากมุมซ้ายบนของกล่อง */
export function clampSize(box, page, min = { width: 10, height: 8 }) {
  return {
    ...box,
    width: Math.min(Math.max(min.width, box.width), Math.max(min.width, page.width - box.x)),
    height: Math.min(Math.max(min.height, box.height), Math.max(min.height, page.height - box.y)),
  }
}

/** กล่องอยู่ในหน้ากระดาษทั้งใบหรือไม่ (เผื่อทศนิยม 1 pt เท่ากับฝั่งเซิร์ฟเวอร์) */
export function isInsidePage(box, page, tolerance = 1) {
  return box.x >= -tolerance
    && box.y >= -tolerance
    && box.x + box.width <= page.width + tolerance
    && box.y + box.height <= page.height + tolerance
}

/**
 * จัดแนวกล่องหลายกล่องให้ตรงกัน
 *
 * ยึด "กล่องที่กินพื้นที่นอกสุด" เป็นเส้นอ้างอิง (เช่น จัดชิดซ้าย = ใช้ x น้อยที่สุด)
 * ซึ่งเป็นพฤติกรรมเดียวกับเครื่องมือออกแบบทั่วไป ผู้ดูแลจึงเดาผลลัพธ์ได้
 */
export function alignBoxes(boxes, mode) {
  if (boxes.length < 2) return boxes

  const left = Math.min(...boxes.map(b => b.x))
  const right = Math.max(...boxes.map(b => b.x + b.width))
  const top = Math.min(...boxes.map(b => b.y))
  const bottom = Math.max(...boxes.map(b => b.y + b.height))

  return boxes.map((box) => {
    switch (mode) {
      case 'left': return { ...box, x: left }
      case 'right': return { ...box, x: right - box.width }
      case 'center': return { ...box, x: (left + right) / 2 - box.width / 2 }
      case 'top': return { ...box, y: top }
      case 'bottom': return { ...box, y: bottom - box.height }
      case 'middle': return { ...box, y: (top + bottom) / 2 - box.height / 2 }
      default: return box
    }
  })
}

/**
 * กระจายระยะห่างให้เท่ากันตามแกนที่เลือก
 *
 * กล่องหัวและท้ายอยู่กับที่ กล่องระหว่างกลางถูกไล่ระยะให้เท่ากัน
 * ต้องมีอย่างน้อย 3 กล่องจึงมีความหมาย
 */
export function distributeBoxes(boxes, axis) {
  if (boxes.length < 3) return boxes

  const key = axis === 'horizontal' ? 'x' : 'y'
  const sorted = [...boxes].sort((a, b) => a[key] - b[key])
  const first = sorted[0][key]
  const last = sorted[sorted.length - 1][key]
  const step = (last - first) / (sorted.length - 1)

  const moved = new Map(
    sorted.map((box, index) => [box, { ...box, [key]: first + step * index }]),
  )

  return boxes.map(box => moved.get(box) ?? box)
}

/**
 * ตำแหน่งใหม่หลังกดปุ่มลูกศร
 *
 * ระยะปกติ 1 pt และกด Shift ค้างได้ 10 pt — ละเอียดพอสำหรับการเล็งช่องในแบบฟอร์มราชการ
 * ซึ่งลากด้วยเมาส์ให้ตรงเป๊ะไม่ได้
 */
export function nudgeBox(box, direction, page, big = false) {
  const step = big ? 10 : 1
  const delta = {
    ArrowLeft: { x: -step, y: 0 },
    ArrowRight: { x: step, y: 0 },
    ArrowUp: { x: 0, y: -step },
    ArrowDown: { x: 0, y: step },
  }[direction]

  if (!delta) return box

  return clampBox({ ...box, x: box.x + delta.x, y: box.y + delta.y }, page)
}

/** ขนาดหน้ากระดาษเริ่มต้นเมื่อยังอ่านไฟล์ต้นฉบับไม่ได้ (A4 แนวตั้ง) */
export const DEFAULT_PAGE = Object.freeze({ width: 595.28, height: 841.89 })

/** ขนาดของหน้าที่ระบุ — ถอยไปใช้ A4 เฉพาะตอนที่ยังไม่รู้ขนาดจริงเท่านั้น */
export function pageSize(pages, page) {
  return pages?.[page - 1] ?? DEFAULT_PAGE
}
