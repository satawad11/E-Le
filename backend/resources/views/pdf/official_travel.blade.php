<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>บันทึกข้อความ ขออนุญาตเดินทางไปราชการ</title>
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
            line-height: 1.2;
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
            padding: 1px 0;
        }
    </style>
</head>
<body>
    <!-- Top Header with Garuda Crest -->
    <table class="layout">
        <tr>
            <td width="15%" style="vertical-align: top;">
                @if(!empty($settings['garuda_logo_url']) && file_exists(public_path(str_replace('/storage/', 'storage/', $settings['garuda_logo_url']))))
                    <img src="{{ public_path(str_replace('/storage/', 'storage/', $settings['garuda_logo_url'])) }}" style="width: 2.8cm; height: auto;">
                @else
                    <img src="{{ public_path('images/garuda.png') }}" style="width: 2.8cm; height: auto;">
                @endif
            </td>
            <td width="85%" style="vertical-align: bottom; padding-left: 10px;">
                <div class="font-bold" style="font-size: 26pt; line-height: 1;">บันทึกข้อความ</div>
            </td>
        </tr>
    </table>

    <table class="layout" style="margin-top: 5px;">
        <tr>
            <td width="10%" class="font-bold">ส่วนราชการ</td>
            <td width="90%">{{ $settings['school_name'] ?? 'วิทยาลัยเทคนิคดอนเมือง' }} {{ $settings['school_affiliation'] ?? 'สำนักงานคณะกรรมการการอาชีวศึกษา' }}</td>
        </tr>
    </table>

    @php
        $thaiMonths = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $createdDay = $leave->created_at ? \Carbon\Carbon::parse($leave->created_at)->day : '';
        $createdMonth = $leave->created_at ? \Carbon\Carbon::parse($leave->created_at)->month : '';
        $createdYear = $leave->created_at ? \Carbon\Carbon::parse($leave->created_at)->year + 543 : '';

        $meta = is_array($leave->meta_data) ? $leave->meta_data : json_decode($leave->meta_data ?? '[]', true);
    @endphp

    <table class="layout">
        <tr>
            <td width="5%" class="font-bold">ที่</td>
            <td width="45%" class="dot-line">{{ $leave->document_number ?? ($settings['doc_number_prefix'] ?? 'ศธ 0584.1') }}</td>
            <td width="8%" class="font-bold text-right">วันที่&nbsp;</td>
            <td width="42%" class="dot-line">{{ $createdDay }} {{ $createdMonth ? $thaiMonths[$createdMonth] : '' }} {{ $createdYear }}</td>
        </tr>
        <tr>
            <td width="5%" class="font-bold">เรื่อง</td>
            <td width="95%" colspan="3" class="font-bold">ขออนุญาตเดินทางไปราชการ</td>
        </tr>
        <tr>
            <td width="5%" class="font-bold">เรียน</td>
            <td width="95%" colspan="3">{{ $settings['director_position'] ?? 'ผู้อำนวยการวิทยาลัยเทคนิคดอนเมือง' }}</td>
        </tr>
    </table>

    <div style="margin-top: 8px;">
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;ข้าพเจ้า (นาย/นาง/นางสาว) <span class="dot-line" style="width: 55%;">{{ $leave->user->name ?? '' }}</span> พร้อมด้วย
    </div>

    <!-- Companion List 1 to 6 -->
    <table class="layout" style="margin-top: 3px;">
        <tr>
            <td width="50%">1) <span class="dot-line" style="width: 85%;">{{ $meta['companion_1'] ?? '' }}</span></td>
            <td width="50%">2) <span class="dot-line" style="width: 85%;">{{ $meta['companion_2'] ?? '' }}</span></td>
        </tr>
        <tr>
            <td width="50%">3) <span class="dot-line" style="width: 85%;">{{ $meta['companion_3'] ?? '' }}</span></td>
            <td width="50%">4) <span class="dot-line" style="width: 85%;">{{ $meta['companion_4'] ?? '' }}</span></td>
        </tr>
        <tr>
            <td width="50%">5) <span class="dot-line" style="width: 85%;">{{ $meta['companion_5'] ?? '' }}</span></td>
            <td width="50%">6) <span class="dot-line" style="width: 85%;">{{ $meta['companion_6'] ?? '' }}</span></td>
        </tr>
    </table>

    <table class="layout" style="margin-top: 4px;">
        <tr>
            <td width="15%">แผนกวิชา/งาน</td>
            <td width="35%" class="dot-line">{{ $leave->user->department->name ?? '' }}</td>
            <td width="20%" class="text-right">ขออนุญาตไปราชการ ณ&nbsp;</td>
            <td width="30%" class="dot-line">{{ $leave->contact_address ?? ($meta['destination'] ?? '') }}</td>
        </tr>
        <tr>
            <td width="5%">เรื่อง</td>
            <td width="45%" class="dot-line">{{ $leave->reason }}</td>
            <td width="15%" class="text-right">ตามหนังสือที่&nbsp;</td>
            <td width="35%" class="dot-line">{{ $meta['ref_doc_no'] ?? '-' }}</td>
        </tr>
    </table>

    @php
        $startDay = $leave->start_date ? \Carbon\Carbon::parse($leave->start_date)->day : '';
        $startMonth = $leave->start_date ? \Carbon\Carbon::parse($leave->start_date)->month : '';
        $startYear = $leave->start_date ? \Carbon\Carbon::parse($leave->start_date)->year + 543 : '';
        
        $endDay = $leave->end_date ? \Carbon\Carbon::parse($leave->end_date)->day : '';
        $endMonth = $leave->end_date ? \Carbon\Carbon::parse($leave->end_date)->month : '';
        $endYear = $leave->end_date ? \Carbon\Carbon::parse($leave->end_date)->year + 543 : '';

        $expenseType = $meta['expense_type'] ?? 'budget';
    @endphp

    <table class="layout" style="margin-top: 4px;">
        <tr>
            <td width="10%">ตั้งแต่วันที่</td>
            <td width="30%" class="dot-line text-center">{{ $startDay }} {{ $startMonth ? $thaiMonths[$startMonth] : '' }} {{ $startYear }}</td>
            <td width="8%" class="text-center">ถึงวันที่</td>
            <td width="30%" class="dot-line text-center">{{ $endDay }} {{ $endMonth ? $thaiMonths[$endMonth] : '' }} {{ $endYear }}</td>
            <td width="10%" class="text-center">มีกำหนด</td>
            <td width="12%" class="dot-line text-center">{{ $leave->total_days }} วัน</td>
        </tr>
    </table>

    <!-- Expense Option Checkboxes -->
    <div style="margin-top: 6px; font-size: 15pt;">
        <div><span class="checkbox {{ $expenseType === 'budget' ? 'checked' : '' }}"></span> โดยขออนุมัติให้เบิกค่าใช้จ่ายในการเดินทางไปราชการตามสิทธิ์</div>
        <div><span class="checkbox {{ $expenseType === 'no_budget' ? 'checked' : '' }}"></span> โดยไม่ขอเบิกค่าใช้จ่ายในการเดินทางไปราชการ</div>
        <div><span class="checkbox {{ $expenseType === 'vehicle_only' ? 'checked' : '' }}"></span> โดยขออนุญาตให้เบิกค่ายานพาหนะแต่ไม่เบิกค่าเบี้ยเลี้ยงในการเดินทางไปราชการ</div>
        <div><span class="checkbox {{ $expenseType === 'substitute' || !empty($leave->substitute_id) ? 'checked' : '' }}"></span> ได้มอบหมายให้ <span class="dot-line" style="width: 35%;">{{ $leave->substitute ? $leave->substitute->name : '-' }}</span> จัดการสอนแทนแล้ว / ปฏิบัติราชการแทน</div>
    </div>

    <!-- Applicant Signature -->
    <div class="text-right" style="margin-top: 15px; margin-right: 30px;">
        ลงชื่อ........................................................ผู้ขอไปราชการ<br>
        ( {{ $leave->user->name ?? '' }} )
    </div>

    <!-- Multi-level 6 Approvals Layout -->
    <table width="100%" style="margin-top: 10px; font-size: 13pt;">
        <tr>
            <!-- Column 1: Levels (1) to (3) -->
            <td width="50%" style="vertical-align: top; padding-right: 10px;">
                <div><strong>(1) ความเห็นหัวหน้าแผนกวิชา/งาน</strong></div>
                ....................................................................................<br>
                ลงชื่อ........................................................<br>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(........................................................)<br><br>

                <div><strong>(2) ความเห็นรองผู้อำนวยการฝ่ายที่สังกัด</strong></div>
                [ &nbsp; ] ฝ่ายแผนงานและความร่วมมือ<br>
                [ &nbsp; ] ฝ่ายพัฒนากิจการนักเรียน นักศึกษา<br>
                ( &nbsp; ) เพื่อโปรดพิจารณา ( &nbsp; ) อนุญาต ( &nbsp; ) ไม่อนุญาต<br>
                ลงชื่อ........................................................<br>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(........................................................)<br><br>

                <div><strong>(3) ความเห็นรองผู้อำนวยการฝ่ายวิชาการ</strong></div>
                ( &nbsp; ) เพื่อโปรดพิจารณา ( &nbsp; ) อนุญาต ( &nbsp; ) ไม่อนุญาต<br>
                ลงชื่อ........................................................<br>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;( {{ $settings['deputy_academic_name'] ?? 'ว่าที่ร้อยตรีไชยา ทับวิธร' }} )
            </td>

            <!-- Column 2: Levels (4) to (6) -->
            <td width="50%" style="vertical-align: top; padding-left: 10px;">
                <div><strong>(4) ความเห็นหัวหน้างานบุคลากร</strong></div>
                ....................................................................................<br>
                ลงชื่อ........................................................<br>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;( {{ $settings['head_hr_name'] ?? 'นายอภินันท์ ก้อนมณี' }} )<br><br>

                <div><strong>(5) ความเห็นรองผู้อำนวยการฝ่ายบริหารทรัพยากร</strong></div>
                ( &nbsp; ) เพื่อโปรดพิจารณา ( &nbsp; ) อนุญาต ( &nbsp; ) ไม่อนุญาต<br>
                ....................................................................................<br>
                ลงชื่อ........................................................<br>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;( {{ $settings['deputy_admin_name'] ?? 'นายพัฒนพงษ์ พริ้วไธสง' }} )<br><br>

                <div><strong>(6) ความเห็นผู้อำนวยการวิทยาลัยเทคนิคดอนเมือง</strong></div>
                ( &nbsp; ) อนุญาต ( &nbsp; ) ไม่อนุญาต<br>
                ....................................................................................<br>
                ลงชื่อ........................................................<br>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;( {{ $settings['director_name'] ?? 'นายบรรจง พลขันธ์' }} )<br>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;ผู้อำนวยการวิทยาลัยเทคนิคดอนเมือง
            </td>
        </tr>
    </table>
@include('pdf.partials.verification_qr')
</body>
</html>
