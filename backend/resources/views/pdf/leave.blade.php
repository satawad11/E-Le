<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แบบใบลาป่วย ลาคลอดบุตร ลากิจส่วนตัว</title>
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
            margin: 8mm 12mm;
            size: A4 portrait;
        }
        body { 
            font-family: 'THSarabunNew', sans-serif; 
            font-size: 14pt; 
            line-height: 1.05;
            color: #000; 
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        
        table.form-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2px;
        }
        table.form-table td {
            vertical-align: bottom;
            padding: 1px 0;
            white-space: nowrap;
        }
        td.dotted {
            border-bottom: 1px dotted #000;
            text-align: center;
        }
        td.dotted-left {
            border-bottom: 1px dotted #000;
            text-align: left;
            padding-left: 5px;
        }

        .table-stats { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 2px; 
            font-size: 11pt; 
        }
        .table-stats th, .table-stats td { 
            border: 1px solid #000; 
            padding: 1px 2px; 
            text-align: center; 
        }
        .circle-opt {
            display: inline-block;
            width: 10px;
            height: 10px;
            border: 1px solid #000;
            border-radius: 50%;
            margin-right: 3px;
            vertical-align: middle;
        }
        .circle-opt.checked {
            background-color: #000;
        }
    </style>
</head>
<body>
    <div class="text-center font-bold" style="font-size: 16pt; margin-bottom: 4px;">
        แบบใบลาป่วย ลาคลอดบุตร ลากิจส่วนตัว
    </div>
    
    <table class="form-table">
        <tr>
            <td width="55%"></td>
            <td width="8%" class="text-right">(เขียนที่)&nbsp;</td>
            <td width="37%" class="dotted">{{ $settings['school_name'] ?? 'วิทยาลัยเทคนิคดอนเมือง' }}</td>
        </tr>
    </table>
    
    @php
        $thaiMonths = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $createdDay = $leave->created_at ? \Carbon\Carbon::parse($leave->created_at)->day : '';
        $createdMonth = $leave->created_at ? \Carbon\Carbon::parse($leave->created_at)->month : '';
        $createdYear = $leave->created_at ? \Carbon\Carbon::parse($leave->created_at)->year + 543 : '';

        $typeName = $leave->type->name ?? '';
        $isSick = str_contains($typeName, 'ป่วย');
        $isPersonal = str_contains($typeName, 'กิจ');
        $isMaternity = str_contains($typeName, 'คลอด');
    @endphp

    <table class="form-table">
        <tr>
            <td width="45%"></td>
            <td width="5%" class="text-right">วันที่&nbsp;</td>
            <td width="8%" class="dotted">{{ $createdDay }}</td>
            <td width="6%" class="text-center">เดือน</td>
            <td width="18%" class="dotted">{{ $createdMonth ? $thaiMonths[$createdMonth] : '' }}</td>
            <td width="5%" class="text-center">พ.ศ.</td>
            <td width="13%" class="dotted">{{ $createdYear }}</td>
        </tr>
    </table>

    <table class="form-table" style="margin-top: 3px;">
        <tr>
            <td width="5%" class="font-bold">เรื่อง</td>
            <td width="95%">
                ขออนุญาตลา &nbsp;
                <span class="circle-opt {{ $isSick ? 'checked' : '' }}"></span>ป่วย &nbsp;&nbsp;
                <span class="circle-opt {{ $isPersonal ? 'checked' : '' }}"></span>กิจส่วนตัว &nbsp;&nbsp;
                <span class="circle-opt {{ $isMaternity ? 'checked' : '' }}"></span>คลอดบุตร
            </td>
        </tr>
        <tr>
            <td width="5%" class="font-bold">เรียน</td>
            <td width="95%">{{ $settings['director_position'] ?? 'ผู้อำนวยการวิทยาลัยเทคนิคดอนเมือง' }}</td>
        </tr>
    </table>

    <table class="form-table" style="margin-top: 3px;">
        <tr>
            <td width="12%"></td>
            <td width="7%">ข้าพเจ้า</td>
            <td width="43%" class="dotted">{{ $leave->user->name ?? '' }}</td>
            <td width="8%" class="text-center">ตำแหน่ง</td>
            <td width="30%" class="dotted">{{ $leave->user->profile->position ?? ($leave->user->role->description ?? 'บุคลากร') }}</td>
        </tr>
    </table>
    <table class="form-table">
        <tr>
            <td width="6%">สังกัด</td>
            <td width="94%" class="dotted-left">{{ $leave->user->department->name ?? 'วิทยาลัยเทคนิคดอนเมือง' }}</td>
        </tr>
    </table>

    <table class="form-table" style="margin-top: 3px;">
        <tr>
            <td width="6%">ขอลา</td>
            <td width="30%">
                <span class="circle-opt {{ $isSick ? 'checked' : '' }}"></span>ป่วย &nbsp;
                <span class="circle-opt {{ $isPersonal ? 'checked' : '' }}"></span>กิจส่วนตัว &nbsp;
                <span class="circle-opt {{ $isMaternity ? 'checked' : '' }}"></span>คลอดบุตร
            </td>
            <td width="8%" class="text-center">เนื่องจาก</td>
            <td width="56%" class="dotted-left">{{ $leave->reason }}</td>
        </tr>
    </table>

    @php
        $startDay = $leave->start_date ? \Carbon\Carbon::parse($leave->start_date)->day : '';
        $startMonth = $leave->start_date ? \Carbon\Carbon::parse($leave->start_date)->month : '';
        $startYear = $leave->start_date ? \Carbon\Carbon::parse($leave->start_date)->year + 543 : '';
        
        $endDay = $leave->end_date ? \Carbon\Carbon::parse($leave->end_date)->day : '';
        $endMonth = $leave->end_date ? \Carbon\Carbon::parse($leave->end_date)->month : '';
        $endYear = $leave->end_date ? \Carbon\Carbon::parse($leave->end_date)->year + 543 : '';
    @endphp

    <table class="form-table" style="margin-top: 3px;">
        <tr>
            <td width="10%">ตั้งแต่วันที่</td>
            <td width="6%" class="dotted">{{ $startDay }}</td>
            <td width="5%" class="text-center">เดือน</td>
            <td width="18%" class="dotted">{{ $startMonth ? $thaiMonths[$startMonth] : '' }}</td>
            <td width="5%" class="text-center">พ.ศ.</td>
            <td width="10%" class="dotted">{{ $startYear }}</td>
            <td width="7%" class="text-center">ถึงวันที่</td>
            <td width="6%" class="dotted">{{ $endDay }}</td>
            <td width="5%" class="text-center">เดือน</td>
            <td width="18%" class="dotted">{{ $endMonth ? $thaiMonths[$endMonth] : '' }}</td>
            <td width="5%" class="text-center">พ.ศ.</td>
            <td width="10%" class="dotted">{{ $endYear }}</td>
        </tr>
    </table>

    <table class="form-table">
        <tr>
            <td width="9%">มีกำหนด</td>
            <td width="8%" class="dotted">{{ $leave->total_days }}</td>
            <td width="83%">&nbsp;วัน (วันทำการ)</td>
        </tr>
    </table>

    <table class="form-table" style="margin-top: 3px;">
        <tr>
            <td width="15%">ข้าพเจ้าได้ลา</td>
            <td width="30%">
                <span class="circle-opt"></span>ป่วย &nbsp;
                <span class="circle-opt"></span>กิจส่วนตัว &nbsp;
                <span class="circle-opt"></span>คลอดบุตร
            </td>
            <td width="17%" class="text-right">ครั้งสุดท้ายตั้งแต่วันที่&nbsp;</td>
            <td width="38%" class="dotted">
                {{ $lastLeave ? \Carbon\Carbon::parse($lastLeave->start_date)->day . ' ' . $thaiMonths[\Carbon\Carbon::parse($lastLeave->start_date)->month] . ' ' . (\Carbon\Carbon::parse($lastLeave->start_date)->year + 543) : '-' }}
            </td>
        </tr>
    </table>
    <table class="form-table">
        <tr>
            <td width="6%">ถึงวันที่</td>
            <td width="44%" class="dotted">
                {{ $lastLeave ? \Carbon\Carbon::parse($lastLeave->end_date)->day . ' ' . $thaiMonths[\Carbon\Carbon::parse($lastLeave->end_date)->month] . ' ' . (\Carbon\Carbon::parse($lastLeave->end_date)->year + 543) : '-' }}
            </td>
            <td width="10%" class="text-center">มีกำหนด</td>
            <td width="10%" class="dotted">{{ $lastLeave ? $lastLeave->total_days : '-' }}</td>
            <td width="30%">&nbsp;วัน (วันทำการ)</td>
        </tr>
    </table>

    <table class="form-table" style="margin-top: 3px;">
        <tr>
            <td width="22%">ในระหว่างลาจะติดต่อข้าพเจ้าได้ที่</td>
            <td width="78%" class="dotted-left">{{ $leave->contact_address }}</td>
        </tr>
        <tr>
            <td width="8%">โทรศัพท์</td>
            <td width="92%" class="dotted-left">{{ $leave->user->phone ?? ($leave->user->profile->phone ?? '-') }}</td>
        </tr>
    </table>

    <!-- Bottom Section Split (Stats on Left, Signatures on Right) -->
    <table width="100%" style="margin-top: 8px;">
        <tr>
            <!-- Left Side: 2 Semester Stats Tables -->
            <td width="54%" style="vertical-align: top; padding-right: 5px;">
                @php
                    $curYear = $leave->start_date ? \Carbon\Carbon::parse($leave->start_date)->year + 543 : (date('Y') + 543);
                @endphp
                <div class="font-bold" style="font-size: 11pt;">สถิติการลา ปีงบประมาณ {{ $curYear }} (1 ต.ค. - 31 มี.ค.)</div>
                <table class="table-stats">
                    <thead>
                        <tr>
                            <th rowspan="2" width="28%">ประเภทการลา<br>(วันทำการ)</th>
                            <th colspan="2">ลามาแล้ว</th>
                            <th colspan="2">ลาครั้งนี้</th>
                            <th colspan="2">รวม</th>
                        </tr>
                        <tr>
                            <th width="12%">ครั้ง</th><th width="12%">วัน</th>
                            <th width="12%">ครั้ง</th><th width="12%">วัน</th>
                            <th width="12%">ครั้ง</th><th width="12%">วัน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>ป่วย</td><td>{{ $statSem1['ป่วย']['used_times'] ?? '' }}</td><td>{{ $statSem1['ป่วย']['used_days'] ?? '' }}</td><td>{{ $isSick ? 1 : '' }}</td><td>{{ $isSick ? $leave->total_days : '' }}</td><td></td><td></td></tr>
                        <tr><td>กิจส่วนตัว</td><td>{{ $statSem1['กิจ']['used_times'] ?? '' }}</td><td>{{ $statSem1['กิจ']['used_days'] ?? '' }}</td><td>{{ $isPersonal ? 1 : '' }}</td><td>{{ $isPersonal ? $leave->total_days : '' }}</td><td></td><td></td></tr>
                        <tr><td>คลอดบุตร</td><td>{{ $statSem1['คลอด']['used_times'] ?? '' }}</td><td>{{ $statSem1['คลอด']['used_days'] ?? '' }}</td><td>{{ $isMaternity ? 1 : '' }}</td><td>{{ $isMaternity ? $leave->total_days : '' }}</td><td></td><td></td></tr>
                    </tbody>
                </table>

                <div class="font-bold" style="font-size: 11pt; margin-top: 4px;">สถิติการลา ปีงบประมาณ {{ $curYear }} (1 เม.ย. - 30 ก.ย.)</div>
                <table class="table-stats">
                    <thead>
                        <tr>
                            <th rowspan="2" width="28%">ประเภทการลา<br>(วันทำการ)</th>
                            <th colspan="2">ลามาแล้ว</th>
                            <th colspan="2">ลาครั้งนี้</th>
                            <th colspan="2">รวม</th>
                        </tr>
                        <tr>
                            <th width="12%">ครั้ง</th><th width="12%">วัน</th>
                            <th width="12%">ครั้ง</th><th width="12%">วัน</th>
                            <th width="12%">ครั้ง</th><th width="12%">วัน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>ป่วย</td><td>{{ $statSem2['ป่วย']['used_times'] ?? '' }}</td><td>{{ $statSem2['ป่วย']['used_days'] ?? '' }}</td><td></td><td></td><td></td><td></td></tr>
                        <tr><td>กิจส่วนตัว</td><td>{{ $statSem2['กิจ']['used_times'] ?? '' }}</td><td>{{ $statSem2['กิจ']['used_days'] ?? '' }}</td><td></td><td></td><td></td><td></td></tr>
                        <tr><td>คลอดบุตร</td><td>{{ $statSem2['คลอด']['used_times'] ?? '' }}</td><td>{{ $statSem2['คลอด']['used_days'] ?? '' }}</td><td></td><td></td><td></td><td></td></tr>
                    </tbody>
                </table>

                <div style="margin-top: 6px; font-size: 12pt;">
                    (ลงชื่อ).........................................................ผู้ตรวจสอบ<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(........................................................)<br>
                    ตำแหน่ง........................................................<br>
                    วันที่......../................/............
                </div>
            </td>

            <!-- Right Side: Applicant Sign & Approver Decision -->
            <td width="46%" style="vertical-align: top; padding-left: 5px;">
                <div class="text-center" style="font-size: 12pt;">
                    ขอแสดงความนับถือ<br><br>
                    (ลงชื่อ)........................................................<br>
                    ( {{ $leave->user->name ?? '' }} )<br>
                    วันที่ {{ $createdDay }} / {{ $createdMonth ? $thaiMonths[$createdMonth] : '' }} / {{ $createdYear }}
                </div>

                <div style="margin-top: 8px; font-size: 12pt;">
                    <div class="font-bold"><u>ความเห็นผู้บังคับบัญชา</u></div>
                    ....................................................................................<br>
                    ....................................................................................<br>
                    (ลงชื่อ)........................................................<br>
                    ตำแหน่ง........................................................<br>
                    วันที่......../................/............
                </div>

                <div style="margin-top: 8px; font-size: 12pt;">
                    <div class="font-bold"><u>คำสั่ง</u> &nbsp;&nbsp;&nbsp;&nbsp; <span class="circle-opt"></span> อนุญาต &nbsp;&nbsp;&nbsp;&nbsp; <span class="circle-opt"></span> ไม่อนุญาต</div>
                    ....................................................................................<br>
                    ....................................................................................<br>
                    (ลงชื่อ)........................................................<br>
                    ตำแหน่ง {{ $settings['director_position'] ?? 'ผู้อำนวยการวิทยาลัยเทคนิคดอนเมือง' }}<br>
                    วันที่......../................/............
                </div>
            </td>
        </tr>
    </table>
@include('pdf.partials.verification_qr')
</body>
</html>
