<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แบบใบลาไปช่วยเหลือภริยาที่คลอดบุตร</title>
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
        @page {
            margin: 15mm 20mm;
            size: A4 portrait;
        }
        body { 
            font-family: 'THSarabunNew', sans-serif; 
            font-size: 16pt; 
            line-height: 1.25;
            color: #000; 
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        
        .dot-line {
            border-bottom: 1px dotted #000;
            display: inline-block;
        }

        .checkbox {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 1px solid #000;
            margin-right: 6px;
            vertical-align: middle;
        }
        .checkbox.checked {
            background-color: #000;
        }
        
        table.layout {
            width: 100%;
            border-collapse: collapse;
        }
        table.layout td {
            vertical-align: bottom;
            padding: 2px 0;
        }
    </style>
</head>
<body>
    <div class="text-center font-bold" style="font-size: 20pt; margin-bottom: 15px;">
        แบบใบลาไปช่วยเหลือภริยาที่คลอดบุตร
    </div>
    
    <table class="layout">
        <tr>
            <td width="55%"></td>
            <td width="8%">(เขียนที่)</td>
            <td width="37%" class="dot-line text-center">{{ $settings['school_name'] ?? 'วิทยาลัยเทคนิคดอนเมือง' }}</td>
        </tr>
    </table>
    
    @php
        $thaiMonths = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $createdDay = $leave->created_at ? \Carbon\Carbon::parse($leave->created_at)->day : '';
        $createdMonth = $leave->created_at ? \Carbon\Carbon::parse($leave->created_at)->month : '';
        $createdYear = $leave->created_at ? \Carbon\Carbon::parse($leave->created_at)->year + 543 : '';

        $meta = is_array($leave->meta_data) ? $leave->meta_data : json_decode($leave->meta_data ?? '[]', true);

        $startDay = $leave->start_date ? \Carbon\Carbon::parse($leave->start_date)->day : '';
        $startMonth = $leave->start_date ? \Carbon\Carbon::parse($leave->start_date)->month : '';
        $startYear = $leave->start_date ? \Carbon\Carbon::parse($leave->start_date)->year + 543 : '';
        
        $endDay = $leave->end_date ? \Carbon\Carbon::parse($leave->end_date)->day : '';
        $endMonth = $leave->end_date ? \Carbon\Carbon::parse($leave->end_date)->month : '';
        $endYear = $leave->end_date ? \Carbon\Carbon::parse($leave->end_date)->year + 543 : '';

        $childBirthDay = !empty($meta['child_birth_date']) ? \Carbon\Carbon::parse($meta['child_birth_date'])->day : '';
        $childBirthMonth = !empty($meta['child_birth_date']) ? \Carbon\Carbon::parse($meta['child_birth_date'])->month : '';
        $childBirthYear = !empty($meta['child_birth_date']) ? \Carbon\Carbon::parse($meta['child_birth_date'])->year + 543 : '';
    @endphp

    <table class="layout">
        <tr>
            <td width="45%"></td>
            <td width="5%" class="text-right">วันที่&nbsp;</td>
            <td width="10%" class="dot-line text-center">{{ $createdDay }}</td>
            <td width="7%" class="text-center">เดือน</td>
            <td width="18%" class="dot-line text-center">{{ $createdMonth ? $thaiMonths[$createdMonth] : '' }}</td>
            <td width="5%" class="text-center">พ.ศ.</td>
            <td width="10%" class="dot-line text-center">{{ $createdYear }}</td>
        </tr>
    </table>

    <table class="layout" style="margin-top: 10px;">
        <tr>
            <td width="6%" class="font-bold">เรื่อง</td>
            <td width="94%" class="font-bold">ขอลาไปช่วยเหลือภริยาที่คลอดบุตร</td>
        </tr>
        <tr>
            <td width="6%" class="font-bold">เรียน</td>
            <td width="94%">{{ $settings['director_position'] ?? 'ผู้อำนวยการวิทยาลัยเทคนิคดอนเมือง' }}</td>
        </tr>
    </table>

    <table class="layout" style="margin-top: 10px;">
        <tr>
            <td width="12%"></td>
            <td width="7%">ข้าพเจ้า</td>
            <td width="40%" class="dot-line text-center">{{ $leave->user->name ?? '' }}</td>
            <td width="8%" class="text-center">ตำแหน่ง</td>
            <td width="33%" class="dot-line text-center">{{ $leave->user->profile->position ?? ($leave->user->role->description ?? '') }}</td>
        </tr>
        <tr>
            <td width="5%">ระดับ</td>
            <td width="35%" class="dot-line text-center">-</td>
            <td width="8%" class="text-center">สังกัด</td>
            <td width="52%" class="dot-line text-center">{{ $leave->user->department->name ?? ($settings['school_name'] ?? 'วิทยาลัยเทคนิคดอนเมือง') }}</td>
        </tr>
        <tr>
            <td width="40%">มีความประสงค์ลาไปช่วยเหลือภริยาโดยชอบด้วยกฎหมายชื่อ</td>
            <td width="60%" class="dot-line text-center">{{ $meta['wife_name'] ?? '-' }}</td>
        </tr>
    </table>

    <table class="layout">
        <tr>
            <td width="15%">ซึ่งคลอดบุตรเมื่อวันที่</td>
            <td width="8%" class="dot-line text-center">{{ $childBirthDay }}</td>
            <td width="5%" class="text-center">เดือน</td>
            <td width="18%" class="dot-line text-center">{{ $childBirthMonth ? $thaiMonths[$childBirthMonth] : '' }}</td>
            <td width="5%" class="text-center">พ.ศ.</td>
            <td width="10%" class="dot-line text-center">{{ $childBirthYear }}</td>
            <td width="39%">จึงขออนุญาตลาไปช่วยเหลือภริยาที่คลอดบุตรตั้งแต่</td>
        </tr>
    </table>

    <table class="layout">
        <tr>
            <td width="5%">วันที่</td>
            <td width="8%" class="dot-line text-center">{{ $startDay }}</td>
            <td width="5%" class="text-center">เดือน</td>
            <td width="18%" class="dot-line text-center">{{ $startMonth ? $thaiMonths[$startMonth] : '' }}</td>
            <td width="5%" class="text-center">พ.ศ.</td>
            <td width="10%" class="dot-line text-center">{{ $startYear }}</td>
            <td width="7%" class="text-center">ถึงวันที่</td>
            <td width="8%" class="dot-line text-center">{{ $endDay }}</td>
            <td width="5%" class="text-center">เดือน</td>
            <td width="18%" class="dot-line text-center">{{ $endMonth ? $thaiMonths[$endMonth] : '' }}</td>
            <td width="5%" class="text-center">พ.ศ.</td>
            <td width="10%" class="dot-line text-center">{{ $endYear }}</td>
        </tr>
    </table>

    <table class="layout">
        <tr>
            <td width="10%">มีกำหนด</td>
            <td width="15%" class="dot-line text-center">{{ $leave->total_days }}</td>
            <td width="75%">วันทำการ</td>
        </tr>
        <tr>
            <td width="25%">ในระหว่างลาจะติดต่อข้าพเจ้าได้ที่</td>
            <td width="75%" class="dot-line">{{ $leave->contact_address }}</td>
        </tr>
        <tr>
            <td width="15%">หมายเลขโทรศัพท์</td>
            <td width="85%" class="dot-line">{{ $leave->user->phone ?? ($leave->user->profile->phone ?? '-') }}</td>
        </tr>
    </table>

    <!-- Applicant Signature -->
    <div class="text-right" style="margin-top: 25px; margin-right: 40px;">
        (ลงชื่อ)........................................................<br>
        ( {{ $leave->user->name ?? '' }} )
    </div>

    <!-- Approvals & Order -->
    <div style="margin-top: 25px;">
        <div class="font-bold"><u>ความเห็นผู้บังคับบัญชา</u></div>
        ........................................................................................................................................................................................<br>
        ........................................................................................................................................................................................
        <div class="text-center" style="margin-top: 15px; margin-left: 200px;">
            (ลงชื่อ)........................................................<br>
            (........................................................)<br>
            วันที่......../................/............
        </div>
    </div>

    <div style="margin-top: 25px;">
        <div class="font-bold"><u>คำสั่ง</u> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <span class="checkbox"></span> อนุญาต &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <span class="checkbox"></span> ไม่อนุญาต</div>
        ........................................................................................................................................................................................<br>
        ........................................................................................................................................................................................
        <div class="text-center" style="margin-top: 15px; margin-left: 200px;">
            (ลงชื่อ)........................................................<br>
            ( {{ $settings['director_name'] ?? '........................................................' }} )<br>
            วันที่......../................/............
        </div>
    </div>

    <div style="margin-top: 30px; font-size: 13pt;" class="font-bold">
        หมายเหตุ : ให้แนบสำเนาทะเบียนสมรส และสำเนาสูติบัตรเพื่อประกอบการพิจารณา
    </div>
@include('pdf.partials.verification_qr')
</body>
</html>
