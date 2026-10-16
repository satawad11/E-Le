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
        <td width="22%">ได้รับเงินเดือนเดือนละ</td>
        <td width="22%" class="dot">{{ $extra['salary'] ?? '' }}</td>
        <td width="6%" class="text-center">บาท</td>
        <td width="50%"></td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="32%">มีความประสงค์ขอลาติดตามคู่สมรส ชื่อ</td>
        <td width="68%" class="dot-left">&nbsp;{{ $extra['spouse_name'] ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="8%">ตำแหน่ง</td>
        <td width="30%" class="dot-left">&nbsp;{{ $extra['spouse_position'] ?? '' }}</td>
        <td width="6%" class="text-center">ระดับ</td>
        <td width="16%" class="dot-left">&nbsp;{{ $extra['spouse_level'] ?? '' }}</td>
        <td width="7%" class="text-center">สังกัด</td>
        <td width="33%" class="dot-left">&nbsp;{{ $extra['spouse_affiliation'] ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="38%">ซึ่งไปปฏิบัติราชการ/ปฏิบัติงาน ณ ประเทศ</td>
        <td width="62%" class="dot-left">&nbsp;{{ $extra['spouse_country'] ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="8%">มีกำหนด</td>
        <td width="26%" class="dot-left">&nbsp;{{ $extra['spouse_posting_period'] ?? '' }}</td>
        <td width="34%" class="text-center">จึงขออนุญาตลาติดตามคู่สมรสมีกำหนด</td>
        <td width="6%" class="dot">{{ $duration_years ?? '' }}</td>
        <td width="4%" class="text-center">ปี</td>
        <td width="6%" class="dot">{{ $duration_months ?? '' }}</td>
        <td width="6%" class="text-center">เดือน</td>
        <td width="5%" class="dot">{{ $duration_days ?? '' }}</td>
        <td width="5%" class="text-center">วัน</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="10%">ตั้งแต่วันที่</td>
        <td width="38%" class="dot">{{ $start_date_thai ?? '' }}</td>
        <td width="8%" class="text-center">ถึงวันที่</td>
        <td width="44%" class="dot">{{ $end_date_thai ?? '' }}</td>
    </tr>
</table>

<table class="row" style="margin-top: 6px;">
    <tr>
        <td width="14%"></td>
        <td width="40%">ครั้งสุดท้ายข้าพเจ้าได้ลาติดตามคู่สมรสไปประเทศ</td>
        <td width="46%" class="dot-left">&nbsp;{{ ($has_last_leave ?? false) ? ($extra['previous_country'] ?? '') : '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="8%">เมื่อวันที่</td>
        <td width="38%" class="dot">{{ ($has_last_leave ?? false) ? $last_start_date_thai : '' }}</td>
        <td width="8%" class="text-center">ถึงวันที่</td>
        <td width="46%" class="dot">{{ ($has_last_leave ?? false) ? $last_end_date_thai : '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="46%">ในกรณีลาติดต่อกับครั้งก่อน รวมทั้งครั้งนี้ด้วย เป็นเวลา</td>
        <td width="54%" class="dot-left">&nbsp;{{ $extra['total_with_previous'] ?? '' }}</td>
    </tr>
</table>

<div style="margin-top: 8px;"></div>
@include('pdf.partials.requester_signature')

@include('pdf.partials.commander_and_order')

@includeIf('pdf.partials.verification_qr')

</body>
</html>
