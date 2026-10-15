{{--
    ส่วน "ความเห็นผู้บังคับบัญชา" และ "คำสั่ง" ตามแบบฟอร์มราชการ (รูปแบบเดียวกับแบบใบลาอุปสมบท)

    ผู้สั่งการ = ขั้นอนุมัติสุดท้าย · ผู้บังคับบัญชา = ขั้นก่อนหน้าขั้นสุดท้าย
    ตัวแปร: $approverBlocks (จาก LeaveApproverBlocks), $director_position
--}}
@php
    $signBlocks = collect($approverBlocks ?? []);
    $order = $signBlocks->last();
    $commander = $signBlocks->filter(fn ($b) => $b !== $order)->last() ?? $order;
    $thaiShort = fn ($date) => ! empty($date) ? $date->format('d/m/').($date->year + 543) : '........./........./.........';
@endphp

<div class="bold underline" style="margin-top: 8px;">ความเห็นผู้บังคับบัญชา</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">{{ $commander['comment'] ?? '' }}</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>
<div class="text-center" style="margin-top: 8px;">
    (ลงชื่อ)
    @if (! empty($commander['signature']))
        <img class="sign-img" src="{{ $commander['signature'] }}" alt="">
    @endif
    <span style="border-bottom: 1px dotted #000; display: inline-block; width: 220px;">&nbsp;</span>
</div>
<div class="text-center">( {{ $commander['name'] ?? '' }} )</div>
<div class="text-center">ตำแหน่ง {{ $commander['position'] ?? '' }}</div>
<div class="text-center">วันที่ {{ $thaiShort($commander['date'] ?? null) }}</div>

<div class="bold underline" style="margin-top: 8px;">คำสั่ง</div>
<div style="margin-left: 40px;">
    <span class="box {{ ($order['status'] ?? '') === 'Approved' ? 'checked' : '' }}"></span>อนุญาต
    &nbsp;&nbsp;&nbsp;&nbsp;
    <span class="box {{ ($order['status'] ?? '') === 'Rejected' ? 'checked' : '' }}"></span>ไม่อนุญาต
</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">{{ $order['comment'] ?? '' }}</div>
<div class="text-center" style="margin-top: 8px;">
    (ลงชื่อ)
    @if (! empty($order['signature']))
        <img class="sign-img" src="{{ $order['signature'] }}" alt="">
    @endif
    <span style="border-bottom: 1px dotted #000; display: inline-block; width: 220px;">&nbsp;</span>
</div>
<div class="text-center">( {{ $order['name'] ?? '' }} )</div>
<div class="text-center">ตำแหน่ง {{ $order['position'] ?? ($director_position ?? '') }}</div>
<div class="text-center">วันที่ {{ $thaiShort($order['date'] ?? null) }}</div>
