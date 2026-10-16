{{--
    แบบใบลาไปศึกษา ฝึกอบรม ปฏิบัติการวิจัย หรือดูงาน
    (ระเบียบสำนักนายกรัฐมนตรีว่าด้วยการลาของข้าราชการ พ.ศ. 2555)

    ทุกค่ามาจากชุดตัวแปรกลาง `App\Services\LeaveDocumentFields`
    รายละเอียดหลักสูตรมาจากช่องเฉพาะแบบฟอร์ม (หน้าตั้งค่าประเภทการลา):
      level, study_kind, course, degree, institution, country, funding
--}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แบบใบลาไปศึกษา ฝึกอบรม ปฏิบัติการวิจัย หรือดูงาน</title>
    @include('pdf.partials.form_styles')
    {{-- แบบท้ายระเบียบมีหลายบรรทัดกว่าแบบอื่น — ขอบกระดาษแคบลงเล็กน้อยให้อยู่ในหน้าเดียว --}}
    <style>@page { margin: 6mm 14mm; } body { font-size: 13pt; } table.row td { padding: 0; }</style>
</head>
<body>

@php
    $kind = (string) ($extra['study_kind'] ?? '');
@endphp

@include('pdf.partials.form_header', [
    'formTitle' => 'แบบใบลาไปศึกษา ฝึกอบรม ปฏิบัติการวิจัย หรือดูงาน',
    'formSubject' => 'ขอลาไป'.($kind !== '' ? $kind : 'ศึกษา ฝึกอบรม ปฏิบัติการวิจัย หรือดูงาน'),
])

{{-- ถ้อยคำตามแบบท้ายระเบียบฯ พ.ศ. 2555 — ช่อง "ศึกษาวิชา" กับ "ฝึกอบรม ปฏิบัติการวิจัย ดูงาน" แยกกัน ติ๊กตามที่ผู้ลาเลือก --}}
@php($isStudy = $kind === 'ศึกษา')
@php($isOther = in_array($kind, ['ฝึกอบรม', 'ปฏิบัติการวิจัย', 'ดูงาน'], true))
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
        <td width="18%" class="dot-left">&nbsp;{{ $level ?? '' }}</td>
        <td width="7%" class="text-center">สังกัด</td>
        <td width="35%" class="dot-left">&nbsp;{{ $affiliation_line ?? '' }}</td>
        <td width="9%" class="text-center">เกิดวันที่</td>
        <td width="25%" class="dot">{{ $birth_date_thai ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="21%">เข้ารับราชการเมื่อวันที่</td>
        <td width="29%" class="dot">{{ $hired_date_thai ?? '' }}</td>
        <td width="21%" class="text-center">ได้รับเงินเดือนเดือนละ</td>
        <td width="20%" class="dot">{{ $extra['salary'] ?? '' }}</td>
        <td width="9%" class="text-center">บาท</td>
    </tr>
</table>
<div>มีความประสงค์ขอลาไป</div>

<table class="row" style="margin-top: 4px;">
    <tr>
        <td width="14%" class="text-right"><span class="box {{ $isStudy ? 'checked' : '' }}"></span></td>
        <td width="10%">ศึกษาวิชา</td>
        <td width="40%" class="dot-left">&nbsp;{{ $isStudy ? ($extra['course'] ?? '') : '' }}</td>
        <td width="10%" class="text-center">ขั้นปริญญา</td>
        <td width="26%" class="dot-left">&nbsp;{{ $isStudy ? ($extra['degree'] ?? '') : '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="12%">ณ สถานศึกษา</td>
        <td width="50%" class="dot-left">&nbsp;{{ $isStudy ? ($extra['institution'] ?? '') : '' }}</td>
        <td width="8%" class="text-center">ประเทศ</td>
        <td width="30%" class="dot-left">&nbsp;{{ $isStudy ? ($extra['country'] ?? '') : '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="8%">ด้วยทุน</td>
        <td width="92%" class="dot-left">&nbsp;{{ $isStudy ? ($extra['funding'] ?? '') : '' }}</td>
    </tr>
</table>

<table class="row" style="margin-top: 4px;">
    <tr>
        <td width="14%" class="text-right"><span class="box {{ $isOther ? 'checked' : '' }}"></span></td>
        <td width="40%">ฝึกอบรม ปฏิบัติการวิจัย ดูงาน ด้าน/หลักสูตร</td>
        <td width="46%" class="dot-left">&nbsp;{{ $isOther ? ($extra['course'] ?? '') : '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="4%">ณ</td>
        <td width="58%" class="dot-left">&nbsp;{{ $isOther ? ($extra['institution'] ?? '') : '' }}</td>
        <td width="8%" class="text-center">ประเทศ</td>
        <td width="30%" class="dot-left">&nbsp;{{ $isOther ? ($extra['country'] ?? '') : '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="8%">ด้วยทุน</td>
        <td width="92%" class="dot-left">&nbsp;{{ $isOther ? ($extra['funding'] ?? '') : '' }}</td>
    </tr>
</table>

<table class="row" style="margin-top: 4px;">
    <tr>
        <td width="16%">ทั้งนี้ ตั้งแต่วันที่</td>
        <td width="34%" class="dot">{{ $start_date_thai ?? '' }}</td>
        <td width="9%" class="text-center">ถึงวันที่</td>
        <td width="41%" class="dot">{{ $end_date_thai ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="8%">มีกำหนด</td>
        <td width="6%" class="dot">{{ $duration_years ?? '' }}</td>
        <td width="4%" class="text-center">ปี</td>
        <td width="6%" class="dot">{{ $duration_months ?? '' }}</td>
        <td width="6%" class="text-center">เดือน</td>
        <td width="6%" class="dot">{{ $duration_days ?? '' }}</td>
        <td width="4%" class="text-center">วัน</td>
        <td width="30%" class="text-center">ในระหว่างลาจะติดต่อข้าพเจ้าได้ที่</td>
        <td width="30%"></td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="60%" class="dot-left">&nbsp;{{ $contact_address ?? '' }}</td>
        <td width="16%" class="text-center">หมายเลขโทรศัพท์</td>
        <td width="24%" class="dot-left">&nbsp;{{ $phone ?? '' }}</td>
    </tr>
</table>

<div style="margin-top: 6px; text-indent: 14%;">ข้าพเจ้าขอรับรองว่าจะปฏิบัติตามกฎหมายและระเบียบของทางราชการเกี่ยวกับการไปศึกษา</div>
<div>ฝึกอบรม ปฏิบัติการวิจัย หรือดูงาน ทุกประการ</div>

@include('pdf.partials.requester_signature')

@include('pdf.partials.commander_and_order')

@includeIf('pdf.partials.verification_qr')

</body>
</html>
