{{--
    แบบใบลาป่วย ลาคลอดบุตร ลากิจส่วนตัว

    ทุกค่าที่เติมลงช่องว่างมาจากชุดตัวแปรกลาง `App\Services\LeaveDocumentFields`
    ไม่มีชื่อสถานศึกษาหรือชื่อผู้บริหารเขียนตายในไฟล์ — ทั้งหมดมาจากหน้าตั้งค่าระบบ

    ไฟล์นี้คือแบบฟอร์มที่พิมพ์จริง (TPL-SEC-01: ระบบไม่เรนเดอร์ HTML จากฐานข้อมูล)
    ผู้ดูแลที่ต้องการปรับแบบฟอร์มให้ใช้ Document Engine วางฟิลด์บน PDF ต้นฉบับ
--}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>{{ $subject ?? 'แบบใบลา' }}</title>
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
        body { font-family: 'THSarabunNew', sans-serif; font-size: 14pt; line-height: 1.05; color: #000; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .underline { text-decoration: underline; }
        table.row { width: 100%; border-collapse: collapse; }
        table.row td { vertical-align: bottom; padding: 1px 0; white-space: nowrap; }
        td.dot { border-bottom: 1px dotted #000; text-align: center; }
        td.dot-left { border-bottom: 1px dotted #000; text-align: left; padding-left: 4px; white-space: normal; }
        .circle { display: inline-block; width: 10px; height: 10px; border: 1px solid #000; border-radius: 50%; margin-right: 3px; }
        .box { display: inline-block; width: 10px; height: 10px; border: 1px solid #000; margin-right: 3px; }
        .checked { background-color: #000; }
        table.stats { border-collapse: collapse; font-size: 13pt; width: 100%; }
        table.stats th, table.stats td { border: 1px solid #000; padding: 2px 4px; text-align: center; }
        .sign-img { height: 32px; }
        .fill { display: inline-block; border-bottom: 1px dotted #000; text-align: center; vertical-align: bottom; min-height: 16px; }
        /* สองคอลัมน์ล่างใช้ float แทนตาราง — dompdf ตัดแถวตารางข้ามหน้าไม่ได้ จึงดันทั้งส่วนไปหน้า 2 */
        .panel-left { float: left; width: 45%; }
        .panel-right { float: right; width: 50%; }
        .clear { clear: both; }
    </style>
</head>
<body>

@php
    // ผู้ตรวจสอบ / ผู้บังคับบัญชา / ผู้สั่งการ — เรียงตามลำดับขั้นอนุมัติที่ตั้งค่าไว้
    $blocks = collect($approverBlocks ?? []);
    $checker = $blocks->first(fn ($b) => \App\Services\DocumentEngine\LeaveFormFieldMap::isChecker($b)) ?? $blocks->first();
    $order = $blocks->last();
    $commander = $blocks->filter(fn ($b) => $b !== $checker && $b !== $order)->first() ?? $order;
@endphp

<div class="text-center bold underline" style="font-size: 18pt;">แบบใบลาป่วย ลาคลอดบุตร ลากิจส่วนตัว</div>

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
        <td width="93%" class="dot-left">&nbsp;{{ $subject ?? '' }}</td>
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
        <td width="78%" class="dot-left">&nbsp;{{ $user_name ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="8%">ตำแหน่ง</td>
        <td width="47%" class="dot-left">&nbsp;{{ $position ?? '' }}</td>
        <td width="10%" class="text-center">แผนก/งาน</td>
        <td width="35%" class="dot-left">&nbsp;{{ $division ?? '' }}</td>
    </tr>
    <tr>
        <td width="6%">สังกัด</td>
        <td width="94%" class="dot-left">&nbsp;{{ $organization_line ?? '' }}</td>
    </tr>
</table>

<table class="row" style="margin-top: 4px;">
    <tr>
        <td width="8%">ขอลา</td>
        <td width="30%">
            <span class="circle {{ ($is_sick ?? false) ? 'checked' : '' }}"></span>ป่วย&nbsp;
            <span class="circle {{ ($is_personal ?? false) ? 'checked' : '' }}"></span>กิจส่วนตัว&nbsp;
            <span class="circle {{ ($is_maternity ?? false) ? 'checked' : '' }}"></span>คลอดบุตร
        </td>
        <td width="9%" class="text-center">เนื่องจาก</td>
        <td width="53%" class="dot-left">&nbsp;{{ $reason ?? '' }}</td>
    </tr>
</table>

<table class="row" style="margin-top: 4px;">
    <tr>
        <td width="10%">ตั้งแต่วันที่</td>
        <td width="26%" class="dot">{{ $start_date_thai ?? '' }}</td>
        <td width="8%" class="text-center">ถึงวันที่</td>
        <td width="26%" class="dot">{{ $end_date_thai ?? '' }}</td>
        <td width="10%" class="text-center">มีกำหนด</td>
        <td width="12%" class="dot">{{ $total_days ?? '' }}</td>
        <td width="8%">&nbsp;วัน</td>
    </tr>
</table>

<table class="row" style="margin-top: 4px;">
    <tr>
        <td width="13%">ข้าพเจ้าได้ลา</td>
        <td width="40%">
            <span class="box {{ ($is_sick ?? false) ? 'checked' : '' }}"></span>ป่วย&nbsp;
            <span class="box {{ ($is_personal ?? false) ? 'checked' : '' }}"></span>กิจส่วนตัว&nbsp;
            <span class="box {{ ($is_maternity ?? false) ? 'checked' : '' }}"></span>คลอดบุตร
        </td>
        <td width="17%" class="text-right">ครั้งสุดท้ายตั้งแต่วันที่&nbsp;</td>
        <td width="30%" class="dot">{{ ($has_last_leave ?? false) ? $last_start_date_thai : '-' }}</td>
    </tr>
    <tr>
        <td width="7%">ถึงวันที่</td>
        <td width="33%" class="dot">{{ ($has_last_leave ?? false) ? $last_end_date_thai : '-' }}</td>
        <td width="10%" class="text-center">มีกำหนด</td>
        <td width="12%" class="dot">{{ ($has_last_leave ?? false) ? $last_total_days : '-' }}</td>
        <td width="38%">&nbsp;วัน</td>
    </tr>
</table>

<table class="row" style="margin-top: 4px;">
    <tr>
        <td width="24%">ในระหว่างลาจะติดต่อข้าพเจ้าได้ที่</td>
        <td width="76%" class="dot-left">&nbsp;{{ $contact_address ?? '' }}</td>
    </tr>
    <tr>
        <td width="10%">โทรศัพท์</td>
        <td width="90%" class="dot-left">&nbsp;{{ $phone ?? '' }}</td>
    </tr>
</table>

<div style="margin-top: 5px; margin-left: 40px;">จึงเรียนมาเพื่อโปรดพิจารณา</div>

<div style="margin-top: 6px;">
        <div class="panel-left">
            <div class="text-center bold underline">สถิติการลาในปีงบประมาณนี้</div>
            <table class="stats" style="margin-top: 4px;">
                <tr>
                    <th style="width: 28%;">ประเภทลา</th>
                    <th>ลามาแล้ว<br>(วัน/ครั้ง)</th>
                    <th>ลาครั้งนี้<br>(วัน/ครั้ง)</th>
                    <th>รวมเป็น<br>(วัน/ครั้ง)</th>
                </tr>
                @php
                    // ชื่อประเภทการลาตั้งได้จากหน้าผู้ดูแล จึงจับคู่แถวสถิติด้วยคำสำคัญแทนชื่อเต็ม
                    $statRow = function (string $needle) use ($stats) {
                        foreach ($stats ?? [] as $name => $row) {
                            if (str_contains($name, $needle)) {
                                return $row;
                            }
                        }

                        return [];
                    };
                @endphp
                @foreach (['ป่วย', 'กิจส่วนตัว', 'คลอดบุตร'] as $label)
                    @php $row = $statRow($label === 'กิจส่วนตัว' ? 'กิจ' : $label); @endphp
                    <tr>
                        <td>{{ $label }}<br>(วันทำการ)</td>
                        <td>{{ $row['before'] ?? '' }}{{ ($row['times_before'] ?? '') !== '' ? ' / '.$row['times_before'] : '' }}</td>
                        <td>{{ $row['current'] ?? '' }}{{ ($row['times_current'] ?? '') !== '' ? ' / '.$row['times_current'] : '' }}</td>
                        <td>{{ $row['total'] ?? '' }}{{ ($row['times_total'] ?? '') !== '' ? ' / '.$row['times_total'] : '' }}</td>
                    </tr>
                @endforeach
            </table>

            <div style="margin-top: 12px;">
                (ลงชื่อ)
                @if (! empty($checker['signature']))
                    <img class="sign-img" src="{{ $checker['signature'] }}" alt="">
                @endif
                <span style="border-bottom: 1px dotted #000; display: inline-block; width: 150px;">&nbsp;</span> ผู้ตรวจสอบ
            </div>
            <div class="text-center" style="width: 260px;">(<span class="fill" style="width: 190px;">{{ $checker['name'] ?? '' }}</span>)</div>
            @include('pdf.partials.acting_line', ['block' => $checker, 'boxStyle' => 'width: 260px;'])
            <div class="text-center" style="width: 260px;">วันที่ <span class="fill" style="width: 110px;">{{ \App\Support\FormDate::short($checker['date'] ?? null) }}</span></div>
        </div>
        <div class="panel-right">
            <div class="text-center">ขอแสดงความนับถือ</div>
            @include('pdf.partials.requester_signature', ['signWidth' => 190])

            <div class="bold underline" style="margin-top: 10px;">ความเห็นผู้บังคับบัญชา</div>
            <div style="border-bottom: 1px dotted #000; min-height: 18px;">{{ $commander['comment'] ?? '' }}</div>
                        <div style="margin-top: 10px;">
                (ลงชื่อ)
                @if (! empty($commander['signature']))
                    <img class="sign-img" src="{{ $commander['signature'] }}" alt="">
                @endif
                <span style="border-bottom: 1px dotted #000; display: inline-block; width: 170px; text-align: center;">&nbsp;</span>
            </div>
            <div class="text-center" style="width: 280px;">(<span class="fill" style="width: 190px;">{{ $commander['name'] ?? '' }}</span>)</div>
            @include('pdf.partials.acting_line', ['block' => $commander, 'boxStyle' => 'width: 280px;'])
            <div class="text-center" style="width: 280px;">วันที่ <span class="fill" style="width: 110px;">{{ \App\Support\FormDate::short($commander['date'] ?? null) }}</span></div>

            <div class="bold underline" style="margin-top: 8px;">คำสั่ง</div>
            <div style="margin-left: 20px;">
                <span class="circle {{ ($order['status'] ?? '') === 'Approved' ? 'checked' : '' }}"></span>อนุญาต
                &nbsp;&nbsp;&nbsp;
                <span class="circle {{ ($order['status'] ?? '') === 'Rejected' ? 'checked' : '' }}"></span>ไม่อนุญาต
            </div>
            <div style="border-bottom: 1px dotted #000; min-height: 18px;">{{ $order['comment'] ?? '' }}</div>
            <div style="margin-top: 10px;">
                (ลงชื่อ)
                @if (! empty($order['signature']))
                    <img class="sign-img" src="{{ $order['signature'] }}" alt="">
                @endif
                <span style="border-bottom: 1px dotted #000; display: inline-block; width: 170px; text-align: center;">&nbsp;</span>
            </div>
            <div class="text-center" style="width: 280px;">(<span class="fill" style="width: 190px;">{{ $order['name'] ?? '' }}</span>)</div>
            <div class="text-center" style="width: 280px;"><span class="fill" style="width: 190px;">{{ $order['position'] ?? ($director_position ?? '') }}</span></div>
            @include('pdf.partials.acting_line', ['block' => $order, 'boxStyle' => 'width: 280px;'])
            <div class="text-center" style="width: 280px;">วันที่ <span class="fill" style="width: 110px;">{{ \App\Support\FormDate::short($order['date'] ?? null) }}</span></div>
        </div>
        <div class="clear"></div>
</div>

@includeIf('pdf.partials.verification_qr')

</body>
</html>
