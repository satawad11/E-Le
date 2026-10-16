{{--
    บล็อกลงนามมาตรฐานของแบบท้ายระเบียบ: (ลงชื่อ) / (ชื่อ) / (ตำแหน่ง) / [ปฏิบัติราชการแทน …] / วันที่

    ตัวแปร: $block (บล็อกผู้ลงนามจาก LeaveApproverBlocks หรือ null = ช่องว่างให้ลงนามด้วยมือ)
            $defaultPosition (ตำแหน่งที่พิมพ์เมื่อยังไม่มีผู้ลงนาม)
--}}
@php($signer = $block ?? [])
<div class="text-center" style="margin-top: 6px;">
    (ลงชื่อ)
    @if (! empty($signer['signature']))
        <img class="sign-img" src="{{ $signer['signature'] }}" alt="">
    @endif
    <span style="border-bottom: 1px dotted #000; display: inline-block; width: 220px;">&nbsp;</span>
</div>
<div class="text-center">(<span class="fill" style="width: 190px;">{{ $signer['name'] ?? '' }}</span>)</div>
<div class="text-center">(ตำแหน่ง) <span class="fill" style="width: 190px;">{{ $signer['position'] ?? ($defaultPosition ?? '') }}</span></div>
@if ($block)
    @include('pdf.partials.acting_line', ['block' => $block])
@endif
<div class="text-center">วันที่ <span class="fill" style="width: 110px;">{{ \App\Support\FormDate::short($signer['date'] ?? null) }}</span></div>
