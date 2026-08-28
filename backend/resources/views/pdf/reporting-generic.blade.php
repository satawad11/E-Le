<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>{{ $dataset->title }}</title>
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
        @page { margin: 20px; }
        body { font-family: 'THSarabunNew', sans-serif; font-size: 14pt; color: #1f2937; }
        h1 { font-size: 20pt; margin: 0 0 4px; text-align: center; }
        .meta { text-align: center; color: #4b5563; font-size: 13pt; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #6b7280; padding: 4px 6px; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; }
        td.num, th.num { text-align: right; }
        tfoot td { font-weight: bold; background: #f9fafb; }
        .empty { text-align: center; color: #6b7280; }
    </style>
</head>
<body>
@php
    $thaiDate = static function (?string $value): string {
        if (! $value) {
            return '-';
        }
        $date = \Carbon\Carbon::parse($value);

        return $date->format('d/m/').($date->year + 543);
    };
@endphp
<h1>{{ $dataset->title }}</h1>
<div class="meta">
    {{-- `fiscal_years.year` เก็บเป็น พ.ศ. อยู่แล้ว จึงไม่ต้องบวก 543 ซ้ำ --}}
    ปีงบประมาณ {{ (int) data_get($dataset->meta, 'fiscal_year') }} ·
    ข้อมูลระหว่างวันที่ {{ $thaiDate(data_get($dataset->meta, 'from')) }}
    ถึง {{ $thaiDate(data_get($dataset->meta, 'to')) }} ·
    {{ number_format((int) data_get($dataset->meta, 'row_count')) }} รายการ
</div>
<table>
    <thead>
    <tr>
        <th style="width: 34px;" class="num">ลำดับ</th>
        @foreach ($dataset->columns as $key => $label)
            <th class="{{ isset($dataset->rows[0][$key]) && is_numeric($dataset->rows[0][$key]) ? 'num' : '' }}">{{ $label }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @forelse ($dataset->rows as $index => $row)
        <tr>
            <td class="num">{{ $index + 1 }}</td>
            @foreach ($dataset->columns as $key => $label)
                @php $value = $row[$key] ?? null; @endphp
                <td class="{{ is_numeric($value) ? 'num' : '' }}">
                    @if (is_bool($value))
                        {{ $value ? 'ใช้งาน' : 'ปิดใช้งาน' }}
                    @elseif (is_numeric($value))
                        {{ number_format((float) $value, fmod((float) $value, 1) === 0.0 ? 0 : 2) }}
                    @else
                        {{ $value ?? '-' }}
                    @endif
                </td>
            @endforeach
        </tr>
    @empty
        <tr><td class="empty" colspan="{{ count($dataset->columns) + 1 }}">ไม่พบข้อมูลตามเงื่อนไขที่เลือก</td></tr>
    @endforelse
    </tbody>
    @if ($dataset->totals !== [])
        <tfoot>
        <tr>
            <td colspan="{{ count($dataset->columns) + 1 }}">
                รวม:
                @foreach ($dataset->totals as $metric => $value)
                    {{ $dataset->totalLabel($metric) }}
                    {{ is_numeric($value) ? number_format((float) $value, fmod((float) $value, 1) === 0.0 ? 0 : 2) : $value }}@if (! $loop->last) · @endif
                @endforeach
            </td>
        </tr>
        </tfoot>
    @endif
</table>
</body>
</html>
