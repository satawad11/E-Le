{{--
    บรรทัด "รักษาราชการแทน / ปฏิบัติราชการแทน <ตำแหน่ง>" ใต้ชื่อ/ตำแหน่งผู้ลงนาม (DELEGATION-01)

    แสดงเฉพาะขั้นที่ผู้ทำหน้าที่แทนลงนาม — ค่ามาจาก snapshot ของขั้นอนุมัติ (LeaveApproverBlocks)
    ตัวแปร: $block (บล็อกผู้ลงนาม), $boxStyle (สไตล์ของ div ให้ตรงกับบรรทัดข้างเคียง)
--}}
@if (($block['acting_line'] ?? '') !== '')
<div class="text-center" style="font-size: 12pt; line-height: 1; {{ $boxStyle ?? '' }}"><span style="display: inline-block; width: 250px; height: 13pt; line-height: 1; vertical-align: top; text-align: center;">{{ $block['acting_line'] }}</span></div>
@endif
