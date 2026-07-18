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
    <div class="text-center font-bold" style="font-size: 18pt; margin-bottom: 5px;">
        แบบใบขอยกเลิกวันลา
    </div>
    
    <table class="layout">
        <tr>
            <td width="55%"></td>
            <td width="10%">(เขียนที่)</td>
            <td width="35%" class="dot-line text-center">{{ $settings['institution_name'] ?? '' }}</td>
        </tr>
    </table>
    
    @php
        $thaiMonths = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $createdMonth = \Carbon\Carbon::parse($leave->created_at)->month;
        $createdYear = \Carbon\Carbon::parse($leave->created_at)->year + 543;
        
        // Use cancellation_date if available
        $cancelDate = $leave->cancellation_date ? \Carbon\Carbon::parse($leave->cancellation_date) : \Carbon\Carbon::parse($leave->created_at);
    @endphp

    <table class="layout">
        <tr>
            <td width="45%"></td>
            <td width="5%" class="text-right">วันที่&nbsp;</td>
            <td width="10%" class="dot-line text-center">{{ $cancelDate->day }}</td>
            <td width="7%" class="text-center">เดือน</td>
            <td width="18%" class="dot-line text-center">{{ $thaiMonths[$cancelDate->month] }}</td>
            <td width="5%" class="text-center">พ.ศ.</td>
            <td width="10%" class="dot-line text-center">{{ $cancelDate->year + 543 }}</td>
        </tr>
    </table>

    <table class="layout" style="margin-top: 5px;">
        <tr>
            <td width="5%">เรื่อง</td>
            <td width="40%" class="dot-line">&nbsp;ขอยกเลิกวันลา{{ $leave->type->name ?? '' }}</td>
            <td width="55%"></td>
        </tr>
        <tr>
            <td width="5%">เรียน</td>
            <td width="40%" class="dot-line">&nbsp;ผู้อำนวยการ{{ $settings['institution_name'] ?? '' }}</td>
            <td width="55%"></td>
        </tr>
    </table>

    <table class="layout" style="margin-top: 5px;">
        <tr>
            <td width="15%"></td>
            <td width="10%">ตามที่ข้าพเจ้า</td>
            <td width="30%" class="dot-line text-center">{{ $leave->user->name ?? '' }}</td>
            <td width="10%" class="text-center">ตำแหน่ง</td>
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
            <td width="20%">ได้รับอนุญาตให้ลา</td>
            <td width="30%" class="dot-line text-center">{{ $leave->type->name ?? '' }}</td>
            <td width="12%" class="text-center">ตั้งแต่วันที่</td>
            <td width="38%" class="dot-line text-center">{{ $thaiDateService->formatToThaiText($leave->start_date) }}</td>
        </tr>
    </table>
    <table class="layout">
        <tr>
            <td width="8%">ถึงวันที่</td>
            <td width="42%" class="dot-line text-center">{{ $thaiDateService->formatToThaiText($leave->end_date) }}</td>
            <td width="5%" class="text-center">รวม</td>
            <td width="10%" class="dot-line text-center">{{ $leave->total_days }}</td>
            <td width="35%" class="text-left">วัน นั้น</td>
        </tr>
    </table>

    <table class="layout">
        <tr>
            <td width="10%">เนื่องจาก</td>
            <td width="90%" class="dot-line">&nbsp;{{ $leave->cancellation_reason ?? '' }}</td>
        </tr>
    </table>

    <table class="layout" style="margin-top: 5px;">
        <tr>
            <td width="20%">จึงขอยกเลิกวันลา</td>
            <td width="45%" class="dot-line text-center">{{ $leave->type->name ?? '' }}</td>
            <td width="10%" class="text-center">จำนวน</td>
            <td width="10%" class="dot-line text-center">{{ $leave->total_days }}</td>
            <td width="15%" class="text-right">วัน</td>
        </tr>
    </table>
    
    <table class="layout">
        <tr>
            <td width="10%">ตั้งแต่วันที่</td>
            <td width="40%" class="dot-line text-center">{{ $thaiDateService->formatToThaiText($leave->start_date) }}</td>
            <td width="10%" class="text-center">ถึงวันที่</td>
            <td width="40%" class="dot-line text-center">{{ $thaiDateService->formatToThaiText($leave->end_date) }}</td>
        </tr>
    </table>

    <div class="text-center" style="margin-top: 10px;">
        ขอแสดงความนับถือ
    </div>

    <table class="layout" style="margin-top: 5px;">
        <tr>
            <td width="40%"></td>
            <td width="60%">
                @php
                    $sigPath = $leave->signature_path ?: ($leave->user->profile ? $leave->user->profile->signature_path : null);
                    $userSig = $sigPath ? public_path(str_replace('/storage/', 'storage/', $sigPath)) : null;
                @endphp
                <table class="layout">
                    <tr>
                        <td width="20%" class="text-right">(ลงชื่อ)</td>
                        <td width="60%" class="dot-line text-center">
                            @if($userSig && file_exists($userSig))
                                <img src="{{ $userSig }}" height="30" style="margin-bottom: -10px;">
                            @endif
                        </td>
                        <td width="20%"></td>
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

    <div style="margin-top: 10px;">
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
            <tr><td class="dot-line text-center">&nbsp;</td></tr>
        </table>
        
        <table class="layout" style="margin-top: 5px;">
            <tr>
                <td width="40%"></td>
                <td width="60%">
                    <table class="layout">
                        <tr>
                            <td width="20%" class="text-right">(ลงชื่อ)</td>
                            <td width="60%" class="dot-line text-center">
                                @if($deputySig && file_exists($deputySig))
                                    <img src="{{ $deputySig }}" height="30" style="margin-bottom: -10px;">
                                @endif
                            </td>
                            <td width="20%"></td>
                        </tr>
                        <tr>
                            <td class="text-right">(</td>
                            <td class="text-center">{{ $deputyName }}</td>
                            <td>)</td>
                        </tr>
                        <tr>
                            <td class="text-right">(ตำแหน่ง)</td>
                            <td class="dot-line text-center">{{ $deputyPosition }}</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="text-right">วันที่</td>
                            <td class="dot-line text-center">@if($deputyDate) {{ $deputyDate->day }} / {{ $thaiMonths[$deputyDate->month] }} / {{ $deputyDate->year + 543 }} @else ......./......./....... @endif</td>
                            <td></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <div style="font-weight: bold; margin-top: 10px; margin-bottom: 5px;">คำสั่ง</div>
        
        <table class="layout">
            <tr>
                <td width="15%"></td>
                <td width="10%">( {!! isset($approverBlocks[2]) && $approverBlocks[2]['status'] === 'Approved' ? '/' : '&nbsp;&nbsp;&nbsp;' !!} )</td>
                <td width="25%">อนุญาต</td>
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

        <table class="layout" style="margin-top: 5px;">
            <tr><td class="dot-line text-center" style="color: #333;">{!! $directorComment ?: '&nbsp;' !!}</td></tr>
            <tr><td class="dot-line text-center">&nbsp;</td></tr>
        </table>

        <table class="layout" style="margin-top: 5px;">
            <tr>
                <td width="40%"></td>
                <td width="60%">
                    <table class="layout">
                        <tr>
                            <td width="20%" class="text-right">(ลงชื่อ)</td>
                            <td width="60%" class="dot-line text-center">
                                @if($directorSig && file_exists($directorSig))
                                    <img src="{{ $directorSig }}" height="30" style="margin-bottom: -10px;">
                                @endif
                            </td>
                            <td width="20%"></td>
                        </tr>
                        <tr>
                            <td class="text-right">(</td>
                            <td class="text-center">{{ $directorName }}</td>
                            <td>)</td>
                        </tr>
                        <tr>
                            <td class="text-right">(ตำแหน่ง)</td>
                            <td class="dot-line text-center">{{ $directorPosition }}</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td class="text-right">วันที่</td>
                            <td class="dot-line text-center">@if($directorDate) {{ $directorDate->day }} / {{ $thaiMonths[$directorDate->month] }} / {{ $directorDate->year + 543 }} @else ......./......./....... @endif</td>
                            <td></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div style="position: absolute; bottom: 20px; left: 30px; font-size: 14pt;">
        {{ $leave->document_number ?? '' }}
    </div>
</body>
</html>
