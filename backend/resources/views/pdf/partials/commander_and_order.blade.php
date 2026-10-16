{{--
    ส่วน "ความเห็นผู้บังคับบัญชา" และ "คำสั่ง" ตามแบบฟอร์มราชการ (รูปแบบเดียวกับแบบใบลาอุปสมบท)

    ผู้สั่งการ = ขั้นอนุมัติสุดท้าย · ผู้บังคับบัญชา = ขั้นก่อนหน้าขั้นสุดท้าย
    ตัวแปร: $approverBlocks (จาก LeaveApproverBlocks), $director_position
--}}
@php
    $signBlocks = collect($approverBlocks ?? []);
    $order = $signBlocks->last();
    $commander = $signBlocks->filter(fn ($b) => $b !== $order)->last() ?? $order;
    $thaiShort = fn ($date) => \App\Support\FormDate::short($date);
@endphp

<div class="bold underline" style="margin-top: 4px;">ความเห็นผู้บังคับบัญชา</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">{{ $commander['comment'] ?? '' }}</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>
<div class="text-center" style="margin-top: 4px;">
    (ลงชื่อ)
    @if (! empty($commander['signature']))
        <img class="sign-img" src="{{ $commander['signature'] }}" alt="">
    @endif
    <span style="border-bottom: 1px dotted #000; display: inline-block; width: 220px;">&nbsp;</span>
</div>
<div class="text-center">(<span class="fill" style="width: 190px;">{{ $commander['name'] ?? '' }}</span>)</div>
<div class="text-center">ตำแหน่ง <span class="fill" style="width: 190px;">{{ $commander['position'] ?? '' }}</span></div>
@include('pdf.partials.acting_line', ['block' => $commander])
<div class="text-center">วันที่ <span class="fill" style="width: 110px;">{{ $thaiShort($commander['date'] ?? null) }}</span></div>

<div class="bold underline" style="margin-top: 4px;">คำสั่ง</div>
<div style="margin-left: 40px;">
    <span class="box {{ ($order['status'] ?? '') === 'Approved' ? 'checked' : '' }}"></span>อนุญาต
    &nbsp;&nbsp;&nbsp;&nbsp;
    <span class="box {{ ($order['status'] ?? '') === 'Rejected' ? 'checked' : '' }}"></span>ไม่อนุญาต
</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">{{ $order['comment'] ?? '' }}</div>
<div class="text-center" style="margin-top: 4px;">
    (ลงชื่อ)
    @if (! empty($order['signature']))
        <img class="sign-img" src="{{ $order['signature'] }}" alt="">
    @endif
    <span style="border-bottom: 1px dotted #000; display: inline-block; width: 220px;">&nbsp;</span>
</div>
<div class="text-center">(<span class="fill" style="width: 190px;">{{ $order['name'] ?? '' }}</span>)</div>
<div class="text-center">ตำแหน่ง <span class="fill" style="width: 190px;">{{ $order['position'] ?? ($director_position ?? '') }}</span></div>
@include('pdf.partials.acting_line', ['block' => $order])
<div class="text-center">วันที่ <span class="fill" style="width: 110px;">{{ $thaiShort($order['date'] ?? null) }}</span></div>
