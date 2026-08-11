{{-- QR ตรวจสอบความถูกต้องของเอกสาร วางมุมล่างขวาของหน้า --}}
@isset($verificationQr)
<div style="position: fixed; right: 24px; bottom: 18px; width: 108px; text-align: center;">
  <img src="{{ $verificationQr }}" alt="QR ตรวจสอบเอกสาร" style="width: 72px; height: 72px;">
  <div style="font-family: 'THSarabunNew', 'Tahoma', sans-serif; font-size: 9px; color: #555; line-height: 1.2; margin-top: 2px;">
    ตรวจสอบเอกสาร<br>
    <span style="font-size: 8px; word-break: break-all;">{{ substr($verificationHash ?? '', 0, 12) }}</span>
  </div>
</div>
@endisset
