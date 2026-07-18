<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>สรุปผลการปฏิบัติงาน</title>
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
        .btn-pdf { background: #dc3545; color: white; }
        .btn-word { background: #0d6efd; color: white; }
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
        $thaiMonths = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $monthName = $thaiMonths[(int)$month] ?? '';
        $yearThai = $year + 543;
        
        // Chunk records into pages of 15-20 depending on space
        // Let's use 20 for A4 landscape
        $chunks = collect($records)->chunk(20);
        if ($chunks->isEmpty()) {
            $chunks = collect([collect([])]);
        }
    @endphp

    @foreach ($chunks as $index => $pageRecords)
        <div style="position: relative;">
            <div style="position: absolute; top: 0px; right: 0px; font-size: 14pt;">
                หน้า {{ $index + 1 }} / {{ $chunks->count() }}
            </div>
            <div class="text-center mb-4">
                @if($logoPath && file_exists($logoPath))
                    <img src="{{ isset($export) && $export ? $logoPath : asset(str_replace('public/', 'storage/', $settings['organization_logo'])) }}" class="logo" alt="Logo">
                @endif
                <div class="font-bold" style="font-size: 16pt;">สรุปผลการปฏิบัติงาน</div>
                <div class="font-bold" style="font-size: 15pt;">ประจำเดือน {{ $monthName }} พ.ศ. {{ $yearThai }}</div>
                <div style="font-size: 14pt;">{{ $collegeName }}</div>
            </div>

        <table>
            <thead>
                <tr>
                    <th rowspan="2" style="width: 3%;">ที่</th>
                    <th rowspan="2" style="width: 15%;">ชื่อ-สกุล</th>
                    <th rowspan="2" style="width: 10%;">ตำแหน่ง</th>
                    <th colspan="{{ $daysInMonth }}">วันที่</th>
                    <th rowspan="2" style="width: 5%;">รวม<br>(วัน)</th>
                    <th rowspan="2" style="width: 4%;">ลากิจ</th>
                    <th rowspan="2" style="width: 4%;">ป่วย</th>
                    <th rowspan="2" style="width: 4%;">ลาคลอด</th>
                    <th rowspan="2" style="width: 4%;">ราชการ</th>
                </tr>
                <tr>
                    @for($i = 1; $i <= $daysInMonth; $i++)
                        <th style="width: {{ 55 / $daysInMonth }}%; font-size: 12pt;">{{ $i }}</th>
                    @endfor
                </tr>
            </thead>
            <tbody>
                @forelse($pageRecords as $i => $record)
                    <tr>
                        <td>{{ ($index * 20) + $loop->iteration }}</td>
                        <td class="text-left">{{ $record['name'] }}</td>
                        <td>{{ $record['position'] ?: '-' }}</td>
                        
                        @for($d = 1; $d <= $daysInMonth; $d++)
                            @php
                                $val = $record['attendances'][$d] ?? '-';
                                $color = '';
                                if ($val === 1) $color = 'green';
                                elseif ($val === 0) $color = 'red';
                                elseif (in_array($val, ['ก', 'ป', 'ด', 'ร'])) $color = 'blue';
                            @endphp
                            <td style="color: {{ $color }};">{{ $val }}</td>
                        @endfor
                        
                        <td class="font-bold">{{ $record['total_score'] }}</td>
                        <td style="color: blue;">{{ $record['total_personal'] ?: '-' }}</td>
                        <td style="color: blue;">{{ $record['total_sick'] ?: '-' }}</td>
                        <td style="color: blue;">{{ $record['total_maternity'] ?: '-' }}</td>
                        <td style="color: blue;">{{ $record['total_official'] ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 8 + $daysInMonth }}">ไม่พบข้อมูล</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        @if (!$loop->last)
            <div class="page-break"></div>
        @endif
    @endforeach
</body>
</html>
