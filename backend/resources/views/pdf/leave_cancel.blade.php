{{--
    แบบใบขอยกเลิกวันลา

    ใช้ตอนพิมพ์ด้วย `?form=cancel` — ค่าทั้งหมดมาจาก `App\Services\LeaveDocumentFields`
--}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แบบใบขอยกเลิกวันลา</title>
    <style>
        @font-face {
            font-family: 'THSarabunNew';
            font-style: normal;
            font-weight: normal;
            src: url("{{ public_path('fonts/THSarabunNew/THSarabunNew.ttf') }}") format('truetype');
        }
        @font-face {
            font-family: 'THSarabunNew';
            font-style: normal;
            font-weight: bold;
            src: url("{{ public_path('fonts/THSarabunNew/THSarabunNew Bold.ttf') }}") format('truetype');
        }
        @page { margin: 8mm 14mm; size: A4 portrait; }
        body { font-family: 'THSarabunNew', sans-serif; font-size: 14pt; line-height: 1.1; color: #000; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .underline { text-decoration: underline; }
        table.row { width: 100%; border-collapse: collapse; }
        table.row td { vertical-align: bottom; padding: 1px 0; white-space: nowrap; }
        td.dot { border-bottom: 1px dotted #000; text-align: center; }
        td.dot-left { border-bottom: 1px dotted #000; text-align: left; white-space: normal; }
        .box { display: inline-block; width: 10px; height: 10px; border: 1px solid #000; margin-right: 3px; }
        .checked { background-color: #000; }
        .sign-img { height: 32px; }
    </style>
</head>
<body>

@php
    $blocks = collect($approverBlocks ?? []);
    $order = $blocks->last();
    $commander = $blocks->filter(fn ($b) => $b !== $order)->last() ?? $order;
@endphp

<div class="text-center bold underline" style="font-size: 18pt;">แบบใบขอยกเลิกวันลา</div>

<table class="row" style="margin-top: 6px;">
    <tr>
        <td width="48%"></td>
        <td width="12%" class="text-right">(เขียนที่)&nbsp;</td>
        <td width="40%" class="dot">{{ $written_at ?? '' }}</td>
    </tr>
    <tr>
        <td width="48%"></td>
        <td width="7%" class="text-right">วันที่&nbsp;</td>
        <td width="8%" class="dot">{{ $doc_day ?? '' }}</td>
        <td width="6%" class="text-center">เดือน</td>
        <td width="18%" class="dot">{{ $doc_month ?? '' }}</td>
        <td width="5%" class="text-center">พ.ศ.</td>
        <td width="8%" class="dot">{{ $doc_year ?? '' }}</td>
    </tr>
</table>

<table class="row" style="margin-top: 6px;">
    <tr>
        <td width="7%" class="bold">เรื่อง</td>
        <td width="93%">ขอยกเลิกวันลา</td>
    </tr>
    <tr>
        <td width="7%" class="bold">เรียน</td>
        <td width="93%">{{ $director_position ?? '' }}</td>
    </tr>
</table>

<table class="row" style="margin-top: 6px;">
    <tr>
        <td width="14%"></td>
        <td width="12%">ตามที่ข้าพเจ้า</td>
        <td width="36%" class="dot-left">&nbsp;{{ $user_name ?? '' }}</td>
        <td width="8%" class="text-center">ตำแหน่ง</td>
        <td width="30%" class="dot-left">&nbsp;{{ $position ?? '' }}</td>
    </tr>
    <tr>
        <td width="10%">แผนก/งาน</td>
        <td width="50%" class="dot-left">&nbsp;{{ $division ?? '' }}</td>
        <td width="7%" class="text-center">สังกัด</td>
        <td width="33%" class="dot-left">&nbsp;{{ trim(($school_name ?? '').' '.($school_affiliation ?? '')) }}</td>
    </tr>
    <tr>
        <td width="14%">ได้รับอนุญาตให้ลา</td>
        <td width="86%" class="dot-left" colspan="4">&nbsp;{{ $leave_type ?? '' }}</td>
    </tr>
</table>

<table class="row">
    <tr>
        <td width="10%">ตั้งแต่วันที่</td>
        <td width="27%" class="dot">{{ $start_date_thai ?? '' }}</td>
        <td width="8%" class="text-center">ถึงวันที่</td>
        <td width="27%" class="dot">{{ $end_date_thai ?? '' }}</td>
        <td width="6%" class="text-center">รวม</td>
        <td width="12%" class="dot">{{ $total_days ?? '' }}</td>
        <td width="10%" class="text-center">วัน นั้น</td>
    </tr>
</table>

<table class="row" style="margin-top: 6px;">
    <tr>
        <td width="14%"></td>
        <td width="9%">เนื่องจาก</td>
        <td width="77%" class="dot-left">&nbsp;{{ $cancel_reason ?? '' }}</td>
    </tr>
    <tr>
        <td width="100%" class="dot-left" colspan="3">&nbsp;</td>
    </tr>
    <tr>
        <td width="14%">จึงขอยกเลิกวันลา</td>
        <td width="63%" class="dot-left">&nbsp;{{ $cancelled_leave_text ?? '' }}</td>
        <td width="8%" class="text-center">จำนวน</td>
        <td width="8%" class="dot">{{ $cancel_days ?? '' }}</td>
        <td width="7%" class="text-center">วัน</td>
    </tr>
</table>

<div class="text-center" style="margin-top: 8px;">ขอแสดงความนับถือ</div>
<div class="text-center" style="margin-top: 6px;">
    (ลงชื่อ)
    <span style="border-bottom: 1px dotted #000; display: inline-block; width: 220px; text-align: center;">{{ $user_name ?? '' }}</span>
</div>
<div class="text-center">( {{ $user_name ?? '' }} )</div>

<div class="bold underline" style="margin-top: 8px;">ความเห็นผู้บังคับบัญชา</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">{{ $commander['comment'] ?? '' }}</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>
<div class="text-center" style="margin-top: 6px;">
    (ลงชื่อ)
    @if (! empty($commander['signature']))
        <img class="sign-img" src="{{ $commander['signature'] }}" alt="">
    @endif
    <span style="border-bottom: 1px dotted #000; display: inline-block; width: 220px;">&nbsp;</span>
</div>
<div class="text-center">( {{ $commander['name'] ?? '' }} )</div>
<div class="text-center">วันที่ {{ ! empty($commander['date']) ? $commander['date']->format('d/m/').($commander['date']->year + 543) : '........./........./.........' }}</div>

<div class="bold underline" style="margin-top: 8px;">คำสั่ง</div>
<div style="margin-left: 40px;">
    <span class="box {{ ($order['status'] ?? '') === 'Approved' ? 'checked' : '' }}"></span>อนุญาต
    &nbsp;&nbsp;&nbsp;&nbsp;
    <span class="box {{ ($order['status'] ?? '') === 'Rejected' ? 'checked' : '' }}"></span>ไม่อนุญาต
</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">{{ $order['comment'] ?? '' }}</div>
<div class="text-center" style="margin-top: 6px;">
    (ลงชื่อ)
    @if (! empty($order['signature']))
        <img class="sign-img" src="{{ $order['signature'] }}" alt="">
    @endif
    <span style="border-bottom: 1px dotted #000; display: inline-block; width: 220px;">&nbsp;</span>
</div>
<div class="text-center">( {{ $order['name'] ?? '' }} )</div>
<div class="text-center">{{ $order['position'] ?? ($director_position ?? '') }}</div>
<div class="text-center">วันที่ {{ ! empty($order['date']) ? $order['date']->format('d/m/').($order['date']->year + 543) : '........./........./.........' }}</div>

@includeIf('pdf.partials.verification_qr')

</body>
</html>
