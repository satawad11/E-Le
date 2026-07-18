<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>สรุปผลการปฏิบัติงานตลอดทั้งปี</title>
    @php
        $fontRegular = isset($export) && $export ? public_path('fonts/THSarabunNew/THSarabunNew.ttf') : asset('fonts/THSarabunNew/THSarabunNew.ttf');
        $fontBold = isset($export) && $export ? public_path('fonts/THSarabunNew/THSarabunNew Bold.ttf') : asset('fonts/THSarabunNew/THSarabunNew Bold.ttf');
    @endphp
    <style>
        @page {
            size: A4 landscape;
            margin: 1cm;
        }
        @font-face {
            font-family: 'THSarabunNew';
            font-style: normal;
            font-weight: normal;
            src: url("{{ $fontRegular }}") format('truetype');
        }
        @font-face {
            font-family: 'THSarabunNew';
            font-style: normal;
            font-weight: bold;
            src: url("{{ $fontBold }}") format('truetype');
        }
        body {
            font-family: 'THSarabunNew', sans-serif;
            font-size: 14pt;
            line-height: 1.1;
            margin: 0;
            padding: 0;
        }
        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .font-bold { font-weight: bold; }
        .mb-2 { margin-bottom: 0.5rem; }
        .mb-4 { margin-bottom: 1rem; }
        .logo {
            width: 70px;
            height: 70px;
            object-fit: contain;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }
        th, td {
            border: 1px solid #000;
            padding: 2px 4px;
            text-align: center;
        }
        th {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        .page-break {
            page-break-after: always;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                margin: 1cm;
            }
        }
        .action-bar {
            background: #f8f9fa;
            padding: 10px 20px;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-bottom: 20px;
        }
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-family: 'THSarabunNew', sans-serif;
            font-size: 14pt;
            text-decoration: none;
            display: inline-block;
        }
        .btn-print { background: #6c757d; color: white; }
    </style>
</head>
<body>
    @if(!isset($export) || !$export)
    <div class="no-print action-bar">
        <button onclick="window.print()" class="btn btn-print">🖨️ พิมพ์ (Print)</button>
    </div>
    @endif

    @php
        $logoPath = isset($settings['organization_logo']) && $settings['organization_logo'] ? public_path(str_replace('/storage/', 'storage/', $settings['organization_logo'])) : null;
        $collegeName = $settings['institution_name'] ?? 'วิทยาลัย................';
        $yearThai = $year + 543;
    @endphp

    <div style="position: relative;">
        <div class="text-center mb-4">
            @if($logoPath && file_exists($logoPath))
                <img src="{{ isset($export) && $export ? $logoPath : asset(str_replace('public/', 'storage/', $settings['organization_logo'])) }}" class="logo" alt="Logo">
            @endif
            <div class="font-bold" style="font-size: 16pt;">สรุปผลการปฏิบัติงานตลอดทั้งปี</div>
            <div class="font-bold" style="font-size: 15pt;">ประจำปี พ.ศ. {{ $yearThai }}</div>
            <div style="font-size: 14pt;">ชื่อ: {{ $user['name'] }} | ตำแหน่ง: {{ $user['position'] }} | แผนก: {{ $user['department'] }}</div>
        </div>

        <table>
            <thead>
                <tr>
                    <th rowspan="2" style="width: 10%;">เดือน</th>
                    <th colspan="31">วันที่</th>
                    <th rowspan="2" style="width: 5%;">รวม<br>(วัน)</th>
                    <th rowspan="2" style="width: 4%;">ลากิจ</th>
                    <th rowspan="2" style="width: 4%;">ป่วย</th>
                    <th rowspan="2" style="width: 4%;">ลาคลอด</th>
                    <th rowspan="2" style="width: 4%;">ราชการ</th>
                </tr>
                <tr>
                    @for($i = 1; $i <= 31; $i++)
                        <th style="width: {{ 69 / 31 }}%; font-size: 12pt;">{{ $i }}</th>
                    @endfor
                </tr>
            </thead>
            <tbody>
                @foreach($months as $monthData)
                    <tr>
                        <td class="text-left font-bold">{{ $monthData['month_name'] }}</td>
                        
                        @for($d = 1; $d <= 31; $d++)
                            @if($d <= $monthData['days_in_month'])
                                @php
                                    $val = $monthData['attendances'][$d] ?? '-';
                                    $color = '';
                                    if ($val === 1) $color = 'green';
                                    elseif ($val === 0) $color = 'red';
                                    elseif (in_array($val, ['ก', 'ป', 'ด', 'ร'])) $color = 'blue';
                                @endphp
                                <td style="color: {{ $color }};">{{ $val }}</td>
                            @else
                                <td style="background-color: #e9ecef;"></td>
                            @endif
                        @endfor
                        
                        <td class="font-bold">{{ $monthData['total_score'] }}</td>
                        <td style="color: blue;">{{ $monthData['total_personal'] ?: '-' }}</td>
                        <td style="color: blue;">{{ $monthData['total_sick'] ?: '-' }}</td>
                        <td style="color: blue;">{{ $monthData['total_maternity'] ?: '-' }}</td>
                        <td style="color: blue;">{{ $monthData['total_official'] ?: '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

</body>
</html>
