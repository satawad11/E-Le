{{--
    แบบใบลาไปประกอบพิธีฮัจย์ (ระเบียบสำนักนายกรัฐมนตรีว่าด้วยการลาของข้าราชการ พ.ศ. 2555)

    ทุกค่ามาจากชุดตัวแปรกลาง `App\Services\LeaveDocumentFields`
    "เคย/ยังไม่เคย" ใช้ช่องเฉพาะแบบฟอร์มคีย์ `hajj_before` ถ้าผู้ลาเลือกไว้
    ไม่เช่นนั้นดูจากประวัติการลาประเภทเดียวกันที่ได้รับอนุมัติ
--}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แบบใบลาไปประกอบพิธีฮัจย์</title>
    @include('pdf.partials.form_styles')
</head>
<body>

@php
    // true = เคย, false = ยังไม่เคย, null = ไม่ทราบ (ไม่ติ๊กช่องใด) — ดู LeaveDocumentFields::doneBefore()
    $hasBeen = $done_before ?? null;
@endphp

@include('pdf.partials.form_header', ['formTitle' => 'แบบใบลาไปประกอบพิธีฮัจย์ ณ เมืองเมกกะ ประเทศซาอุดีอาระเบีย', 'formSubject' => 'ขอลาไปประกอบพิธีฮัจย์'])

{{-- ถ้อยคำตามแบบท้ายระเบียบฯ พ.ศ. 2555 --}}
<table class="row" style="margin-top: 6px;">
    <tr>
        <td width="14%"></td>
        <td width="8%">ข้าพเจ้า</td>
        <td width="40%" class="dot-left">&nbsp;{{ $user_name ?? '' }}</td>
        <td width="8%" class="text-center">ตำแหน่ง</td>
        <td width="30%" class="dot-left">&nbsp;{{ $position ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="6%">ระดับ</td>
        <td width="24%" class="dot-left">&nbsp;{{ $level ?? '' }}</td>
        <td width="7%" class="text-center">สังกัด</td>
        <td width="63%" class="dot-left">&nbsp;{{ $affiliation_line ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="22%">เข้ารับราชการเมื่อวันที่</td>
        <td width="40%" class="dot">{{ $hired_date_thai ?? '' }}</td>
        <td width="38%"></td>
    </tr>
</table>

<div style="margin-top: 6px;">
    ข้าพเจ้า
    <span class="box {{ $hasBeen === false ? 'checked' : '' }}"></span>ยังไม่เคย
    &nbsp;&nbsp;
    <span class="box {{ $hasBeen === true ? 'checked' : '' }}"></span>เคย
    &nbsp;ไปประกอบพิธีฮัจย์ บัดนี้ มีศรัทธาจะไปประกอบพิธีฮัจย์
</div>
<div>ณ เมืองเมกกะ ประเทศซาอุดีอาระเบีย จึงขออนุญาตลาหยุดราชการ</div>
<table class="row">
    <tr>
        <td width="10%">ตั้งแต่วันที่</td>
        <td width="28%" class="dot">{{ $start_date_thai ?? '' }}</td>
        <td width="8%" class="text-center">ถึงวันที่</td>
        <td width="28%" class="dot">{{ $end_date_thai ?? '' }}</td>
        <td width="10%" class="text-center">มีกำหนด</td>
        <td width="8%" class="dot">{{ $total_days ?? '' }}</td>
        <td width="8%" class="text-center">วัน</td>
    </tr>
</table>

<div style="margin-top: 10px;"></div>
@include('pdf.partials.requester_signature')

@include('pdf.partials.commander_and_order')

@includeIf('pdf.partials.verification_qr')

</body>
</html>
