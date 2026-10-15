{{--
    แบบใบลาติดตามคู่สมรส (ระเบียบสำนักนายกรัฐมนตรีว่าด้วยการลาของข้าราชการ พ.ศ. 2555)

    ทุกค่ามาจากชุดตัวแปรกลาง `App\Services\LeaveDocumentFields`
    ข้อมูลคู่สมรสมาจากช่องเฉพาะแบบฟอร์ม (หน้าตั้งค่าประเภทการลา):
      level, spouse_name, spouse_position, spouse_level, spouse_affiliation, spouse_country
    การลาติดตามคู่สมรสครั้งก่อนมาจากประวัติการลาประเภทเดียวกันที่ได้รับอนุมัติ
--}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แบบใบลาติดตามคู่สมรส</title>
    @include('pdf.partials.form_styles')
</head>
<body>

@include('pdf.partials.form_header', ['formTitle' => 'แบบใบลาติดตามคู่สมรส', 'formSubject' => 'ขอลาติดตามคู่สมรส'])

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
        <td width="20%">ขอลาติดตามคู่สมรส ชื่อ</td>
        <td width="42%" class="dot-left">&nbsp;{{ $extra['spouse_name'] ?? '' }}</td>
        <td width="8%" class="text-center">ตำแหน่ง</td>
        <td width="30%" class="dot-left">&nbsp;{{ $extra['spouse_position'] ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="6%">ระดับ</td>
        <td width="24%" class="dot-left">&nbsp;{{ $extra['spouse_level'] ?? '' }}</td>
        <td width="7%" class="text-center">สังกัด</td>
        <td width="63%" class="dot-left">&nbsp;{{ $extra['spouse_affiliation'] ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="36%">ซึ่งไปปฏิบัติราชการ/ปฏิบัติงาน ณ ประเทศ</td>
        <td width="64%" class="dot-left">&nbsp;{{ $extra['spouse_country'] ?? '' }}</td>
    </tr>
</table>

<table class="row" style="margin-top: 4px;">
    <tr>
        <td width="7%">มีกำหนด</td>
        <td width="7%" class="dot">{{ $duration_years ?? '' }}</td>
        <td width="4%" class="text-center">ปี</td>
        <td width="7%" class="dot">{{ $duration_months ?? '' }}</td>
        <td width="6%" class="text-center">เดือน</td>
        <td width="7%" class="dot">{{ $duration_days ?? '' }}</td>
        <td width="4%" class="text-center">วัน</td>
        <td width="10%" class="text-center">ตั้งแต่วันที่</td>
        <td width="18%" class="dot">{{ $start_date_thai ?? '' }}</td>
        <td width="8%" class="text-center">ถึงวันที่</td>
        <td width="22%" class="dot">{{ $end_date_thai ?? '' }}</td>
    </tr>
</table>

<table class="row" style="margin-top: 4px;">
    <tr>
        <td width="30%">ข้าพเจ้าได้เคยลาติดตามคู่สมรส</td>
        <td width="9%">ตั้งแต่วันที่</td>
        <td width="24%" class="dot">{{ ($has_last_leave ?? false) ? $last_start_date_thai : '-' }}</td>
        <td width="8%" class="text-center">ถึงวันที่</td>
        <td width="29%" class="dot">{{ ($has_last_leave ?? false) ? $last_end_date_thai : '-' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="30%">ในระหว่างลาจะติดต่อข้าพเจ้าได้ที่</td>
        <td width="70%" class="dot-left">&nbsp;{{ $contact_address ?? '' }}</td>
    </tr>
    <tr>
        <td width="30%">หมายเลขโทรศัพท์</td>
        <td width="70%" class="dot-left">&nbsp;{{ $phone ?? '' }}</td>
    </tr>
</table>

<div class="text-center" style="margin-top: 12px;">ขอแสดงความนับถือ</div>
@include('pdf.partials.requester_signature')

@include('pdf.partials.commander_and_order')

@includeIf('pdf.partials.verification_qr')

</body>
</html>
