<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แบบใบลาอุปสมบท</title>
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
            line-height: 1.08;
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
    <div class="text-center font-bold" style="font-size: 17pt; margin-bottom: 6px;">
        แบบใบลาอุปสมบท
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

        $meta = is_array($leave->meta_data) ? $leave->meta_data : json_decode($leave->meta_data ?? '[]', true);
        $ordainStatus = $meta['ordain_status'] ?? 'ยังไม่เคย';
        $isEver = $ordainStatus === 'เคย';
        $isNever = $ordainStatus === 'ยังไม่เคย';
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

    <table class="form-table" style="margin-top: 4px;">
        <tr>
            <td width="5%" class="font-bold">เรื่อง</td>
            <td width="95%" class="font-bold">ขอลาอุปสมบท</td>
        </tr>
        <tr>
            <td width="5%" class="font-bold">เรียน</td>
            <td width="95%">{{ $settings['director_position'] ?? 'ผู้อำนวยการวิทยาลัยเทคนิคดอนเมือง' }}</td>
        </tr>
    </table>

    <table class="form-table" style="margin-top: 4px;">
        <tr>
            <td width="12%"></td>
            <td width="7%">ข้าพเจ้า</td>
            <td width="43%" class="dotted">{{ $leave->user->name ?? '' }}</td>
            <td width="8%" class="text-center">ตำแหน่ง</td>
            <td width="30%" class="dotted">{{ $leave->user->profile->position ?? ($leave->user->role->description ?? 'บุคลากร') }}</td>
        </tr>
        <tr>
            <td width="10%">แผนก/งาน</td>
            <td width="35%" class="dotted">{{ $leave->user->department->name ?? '' }}</td>
            <td width="8%" class="text-center">สังกัด</td>
            <td width="47%" class="dotted">{{ $settings['school_name'] ?? 'วิทยาลัยเทคนิคดอนเมือง' }}</td>
        </tr>
    </table>

    <table class="form-table" style="margin-top: 4px;">
        <tr>
            <td width="20%">เกิดวันที่</td>
            <td width="30%" class="dotted">{{ $leave->user->profile->date_of_birth ? \Carbon\Carbon::parse($leave->user->profile->date_of_birth)->day . ' ' . $thaiMonths[\Carbon\Carbon::parse($leave->user->profile->date_of_birth)->month] . ' ' . (\Carbon\Carbon::parse($leave->user->profile->date_of_birth)->year + 543) : '-' }}</td>
            <td width="15%" class="text-center">เข้ารับราชการเมื่อวันที่</td>
            <td width="35%" class="dotted">{{ $leave->user->profile->start_date ? \Carbon\Carbon::parse($leave->user->profile->start_date)->day . ' ' . $thaiMonths[\Carbon\Carbon::parse($leave->user->profile->start_date)->month] . ' ' . (\Carbon\Carbon::parse($leave->user->profile->start_date)->year + 543) : '-' }}</td>
        </tr>
        <tr>
            <td width="30%">ข้าพเจ้า <span class="circle-opt {{ $isNever ? 'checked' : '' }}"></span> ยังไม่เคยอุปสมบท &nbsp;&nbsp; <span class="circle-opt {{ $isEver ? 'checked' : '' }}"></span> เคยอุปสมบทมาแล้ว</td>
            <td width="70%">มีความประสงค์ขอลาอุปสมบท ณ วัด <span class="dotted" style="display:inline-block; width: 40%;">{{ $meta['temple_name'] ?? '-' }}</span></td>
        </tr>
        <tr>
            <td width="10%">ตั้งอยู่ ณ</td>
            <td width="90%" class="dotted-left">{{ $meta['temple_location'] ?? '-' }}</td>
        </tr>
        <tr>
            <td width="20%">กำหนดอุปสมบทวันที่</td>
            <td width="30%" class="dotted">{{ !empty($meta['ordain_date']) ? \Carbon\Carbon::parse($meta['ordain_date'])->day . ' ' . $thaiMonths[\Carbon\Carbon::parse($meta['ordain_date'])->month] . ' ' . (\Carbon\Carbon::parse($meta['ordain_date'])->year + 543) : '-' }}</td>
            <td width="20%" class="text-center">และจะจำพรรษาอยู่ ณ วัด</td>
            <td width="30%" class="dotted">{{ $meta['stay_temple_name'] ?? ($meta['temple_name'] ?? '-') }}</td>
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

    <table class="form-table" style="margin-top: 4px;">
        <tr>
            <td width="18%">ขอลาอุปสมบทตั้งแต่วันที่</td>
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

    <!-- Signature & Approval Split Layout -->
    <table width="100%" style="margin-top: 20px;">
        <tr>
            <!-- Left Side -->
            <td width="48%" style="vertical-align: top; padding-right: 10px;">
                <div style="font-size: 13pt;">
                    (ลงชื่อ).........................................................ผู้ตรวจสอบ<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(........................................................)<br>
                    วันที่......../................/............
                </div>
            </td>

            <!-- Right Side: Applicant Sign & Approver Decision -->
            <td width="52%" style="vertical-align: top; padding-left: 10px;">
                <div class="text-center" style="font-size: 13pt;">
                    ขอแสดงความนับถือ<br><br>
                    (ลงชื่อ)........................................................<br>
                    ( {{ $leave->user->name ?? '' }} )
                </div>

                <div style="margin-top: 15px; font-size: 13pt;">
                    <div class="font-bold"><u>ความเห็นผู้บังคับบัญชา</u></div>
                    ....................................................................................<br>
                    (ลงชื่อ)........................................................<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;(........................................................)<br>
                    วันที่......../................/............
                </div>

                <div style="margin-top: 15px; font-size: 13pt;">
                    <div class="font-bold"><u>คำสั่ง</u> &nbsp;&nbsp;&nbsp;&nbsp; <span class="circle-opt"></span> อนุญาต &nbsp;&nbsp;&nbsp;&nbsp; <span class="circle-opt"></span> ไม่อนุญาต</div>
                    ....................................................................................<br>
                    (ลงชื่อ)........................................................<br>
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;( {{ $settings['director_name'] ?? '........................................................' }} )<br>
                    วันที่......../................/............
                </div>
            </td>
        </tr>
    </table>
@include('pdf.partials.verification_qr')
</body>
</html>
