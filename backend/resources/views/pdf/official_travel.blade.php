{{--
    บันทึกข้อความ ขออนุญาตเดินทางไปราชการ

    ทุกค่ามาจากชุดตัวแปรกลาง `App\Services\LeaveDocumentFields` และหน้าตั้งค่าระบบ
    ไม่มีชื่อสถานศึกษา ชื่อฝ่าย หรือชื่อผู้บริหารเขียนตายในไฟล์ (PROJECT_RULES: ห้าม hard-code)
    ช่องความเห็นสร้างจากสายอนุมัติที่ตั้งค่าไว้ (approverBlocks) ตามลำดับขั้น

    ช่องเฉพาะแบบฟอร์ม (หน้าตั้งค่าประเภทการลา): destination, ref_doc_no, expense_type,
    companion_1 … companion_6 — รองรับค่าที่เก็บไว้แบบเดิมใน meta_data ด้วย
--}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>บันทึกข้อความ ขออนุญาตเดินทางไปราชการ</title>
    @include('pdf.partials.form_styles')
    <style>
        .memo-title { font-size: 26pt; font-weight: bold; line-height: 1; }
        .approvals { width: 100%; border-collapse: collapse; font-size: 13pt; margin-top: 8px; }
        .approvals td { width: 50%; vertical-align: top; padding: 0 6px 6px 0; }
    </style>
</head>
<body>

@php
    $meta = is_array($leave->meta_data ?? null) ? $leave->meta_data : [];
    $field = fn (string $key) => (string) (($extra[$key] ?? '') !== '' ? $extra[$key] : ($meta[$key] ?? ''));

    $garudaSetting = $settings['saraban_garuda_logo'] ?? ($settings['garuda_logo_url'] ?? null);
    $garuda = $garudaSetting ? \App\Support\StoredFile::path($garudaSetting) : null;
    $garuda = $garuda && is_file($garuda) ? $garuda : null;

    // รองรับค่าที่เก็บแบบเดิม (budget/no_budget/vehicle_only) และค่าภาษาไทยจากช่องกรอกของประเภทการลา
    $expense = match ($field('expense_type')) {
        'budget', 'เบิกตามสิทธิ' => 'budget',
        'no_budget', 'ไม่เบิกค่าใช้จ่าย' => 'no_budget',
        'vehicle_only', 'เบิกเฉพาะค่ายานพาหนะ' => 'vehicle_only',
        default => '',
    };
    $companions = collect(range(1, 6))->map(fn ($i) => $field('companion_'.$i));
    $thaiShort = fn ($date) => \App\Support\FormDate::short($date);
@endphp

<table class="row">
    <tr>
        <td width="16%" style="vertical-align: bottom;">
            @if ($garuda)
                <img src="{{ $garuda }}" alt="" style="width: 1.5cm; height: auto;">
            @endif
        </td>
        <td width="84%" class="memo-title" style="vertical-align: bottom;">บันทึกข้อความ</td>
    </tr>
</table>

<table class="row" style="margin-top: 6px;">
    <tr>
        <td width="11%" class="bold">ส่วนราชการ</td>
        <td width="89%" class="dot-left">&nbsp;{{ $organization_line ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="4%" class="bold">ที่</td>
        <td width="44%" class="dot-left">&nbsp;{{ $doc_number ?? '' }}</td>
        <td width="7%" class="bold text-right">วันที่&nbsp;</td>
        <td width="45%" class="dot-left">&nbsp;{{ $doc_date_thai ?? '' }}</td>
    </tr>
    <tr>
        <td width="4%" class="bold">เรื่อง</td>
        <td width="96%" colspan="3">ขออนุญาตเดินทางไปราชการ</td>
    </tr>
    <tr>
        <td width="4%" class="bold">เรียน</td>
        <td width="96%" colspan="3">{{ $director_position ?? '' }}</td>
    </tr>
</table>

<table class="row" style="margin-top: 6px;">
    <tr>
        <td width="14%"></td>
        <td width="8%">ข้าพเจ้า</td>
        <td width="40%" class="dot-left">&nbsp;{{ $user_name ?? '' }}</td>
        <td width="8%" class="text-center">ตำแหน่ง</td>
        <td width="30%" class="dot-left">&nbsp;{{ $position ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="13%">แผนกวิชา/งาน</td>
        <td width="87%" class="dot-left">&nbsp;{{ $division ?? '' }}</td>
    </tr>
</table>

@if ($companions->filter()->isNotEmpty())
    <div style="margin-top: 4px;">พร้อมด้วย</div>
    <table class="row">
        @foreach ($companions->chunk(2) as $pair)
            <tr>
                @foreach ($pair as $index => $name)
                    <td width="4%">{{ $index + 1 }})</td>
                    <td width="46%" class="dot-left">&nbsp;{{ $name }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>
@endif

<table class="row" style="margin-top: 4px;">
    <tr>
        <td width="22%">ขออนุญาตเดินทางไปราชการ ณ</td>
        <td width="78%" class="dot-left">&nbsp;{{ $field('destination') }}</td>
    </tr>
    <tr>
        <td width="22%">เพื่อ</td>
        <td width="78%" class="dot-left">&nbsp;{{ $reason ?? '' }}</td>
    </tr>
    <tr>
        <td width="22%">ตามหนังสือที่</td>
        <td width="78%" class="dot-left">&nbsp;{{ $field('ref_doc_no') ?: '-' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="10%">ตั้งแต่วันที่</td>
        <td width="28%" class="dot">{{ $start_date_thai ?? '' }}</td>
        <td width="8%" class="text-center">ถึงวันที่</td>
        <td width="28%" class="dot">{{ $end_date_thai ?? '' }}</td>
        <td width="10%" class="text-center">มีกำหนด</td>
        <td width="8%" class="dot">{{ $total_days ?? '' }}</td>
        <td width="8%" class="text-center">วัน</td>
    </tr>
</table>

<div style="margin-top: 6px;">
    <div><span class="box {{ $expense === 'budget' ? 'checked' : '' }}"></span> ขออนุมัติเบิกค่าใช้จ่ายในการเดินทางไปราชการตามสิทธิ</div>
    <div><span class="box {{ $expense === 'no_budget' ? 'checked' : '' }}"></span> ไม่ขอเบิกค่าใช้จ่ายในการเดินทางไปราชการ</div>
    <div><span class="box {{ $expense === 'vehicle_only' ? 'checked' : '' }}"></span> ขอเบิกเฉพาะค่ายานพาหนะ ไม่เบิกค่าเบี้ยเลี้ยง</div>
    <div>
        <span class="box {{ ! empty($leave->substitute_id) ? 'checked' : '' }}"></span>
        ได้มอบหมายให้ <span style="border-bottom: 1px dotted #000; display: inline-block; min-width: 200px;">&nbsp;{{ $leave->substitute?->full_name_with_title ?: ($leave->substitute?->name ?? '') }}</span>
        ปฏิบัติราชการแทน/สอนแทนแล้ว
    </div>
</div>

<div style="margin-top: 6px; text-indent: 14%;">จึงเรียนมาเพื่อโปรดพิจารณา</div>
<div style="margin-left: 50%;">
    @include('pdf.partials.requester_signature', ['signWidth' => 180])
</div>

@php $blocks = collect($approverBlocks ?? [])->values(); @endphp
@if ($blocks->isNotEmpty())
    <table class="approvals">
        @foreach ($blocks->chunk(2) as $pair)
            <tr>
                @foreach ($pair as $index => $block)
                    @php $isLast = $index === $blocks->count() - 1; @endphp
                    <td>
                        <div class="bold">({{ $index + 1 }}) ความเห็น{{ $block['position'] ?? '' }}</div>
                        <div>
                            @unless ($isLast)
                                <span class="box {{ ($block['status'] ?? '') === 'Approved' ? 'checked' : '' }}"></span>เพื่อโปรดพิจารณา&nbsp;
                            @else
                                <span class="box {{ ($block['status'] ?? '') === 'Approved' ? 'checked' : '' }}"></span>อนุญาต&nbsp;
                            @endunless
                            <span class="box {{ ($block['status'] ?? '') === 'Rejected' ? 'checked' : '' }}"></span>ไม่{{ $isLast ? 'อนุญาต' : 'เห็นชอบ' }}
                        </div>
                        <div style="border-bottom: 1px dotted #000; min-height: 15px;">{{ $block['comment'] ?? '' }}</div>
                        <div style="margin-top: 4px;">
                            ลงชื่อ
                            @if (! empty($block['signature']))
                                <img class="sign-img" src="{{ $block['signature'] }}" alt="">
                            @endif
                            <span style="border-bottom: 1px dotted #000; display: inline-block; width: 170px;">&nbsp;</span>
                        </div>
                        <div style="margin-left: 30px;">(<span class="fill" style="width: 190px;">{{ $block['name'] ?? '' }}</span>)</div>
                        <div style="margin-left: 30px;">วันที่ <span class="fill" style="width: 110px;">{{ $thaiShort($block['date'] ?? null) }}</span></div>
                    </td>
                @endforeach
                @if ($pair->count() === 1)
                    <td></td>
                @endif
            </tr>
        @endforeach
    </table>
@endif

@includeIf('pdf.partials.verification_qr')

</body>
</html>
