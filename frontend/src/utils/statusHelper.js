/**
 * statusHelper.js
 * ตัวช่วยแปลง status ของใบลาเป็นภาษาไทย + สีที่สอดคล้อง
 */

export const STATUS_MAP = {
  Pending:   { label: 'รออนุมัติ',  color: 'amber' },
  Approved:  { label: 'อนุมัติแล้ว', color: 'green' },
  Rejected:  { label: 'ไม่อนุมัติ',  color: 'red'   },
  Cancelled: { label: 'ยกเลิกแล้ว', color: 'gray'  },
}

/**
 * แปลง status เป็นข้อความภาษาไทย
 * @param {string} status
 * @returns {string}
 */
export function statusLabel(status) {
  return STATUS_MAP[status]?.label ?? status
}

/**
 * คืน Tailwind class สำหรับ badge ตาม status
 * @param {string} status
 * @returns {string}
 */
export function statusBadgeClass(status) {
  const colorMap = {
    amber: 'bg-amber-100 text-amber-700',
    green: 'bg-green-100 text-green-700',
    red:   'bg-red-100   text-red-700',
    gray:  'bg-gray-100  text-gray-600',
  }
  const color = STATUS_MAP[status]?.color ?? 'gray'
  return colorMap[color] ?? colorMap.gray
}

export function compositeStatusLabel(leave) {
  if (leave.status === 'Approved' && leave.cancellation_status === 'Pending') {
    return 'รออนุมัติยกเลิก'
  }
  return statusLabel(leave.status)
}

export function compositeStatusBadgeClass(leave) {
  if (leave.status === 'Approved' && leave.cancellation_status === 'Pending') {
    return 'bg-orange-100 text-orange-700'
  }
  return statusBadgeClass(leave.status)
}

/**
 * สถานะเอกสารของโมดูลสารบัญ — ครอบคลุมทุก state ใน state machine
 * (draft → pending_review → pending_approval → approved_pending_signature
 *  → signed → dispatched → archived) รวมสถานะเดิมที่ยังมีข้อมูลค้างอยู่
 */
export const SARABAN_STATUS_MAP = {
  draft:                      { label: 'ฉบับร่าง',      class: 'bg-gray-100 text-gray-800 border-gray-200' },
  pending:                    { label: 'รอดำเนินการ',   class: 'bg-yellow-100 text-yellow-800 border-yellow-200' },
  pending_review:             { label: 'รอตรวจสอบ',     class: 'bg-amber-100 text-amber-800 border-amber-200' },
  pending_approval:           { label: 'รออนุมัติ',      class: 'bg-yellow-100 text-yellow-800 border-yellow-200' },
  approved_pending_signature: { label: 'รอลงนาม',       class: 'bg-purple-100 text-purple-800 border-purple-200' },
  approved:                   { label: 'อนุมัติแล้ว',    class: 'bg-green-100 text-green-800 border-green-200' },
  signed:                     { label: 'ลงนามแล้ว',     class: 'bg-teal-100 text-teal-800 border-teal-200' },
  completed:                  { label: 'เสร็จสิ้น',      class: 'bg-blue-100 text-blue-800 border-blue-200' },
  dispatched:                 { label: 'ส่งออกแล้ว',    class: 'bg-blue-100 text-blue-800 border-blue-200' },
  archived:                   { label: 'จัดเก็บแล้ว',    class: 'bg-slate-100 text-slate-700 border-slate-200' },
  rejected:                   { label: 'ไม่อนุมัติ',     class: 'bg-red-100 text-red-800 border-red-200' },
  cancelled:                  { label: 'ยกเลิก',        class: 'bg-red-100 text-red-800 border-red-200' },
  published:                  { label: 'เผยแพร่',       class: 'bg-indigo-100 text-indigo-800 border-indigo-200' },
}

/**
 * คืน { label, class } ของสถานะเอกสารสารบัญ
 * @param {string} status
 */
export function sarabanStatusConfig(status) {
  return SARABAN_STATUS_MAP[status?.toLowerCase()]
    ?? { label: status || '', class: 'bg-gray-100 text-gray-800 border-gray-200' }
}
