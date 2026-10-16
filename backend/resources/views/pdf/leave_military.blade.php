{{--
    รายงานลาเข้ารับการตรวจเลือกหรือเข้ารับการเตรียมพล
    (ระเบียบสำนักนายกรัฐมนตรีว่าด้วยการลาของข้าราชการ พ.ศ. 2555 — ลาประเภทนี้ "รายงานลา" ต่อผู้บังคับบัญชา
    ไม่ต้องรออนุญาต จึงไม่มีช่องคำสั่งอนุญาต/ไม่อนุญาต มีเพียงช่องรับทราบของผู้บังคับบัญชา)

    ทุกค่ามาจากชุดตัวแปรกลาง `App\Services\LeaveDocumentFields`
    รายละเอียดหมายเรียกมาจากช่องเฉพาะแบบฟอร์ม (หน้าตั้งค่าประเภทการลา):
      level, call_kind, summons_issuer, summons_number, summons_date, report_place
--}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายงานลาเข้ารับการตรวจเลือกหรือเข้ารับการเตรียมพล</title>
    @include('pdf.partials.form_styles')
</head>
<body>

@php
    $kind = (string) ($extra['call_kind'] ?? '');
@endphp

@include('pdf.partials.form_header', [
    'formTitle' => 'แบบรายงานลาเข้ารับการตรวจเลือก หรือเข้ารับการเตรียมพล',
    'formSubject' => 'รายงานลา'.($kind !== '' ? $kind : 'เข้ารับการตรวจเลือกหรือเข้ารับการเตรียมพล'),
])

{{-- ถ้อยคำตามแบบท้ายระเบียบฯ พ.ศ. 2555 — เป็นการรายงาน ไม่ใช่การขออนุญาต --}}
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
        <td width="20%">ได้รับหมายเรียกของ</td>
        <td width="80%" class="dot-left">&nbsp;{{ $extra['summons_issuer'] ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="5%">ที่</td>
        <td width="40%" class="dot-left">&nbsp;{{ $extra['summons_number'] ?? '' }}</td>
        <td width="8%" class="text-center">ลงวันที่</td>
        <td width="47%" class="dot-left">&nbsp;{{ $extra['summons_date'] ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="4%">ให้</td>
        <td width="36%" class="dot-left">&nbsp;{{ $kind }}</td>
        <td width="7%" class="text-center">ณ ที่</td>
        <td width="53%" class="dot-left">&nbsp;{{ $extra['report_place'] ?? '' }}</td>
    </tr>
</table>
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

<div style="margin-top: 6px; text-indent: 14%;">จึงรายงานมาเพื่อโปรดทราบ</div>
@include('pdf.partials.requester_signature')

{{-- แบบท้ายระเบียบไม่มีส่วนผู้บังคับบัญชา — ระบบเพิ่มช่อง "รับทราบ" ไว้ใต้รายงานสำหรับสายอนุมัติ --}}
@php
    $signBlocks = collect($approverBlocks ?? []);
    $acknowledger = $signBlocks->last();
@endphp
<div class="bold underline" style="margin-top: 10px;">ผู้บังคับบัญชารับทราบ</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">{{ $acknowledger['comment'] ?? '' }}</div>
<div class="text-center" style="margin-top: 8px;">
    (ลงชื่อ)
    @if (! empty($acknowledger['signature']))
        <img class="sign-img" src="{{ $acknowledger['signature'] }}" alt="">
    @endif
    <span style="border-bottom: 1px dotted #000; display: inline-block; width: 220px;">&nbsp;</span>
</div>
<div class="text-center">(<span class="fill" style="width: 190px;">{{ $acknowledger['name'] ?? '' }}</span>)</div>
<div class="text-center">ตำแหน่ง <span class="fill" style="width: 190px;">{{ $acknowledger['position'] ?? ($director_position ?? '') }}</span></div>
@include('pdf.partials.acting_line', ['block' => $acknowledger])
<div class="text-center">วันที่ <span class="fill" style="width: 110px;">{{ \App\Support\FormDate::short($acknowledger['date'] ?? null) }}</span></div>

@includeIf('pdf.partials.verification_qr')

</body>
</html>
