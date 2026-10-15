{{--
    บรรทัดลงชื่อผู้ลา — ใช้ลายเซ็นที่แนบตอนยื่นใบลา ถ้าไม่มีให้เว้นเส้นประไว้ลงนามด้วยมือ
    เดิมพิมพ์ชื่อผู้ลาลงบนเส้นลายเซ็น ซึ่งไม่ใช่การลงนาม

    ตัวแปร: $requester_signature, $user_name · ตัวเลือก: $signWidth (px), $align ('center'|'left')
--}}
@php($signWidth = $signWidth ?? 220)
<div style="text-align: {{ $align ?? 'center' }}; margin-top: 8px;">
    (ลงชื่อ)
    @if (! empty($requester_signature))
        <img src="{{ $requester_signature }}" alt="" style="height: 32px; vertical-align: bottom;">
    @endif
    <span style="border-bottom: 1px dotted #000; display: inline-block; width: {{ $signWidth }}px;">&nbsp;</span>
</div>
<div style="text-align: {{ $align ?? 'center' }};">(<span class="fill" style="width: 190px;">{{ $user_name ?? '' }}</span>)</div>
