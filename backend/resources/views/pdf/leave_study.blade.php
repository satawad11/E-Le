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
</head>
<body>

@php
    $kind = (string) ($extra['study_kind'] ?? '');
    $kinds = ['ศึกษา', 'ฝึกอบรม', 'ปฏิบัติการวิจัย', 'ดูงาน'];
@endphp

@include('pdf.partials.form_header', [
    'formTitle' => 'แบบใบลาไปศึกษา ฝึกอบรม ปฏิบัติการวิจัย หรือดูงาน',
    'formSubject' => 'ขอลาไป'.($kind !== '' ? $kind : 'ศึกษา ฝึกอบรม ปฏิบัติการวิจัย หรือดูงาน'),
])

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
        <td width="63%" class="dot-left">&nbsp;{{ trim(($division ?? '').' '.($school_name ?? '')) }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="9%">เกิดวันที่</td>
        <td width="30%" class="dot">{{ $birth_date_thai ?? '' }}</td>
        <td width="20%" class="text-center">เข้ารับราชการเมื่อวันที่</td>
        <td width="41%" class="dot">{{ $hired_date_thai ?? '' }}</td>
    </tr>
</table>

<div style="margin-top: 6px;">
    ขอลาไป
    @foreach ($kinds as $option)
        &nbsp;<span class="box {{ $kind === $option ? 'checked' : '' }}"></span>{{ $option }}
    @endforeach
</div>
<table class="row" style="margin-top: 4px;">
    <tr>
        <td width="12%">วิชา/หลักสูตร</td>
        <td width="50%" class="dot-left">&nbsp;{{ $extra['course'] ?? '' }}</td>
        <td width="10%" class="text-center">ขั้นปริญญา</td>
        <td width="28%" class="dot-left">&nbsp;{{ $extra['degree'] ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="14%">ณ สถานศึกษา</td>
        <td width="48%" class="dot-left">&nbsp;{{ $extra['institution'] ?? '' }}</td>
        <td width="8%" class="text-center">ประเทศ</td>
        <td width="30%" class="dot-left">&nbsp;{{ $extra['country'] ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="8%">ด้วยทุน</td>
        <td width="92%" class="dot-left">&nbsp;{{ $extra['funding'] ?? '' }}</td>
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
