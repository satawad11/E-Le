<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
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
            margin: 5mm 15mm;
            size: A4 portrait;
        }
        body { 
            font-family: 'THSarabunNew', sans-serif; 
            font-size: 15pt; 
            margin: 0; 
            padding: 0; 
            color: #000; 
            line-height: 0.95; 
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        
        .table-stats { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 5px; 
            font-size: 14pt; 
        }
        .table-stats th, .table-stats td { 
            border: 1px solid #000; 
            padding: 2px; 
            text-align: center; 
        }
        
        .dot-line {
            border-bottom: 1px dotted #000;
        }
        
        table.layout {
            width: 100%;
            border-collapse: collapse;
        }
        table.layout td {
            vertical-align: bottom;
            padding-bottom: 0px;
            padding-top: 0px;
        }
    </style>
</head>
<body>
    <div class="text-center font-bold" style="font-size: 18pt; margin-bottom: 10px;">
        แบบใบลาไปประกอบพิธีฮัจญ์
    </div>
    
    <table class="layout">
        <tr>
            <td width="55%"></td>
            <td width="8%">เขียนที่</td>
            <td width="37%" class="dot-line text-center">{{ $settings['institution_name'] ?? '' }}</td>
        </tr>
    </table>
    
    @php
        $thaiMonths = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $createdMonth = \Carbon\Carbon::parse($leave->created_at)->month;
        $createdYear = \Carbon\Carbon::parse($leave->created_at)->year + 543;
    @endphp

    <table class="layout">
        <tr>
            <td width="45%"></td>
            <td width="5%" class="text-right">วันที่&nbsp;</td>
            <td width="10%" class="dot-line text-center">{{ \Carbon\Carbon::parse($leave->created_at)->day }}</td>
            <td width="7%" class="text-center">เดือน</td>
            <td width="18%" class="dot-line text-center">{{ $thaiMonths[$createdMonth] }}</td>
            <td width="5%" class="text-center">พ.ศ.</td>
            <td width="10%" class="dot-line text-center">{{ $createdYear }}</td>
        </tr>
    </table>

    <table class="layout" style="margin-top: 5px;">
        <tr>
            <td width="5%">เรื่อง</td>
            <td width="40%" class="dot-line">&nbsp;ขอลาไปประกอบพิธีฮัจญ์</td>
            <td width="55%"></td>
        </tr>
        <tr>
            <td width="5%">เรียน</td>
            <td width="40%" class="dot-line">&nbsp;ผู้อำนวยการ{{ $settings['institution_name'] ?? '' }}</td>
            <td width="55%"></td>
        </tr>
    </table>

    <table class="layout" style="margin-top: 10px;">
        <tr>
            <td width="15%"></td>
            <td width="7%">ข้าพเจ้า</td>
            <td width="35%" class="dot-line text-center">{{ $leave->user->name ?? '' }}</td>
            <td width="8%" class="text-center">ตำแหน่ง</td>
            <td width="35%" class="dot-line text-center">{{ $leave->user->role->name ?? '' }}</td>
        </tr>
    </table>
    <table class="layout">
        <tr>
            <td width="5%">สังกัด</td>
            <td width="95%" class="dot-line">&nbsp;{{ $leave->user->department->name ?? '' }}</td>
        </tr>
    </table>

    <table class="layout" style="margin-top: 5px;">
        <tr>
            <td width="45%">ข้าพเจ้ามีความประสงค์ขอลาไปประกอบพิธีฮัจญ์ ณ</td>
            <td width="55%" class="dot-line text-center">เมืองเมกกะ ประเทศซาอุดีอาระเบีย</td>
        </tr>
    </table>
    
    <table class="layout" style="margin-top: 5px;">
        <tr>
            <td width="12%">ตั้งแต่วันที่</td>
            <td width="30%" class="dot-line text-center">{{ $thaiDateService->formatToThaiText($leave->start_date) }}</td>
            <td width="10%" class="text-center">ถึงวันที่</td>
            <td width="30%" class="dot-line text-center">{{ $thaiDateService->formatToThaiText($leave->end_date) }}</td>
            <td width="8%" class="text-center">มีกำหนด</td>
            <td width="7%" class="dot-line text-center">{{ $leave->total_days }}</td>
            <td width="3%" class="text-right">วัน</td>
        </tr>
    </table>

    <table class="layout" style="margin-top: 5px;">
        <tr>
            <td width="80%">ข้าพเจ้า ( @if(($leave->meta_data['hajj_status'] ?? '') == 'เคย') / @else &nbsp; @endif ) เคย ( @if(($leave->meta_data['hajj_status'] ?? '') == 'ยังไม่เคย') / @else &nbsp; @endif ) ยังไม่เคย ไปประกอบพิธีฮัจญ์</td>
            <td width="20%" class="text-center"></td>
        </tr>
    </table>
    <table class="layout">
        <tr>
            <td width="8%">ถึงวันที่</td>
            <td width="25%" class="dot-line text-center">{{ $lastLeave ? $thaiDateService->formatToThaiText($lastLeave->end_date) : '-' }}</td>
            <td width="8%" class="text-center">มีกำหนด</td>
            <td width="8%" class="dot-line text-center">{{ $lastLeave ? $lastLeave->total_days : '-' }}</td>
            <td width="30%" class="text-center">วัน ในระหว่างลาจะติดต่อข้าพเจ้าได้ที่</td>
            <td width="21%" class="dot-line text-center"></td>
        </tr>
    </table>
    <table class="layout">
        <tr>
            <td width="100%" class="dot-line text-left">&nbsp;{{ $leave->contact_address ?? '' }}</td>
        </tr>
    </table>
    <table class="layout">
        <tr>
            <td width="60%" class="dot-line text-center"></td>
            <td width="15%" class="text-center">หมายเลขโทรศัพท์</td>
            <td width="25%" class="dot-line text-center">{{ $leave->user->phone ?? '' }}</td>
        </tr>
    </table>

    <table class="layout" style="margin-top: 10px;">
        <tr>
            <td width="40%"></td>
            <td width="60%">
                @php
                    $sigPath = $leave->signature_path ?: ($leave->user->profile ? $leave->user->profile->signature_path : null);
                    $userSig = $sigPath ? \App\Support\StoredFile::path($sigPath) : null;
                @endphp
                <table class="layout">
                    <tr>
                        <td width="15%" class="text-right">(ลงชื่อ)</td>
                        <td width="70%" class="dot-line text-center">
                            @if($userSig && file_exists($userSig))
                                <img src="{{ $userSig }}" height="30" style="margin-bottom: -10px;">
                            @endif
                        </td>
                        <td width="15%"></td>
                    </tr>
                    <tr>
                        <td class="text-right">(</td>
                        <td class="text-center">&nbsp;{{ $leave->user->name ?? '' }}&nbsp;</td>
                        <td>)</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Bottom Section -->
    <table width="100%" border="0" cellpadding="0" cellspacing="0" style="margin-top: 5px;">
        <tr>
            <td width="48%" valign="top">
                <!-- Left Column -->
                <div style="text-align: center; font-weight: bold; margin-bottom: 5px;">สถิติการลาในปีงบประมาณนี้</div>
                <table class="table-stats">
                    <tr>
                        <th width="50%">ประเภทการลา</th>
                        <th width="50%">ลามาแล้ว (วัน)</th>
                    </tr>
                    <tr>
                        <td align="left">ลาไปประกอบพิธีฮัจญ์</td>
                        <td></td>
                    </tr>
                </table>
                
                @php 
                    $hrBlock = $approverBlocks[0] ?? null;
                    $hrName = $hrBlock ? $hrBlock['name'] : '........................................................';
                    $hrDate = $hrBlock ? $hrBlock['date'] : null;
                    $hrSig = $hrBlock ? $hrBlock['signature'] : null;
                    $hrPosition = $hrBlock ? $hrBlock['position'] : 'หัวหน้างานบุคลากร';
                @endphp
                <table class="layout" style="margin-top: 10px;">
                    <tr>
                        <td width="20%">(ลงชื่อ)</td>
                        <td width="70%" class="dot-line text-center">
                            @if($hrSig && file_exists($hrSig))
                                <img src="{{ $hrSig }}" height="30" style="margin-bottom: -10px;">
                            @endif
                        </td>
                        <td width="10%"></td>
                    </tr>
                    <tr>
                        <td class="text-right">(</td>
                        <td class="text-center">{{ $hrName }}</td>
                        <td>)</td>
                    </tr>
                    <tr>
                        <td colspan="3" class="text-center">ผู้ตรวจสอบ</td>
                    </tr>
                    <tr>
                        <td>ตำแหน่ง</td>
                        <td colspan="2" class="dot-line text-center">{{ $hrPosition }}</td>
                    </tr>
                    <tr>
                        <td>วันที่</td>
                        <td colspan="2" class="dot-line text-center">@if($hrDate) {{ $hrDate->day }} / {{ $thaiMonths[$hrDate->month] }} / {{ $hrDate->year + 543 }} @else ......./......./....... @endif</td>
                    </tr>
                </table>
            </td>
            <td width="4%"></td>
            <td width="48%" valign="top">
                <!-- Right Column -->
                <div style="font-weight: bold; margin-bottom: 5px;">ความเห็นผู้บังคับบัญชา</div>
                @php 
                    $deputyBlock = $approverBlocks[1] ?? null;
                    $deputyName = $deputyBlock ? $deputyBlock['name'] : '........................................................';
                    $deputyDate = $deputyBlock ? $deputyBlock['date'] : null;
                    $deputySig = $deputyBlock ? $deputyBlock['signature'] : null;
                    $deputyPosition = $deputyBlock ? $deputyBlock['position'] : 'รองผู้อำนวยการฝ่ายบริหารทรัพยากร';
                    $deputyComment = $deputyBlock ? $deputyBlock['comment'] : '&nbsp;';
                @endphp
                <table class="layout">
                    <tr><td class="dot-line text-center" style="color: #333;">{!! $deputyComment ?: '&nbsp;' !!}</td></tr>
                    <tr><td class="dot-line">&nbsp;</td></tr>
                </table>
                <table class="layout" style="margin-top: 5px;">
                    <tr>
                        <td width="20%">(ลงชื่อ)</td>
                        <td width="70%" class="dot-line text-center">
                            @if($deputySig && file_exists($deputySig))
                                <img src="{{ $deputySig }}" height="30" style="margin-bottom: -10px;">
                            @endif
                        </td>
                        <td width="10%"></td>
                    </tr>
                    <tr>
                        <td class="text-right">(</td>
                        <td class="text-center">{{ $deputyName }}</td>
                        <td>)</td>
                    </tr>
                    <tr>
                        <td>ตำแหน่ง</td>
                        <td colspan="2" class="dot-line text-center" style="font-size: 14pt;">{{ $deputyPosition }}</td>
                    </tr>
                    <tr>
                        <td>วันที่</td>
                        <td colspan="2" class="dot-line text-center">@if($deputyDate) {{ $deputyDate->day }} / {{ $thaiMonths[$deputyDate->month] }} / {{ $deputyDate->year + 543 }} @else ......./......./....... @endif</td>
                    </tr>
                </table>

                <div style="font-weight: bold; margin-top: 10px; margin-bottom: 5px;">คำสั่ง</div>
                <table class="layout">
                    <tr>
                        <td width="10%"></td>
                        <td width="10%">( {!! isset($approverBlocks[2]) && $approverBlocks[2]['status'] === 'Approved' ? '/' : '&nbsp;&nbsp;&nbsp;' !!} )</td>
                        <td width="30%">อนุญาต</td>
                        <td width="10%">( {!! isset($approverBlocks[2]) && $approverBlocks[2]['status'] === 'Rejected' ? '/' : '&nbsp;&nbsp;&nbsp;' !!} )</td>
                        <td width="40%">ไม่อนุญาต</td>
                    </tr>
                </table>
                @php 
                    $directorBlock = $approverBlocks[2] ?? null;
                    $directorName = $directorBlock ? $directorBlock['name'] : '........................................................';
                    $directorDate = $directorBlock ? $directorBlock['date'] : null;
                    $directorSig = $directorBlock ? $directorBlock['signature'] : null;
                    $directorPosition = $directorBlock ? $directorBlock['position'] : 'ผู้อำนวยการ';
                    if (strpos($directorPosition, $settings['institution_name'] ?? '') === false) {
                        $directorPosition .= $settings['institution_name'] ?? '';
                    }
                    $directorComment = $directorBlock ? $directorBlock['comment'] : '&nbsp;';
                @endphp
                <table class="layout">
                    <tr><td class="dot-line text-center" style="color: #333;">{!! $directorComment ?: '&nbsp;' !!}</td></tr>
                    <tr><td class="dot-line">&nbsp;</td></tr>
                </table>
                <table class="layout" style="margin-top: 5px;">
                    <tr>
                        <td width="20%">(ลงชื่อ)</td>
                        <td width="70%" class="dot-line text-center">
                            @if($directorSig && file_exists($directorSig))
                                <img src="{{ $directorSig }}" height="30" style="margin-bottom: -10px;">
                            @endif
                        </td>
                        <td width="10%"></td>
                    </tr>
                    <tr>
                        <td class="text-right">(</td>
                        <td class="text-center">{{ $directorName }}</td>
                        <td>)</td>
                    </tr>
                    <tr>
                        <td>ตำแหน่ง</td>
                        <td colspan="2" class="dot-line text-center" style="font-size: 14pt;">{{ $directorPosition }}</td>
                    </tr>
                    <tr>
                        <td>วันที่</td>
                        <td colspan="2" class="dot-line text-center">@if($directorDate) {{ $directorDate->day }} / {{ $thaiMonths[$directorDate->month] }} / {{ $directorDate->year + 543 }} @else ......./......./....... @endif</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div style="position: absolute; bottom: 20px; left: 30px; font-size: 14pt;">
        {{ $leave->document_number ?? '' }}
    </div>
@include('pdf.partials.verification_qr')
</body>
</html>
