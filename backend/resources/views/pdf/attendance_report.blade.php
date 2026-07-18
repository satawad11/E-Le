<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายงานการลงเวลาปฏิบัติราชการ</title>
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
        body {
            font-family: 'THSarabunNew', sans-serif;
            font-size: 16pt;
            line-height: 1.2;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-bold { font-weight: bold; }
        .mb-2 { margin-bottom: 0.5rem; }
        .mb-4 { margin-bottom: 1rem; }
        .logo {
            width: 80px;
            height: 80px;
            object-fit: contain;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            border: 1px solid #000;
            padding: 5px;
            text-align: center;
            font-size: 14pt;
        }
        th {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        .page-break {
            page-break-after: always;
        }
        .text-red { color: red; }
    </style>
</head>
<body>

    @php
        $logoPath = isset($settings['organization_logo']) && $settings['organization_logo'] ? public_path(str_replace('/storage/', 'storage/', $settings['organization_logo'])) : null;
        $collegeName = $settings['institution_name'] ?? 'วิทยาลัย................';
        
        // Chunk records into pages of 25
        $pages = $attendances->chunk(25);
        if ($pages->isEmpty()) {
            $pages = collect([collect([])]); // Ensure at least one page
        }
    @endphp

    @foreach ($pages as $index => $pageRecords)
        <div class="text-center mb-4">
            @if($logoPath && file_exists($logoPath))
                <img src="{{ $logoPath }}" class="logo" alt="Logo">
            @else
                <!-- Placeholder for logo if not found -->
                <div style="width: 80px; height: 80px; border: 1px solid #ccc; display: inline-block;"></div>
            @endif
            <div class="font-bold" style="font-size: 18pt; margin-top: 10px;">รายงานการลงเวลาปฏิบัติราชการ</div>
            <div class="font-bold" style="font-size: 16pt;">{{ $collegeName }}</div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">ลำดับ</th>
                    <th style="width: 15%;">ชื่อ-สกุล</th>
                    <th style="width: 15%;">แผนก</th>
                    <th style="width: 10%;">วันที่</th>
                    <th style="width: 8%;">เวลาเข้า</th>
                    <th style="width: 8%;">เวลาออก</th>
                    <th style="width: 8%;">เข้า OT</th>
                    <th style="width: 8%;">ออก OT</th>
                    <th style="width: 10%;">สถานะ</th>
                    <th style="width: 13%;">หมายเหตุ</th>
                </tr>
            </thead>
            <tbody>
                @forelse($pageRecords as $i => $record)
                    @php
                        $statusText = 'มาปกติ';
                        if ($record->status == 'Late') $statusText = 'มาสาย';
                        elseif ($record->status == 'Absent') $statusText = 'ขาดงาน';
                        elseif ($record->status == 'Leave') $statusText = 'ลา';
                        
                        $lateInfo = $record->late_minutes > 0 ? "<br><span class='text-red'>(สาย {$record->late_minutes} นาที)</span>" : "";
                        $earlyInfo = $record->early_leave_minutes > 0 ? "<br><span class='text-red'>(ออกก่อน {$record->early_leave_minutes} นาที)</span>" : "";
                        
                        $otInfo = "";
                        if ($record->ot_minutes > 0) {
                            $otH = floor($record->ot_minutes / 60);
                            $otM = $record->ot_minutes % 60;
                            $otInfo = "<br><span style='color:purple; font-size: 12pt;'>(OT {$otH} ชม. {$otM} น.)</span>";
                        }
                    @endphp
                    <tr>
                        <td>{{ ($index * 25) + $i + 1 }}</td>
                        <td class="text-left">{{ $record->user->name ?? '-' }}</td>
                        <td>{{ $record->user->department->name ?? '-' }}</td>
                        <td>{{ \Carbon\Carbon::parse($record->date)->addYears(543)->format('d/m/Y') }}</td>
                        <td>
                            {{ $record->check_in_time ? \Carbon\Carbon::parse($record->check_in_time)->format('H:i') : '-' }}
                            {!! $lateInfo !!}
                        </td>
                        <td>
                            {{ $record->check_out_time ? \Carbon\Carbon::parse($record->check_out_time)->format('H:i') : '-' }}
                            {!! $earlyInfo !!}
                        </td>
                        <td>{{ $record->ot_check_in_time ? \Carbon\Carbon::parse($record->ot_check_in_time)->format('H:i') : '-' }}</td>
                        <td>
                            {{ $record->ot_check_out_time ? \Carbon\Carbon::parse($record->ot_check_out_time)->format('H:i') : '-' }}
                            {!! $otInfo !!}
                        </td>
                        <td>{{ $statusText }}</td>
                        <td class="text-left">{{ $record->notes ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10">ไม่พบข้อมูล</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if (!$loop->last)
            <div class="page-break"></div>
        @endif
    @endforeach

</body>
</html>
