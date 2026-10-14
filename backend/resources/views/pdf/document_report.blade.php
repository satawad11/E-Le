{{--
    แม่แบบรายงานกลางของ Document & Report Engine

    ใช้กับรายงานทุกฉบับที่ผู้ดูแลสร้างจากหน้าจอ โครงเอกสาร (หัวรายงาน คอลัมน์ การจัดกลุ่ม
    ยอดรวมรายกลุ่ม ยอดรวมทั้งสิ้น ผู้ลงนาม เลขหน้า) มาจากนิยามรายงานทั้งหมด
--}}
@php
    $layout = $definition->layout ?? [];
    $columns = $columns ?? [];
    $settings = \App\Models\Setting::pluck('value', 'key');
@endphp
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 24px 46px 24px; }
        body { font-family: 'THSarabunNew', sans-serif; font-size: 14px; color: #000; }
        .header { text-align: center; margin-bottom: 14px; }
        .header h1 { font-size: 20px; margin: 0 0 2px; }
        .header p { margin: 0; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #444; padding: 4px 6px; }
        th { background: #eee; font-weight: bold; text-align: center; }
        td.num { text-align: right; }
        tr.group td { background: #f5f5f5; font-weight: bold; }
        tr.subtotal td, tr.grand td { font-weight: bold; background: #fafafa; }
        tr.grand td { background: #eaeaea; }
        .signature { margin-top: 36px; text-align: right; }
        .signature .line { display: inline-block; text-align: center; min-width: 220px; }
        .footer { position: fixed; bottom: -28px; left: 0; right: 0; text-align: center; font-size: 12px; color: #555; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $layout['title'] ?? $definition->name_th }}</h1>
        <p>{{ $settings['school_name'] ?? $settings['organization_name'] ?? '' }}</p>
        @if (! empty($dataset->meta['from']))
            <p>ระหว่างวันที่ {{ $dataset->meta['from'] }} ถึง {{ $dataset->meta['to'] ?? '' }}</p>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 36px;">ลำดับ</th>
                @foreach ($columns as $key => $label)
                    <th>{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @php $running = 0; @endphp
            @foreach ($groups as $group)
                @if ($group['label'] !== '')
                    <tr class="group">
                        <td colspan="{{ count($columns) + 1 }}">{{ $group['label'] }}</td>
                    </tr>
                @endif

                @foreach ($group['rows'] as $row)
                    @php $running++; @endphp
                    <tr>
                        <td style="text-align: center;">{{ $running }}</td>
                        @foreach ($columns as $key => $label)
                            <td class="{{ is_numeric($row[$key] ?? null) ? 'num' : '' }}">{{ $row[$key] ?? '' }}</td>
                        @endforeach
                    </tr>
                @endforeach

                @if ($group['label'] !== '' && ! empty($group['subtotal']))
                    <tr class="subtotal">
                        <td colspan="1"></td>
                        @foreach ($columns as $key => $label)
                            <td class="num">{{ $loop->first ? 'รวม '.$group['label'] : ($group['subtotal'][$key] ?? '') }}</td>
                        @endforeach
                    </tr>
                @endif
            @endforeach

            @if (! empty($totals))
                <tr class="grand">
                    <td></td>
                    @foreach ($columns as $key => $label)
                        <td class="num">{{ $loop->first ? 'รวมทั้งสิ้น' : ($totals[$key] ?? '') }}</td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>

    @if (! empty($layout['signature']))
        <div class="signature">
            <div class="line">
                <p>ลงชื่อ ..............................................</p>
                <p>( {{ $layout['signature']['name'] ?? $settings['director_name'] ?? '' }} )</p>
                <p>{{ $layout['signature']['position'] ?? $settings['director_position'] ?? '' }}</p>
            </div>
        </div>
    @endif

    <div class="footer">หน้า <span class="pagenum"></span></div>
    <script type="text/php">
        if (isset($pdf)) {
            $pdf->page_text(520, 812, "หน้า {PAGE_NUM}/{PAGE_COUNT}", null, 10, [0.3, 0.3, 0.3]);
        }
    </script>
</body>
</html>
