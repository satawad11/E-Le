{{--
    แบบใบลาพักผ่อน

    ค่าทั้งหมดมาจากชุดตัวแปรกลาง `App\Services\LeaveDocumentFields`
    ชื่อสถานศึกษา/ผู้บริหารมาจากหน้าตั้งค่าระบบ ไม่มีการเขียนตายในไฟล์
--}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แบบใบลาพักผ่อน</title>
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
        @page { margin: 8mm 12mm; size: A4 portrait; }
        body { font-family: 'THSarabunNew', sans-serif; font-size: 14pt; line-height: 1.15; color: #000; }
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
        table.stats { border-collapse: collapse; font-size: 13pt; }
        table.stats th, table.stats td { border: 1px solid #000; padding: 3px 8px; text-align: center; }
        .sign-img { height: 32px; }
        .panel { width: 100%; border-collapse: collapse; }
    </style>
</head>
<body>

@php
    $blocks = collect($approverBlocks ?? []);
    $checker = $blocks->firstWhere('role_name', 'HR Head') ?? $blocks->first();
    $order = $blocks->last();
    $commander = $blocks->filter(fn ($b) => $b !== $checker && $b !== $order)->first() ?? $order;
    $statRow = collect($stats ?? [])->first(fn ($row, $name) => str_contains((string) $name, 'พักผ่อน')) ?? [];
@endphp

<div class="text-center bold underline" style="font-size: 18pt;">แบบใบลาพักผ่อน</div>

<table class="row" style="margin-top: 5px;">
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

<table class="row" style="margin-top: 4px;">
    <tr>
        <td width="7%" class="bold">เรื่อง</td>
        <td width="93%">ขอลาพักผ่อน</td>
    </tr>
    <tr>
        <td width="7%" class="bold">เรียน</td>
        <td width="93%">{{ $director_position ?? '' }}</td>
    </tr>
</table>

<table class="row" style="margin-top: 4px;">
    <tr>
        <td width="14%"></td>
        <td width="8%">ข้าพเจ้า</td>
        <td width="40%" class="dot-left">&nbsp;{{ $user_name ?? '' }}</td>
        <td width="8%" class="text-center">ตำแหน่ง</td>
        <td width="30%" class="dot-left">&nbsp;{{ $position ?? '' }}</td>
    </tr>
    <tr>
        <td width="10%">แผนก/งาน</td>
        <td width="50%" class="dot-left">&nbsp;{{ $division ?? '' }}</td>
        <td width="7%" class="text-center">สังกัด</td>
        <td width="33%" class="dot-left">&nbsp;{{ trim(($school_name ?? '').' '.($school_affiliation ?? '')) }}</td>
    </tr>
</table>

<table class="row" style="margin-top: 4px;">
    <tr>
        <td width="20%">มีวันลาพักผ่อนสะสม</td>
        <td width="9%" class="dot">{{ $vacation_accumulated ?? '' }}</td>
        <td width="27%" class="text-center">วันทำการ มีสิทธิลาพักผ่อนประจำปีนี้อีก</td>
        <td width="9%" class="dot">{{ $vacation_entitled ?? '' }}</td>
        <td width="17%" class="text-center">วันทำการ รวมเป็น</td>
        <td width="9%" class="dot">{{ $vacation_total ?? '' }}</td>
        <td width="9%" class="text-center">วันทำการ</td>
    </tr>
</table>

<table class="row" style="margin-top: 4px;">
    <tr>
        <td width="17%">ขอลาพักผ่อนตั้งแต่วันที่</td>
        <td width="23%" class="dot">{{ $start_date_thai ?? '' }}</td>
        <td width="8%" class="text-center">ถึงวันที่</td>
        <td width="23%" class="dot">{{ $end_date_thai ?? '' }}</td>
        <td width="10%" class="text-center">มีกำหนด</td>
        <td width="11%" class="dot">{{ $total_days ?? '' }}</td>
        <td width="8%" class="text-center">วัน</td>
    </tr>
    <tr>
        <td width="24%">ในระหว่างลาจะติดต่อข้าพเจ้าได้ที่</td>
        <td width="76%" class="dot-left" colspan="6">&nbsp;{{ $contact_address ?? '' }}</td>
    </tr>
</table>

<table class="panel" style="margin-top: 10px;">
    <tr>
        <td width="45%" style="vertical-align: top;">
            <div class="text-center bold underline">สถิติการลาในปีงบประมาณนี้</div>
            <table class="stats" style="margin: 4px auto 0 auto;">
                <tr>
                    <th>ลามาแล้ว<br>(วันทำการ)</th>
                    <th>ลาครั้งนี้<br>(วันทำการ)</th>
                    <th>รวมเป็น<br>(วันทำการ)</th>
                </tr>
                <tr>
                    <td style="height: 30px;">{{ $statRow['before'] ?? '' }}</td>
                    <td>{{ $statRow['current'] ?? '' }}</td>
                    <td>{{ $statRow['total'] ?? '' }}</td>
                </tr>
            </table>

            <div style="margin-top: 24px;">
                (ลงชื่อ)
                @if (! empty($checker['signature']))
                    <img class="sign-img" src="{{ $checker['signature'] }}" alt="">
                @endif
                <span style="border-bottom: 1px dotted #000; display: inline-block; width: 140px;">&nbsp;</span> ผู้ตรวจสอบ
            </div>
            <div class="text-center" style="width: 260px;">( {{ $checker['name'] ?? '' }} )</div>
            <div class="text-center" style="width: 260px;">วันที่ {{ ! empty($checker['date']) ? $checker['date']->format('d/m/').($checker['date']->year + 543) : '........./........./.........' }}</div>
        </td>
        <td width="10%"></td>
        <td width="45%" style="vertical-align: top;">
            <div class="text-center">ขอแสดงความนับถือ</div>
            @include('pdf.partials.requester_signature', ['signWidth' => 190])

            <div class="bold underline" style="margin-top: 10px;">ความเห็นผู้บังคับบัญชา</div>
            <div style="border-bottom: 1px dotted #000; min-height: 18px;">{{ $commander['comment'] ?? '' }}</div>
            <div style="margin-top: 8px;">
                (ลงชื่อ)
                @if (! empty($commander['signature']))
                    <img class="sign-img" src="{{ $commander['signature'] }}" alt="">
                @endif
                <span style="border-bottom: 1px dotted #000; display: inline-block; width: 170px;">&nbsp;</span>
            </div>
            <div class="text-center" style="width: 280px;">( {{ $commander['name'] ?? '' }} )</div>
            <div class="text-center" style="width: 280px;">วันที่ {{ ! empty($commander['date']) ? $commander['date']->format('d/m/').($commander['date']->year + 543) : '........./........./.........' }}</div>

            <div class="bold underline" style="margin-top: 8px;">คำสั่ง</div>
            <div style="margin-left: 20px;">
                <span class="box {{ ($order['status'] ?? '') === 'Approved' ? 'checked' : '' }}"></span>อนุญาต
                &nbsp;&nbsp;&nbsp;
                <span class="box {{ ($order['status'] ?? '') === 'Rejected' ? 'checked' : '' }}"></span>ไม่อนุญาต
            </div>
            <div style="border-bottom: 1px dotted #000; min-height: 18px;">{{ $order['comment'] ?? '' }}</div>
            <div style="margin-top: 8px;">
                (ลงชื่อ)
                @if (! empty($order['signature']))
                    <img class="sign-img" src="{{ $order['signature'] }}" alt="">
                @endif
                <span style="border-bottom: 1px dotted #000; display: inline-block; width: 170px;">&nbsp;</span>
            </div>
            <div class="text-center" style="width: 280px;">( {{ $order['name'] ?? '' }} )</div>
            <div class="text-center" style="width: 280px;">{{ $order['position'] ?? ($director_position ?? '') }}</div>
            <div class="text-center" style="width: 280px;">วันที่ {{ ! empty($order['date']) ? $order['date']->format('d/m/').($order['date']->year + 543) : '........./........./.........' }}</div>
        </td>
    </tr>
</table>

@includeIf('pdf.partials.verification_qr')

</body>
</html>
