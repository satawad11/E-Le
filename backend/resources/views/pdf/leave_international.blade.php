{{--
    แบบใบลาไปปฏิบัติงานในองค์การระหว่างประเทศ
    (ระเบียบสำนักนายกรัฐมนตรีว่าด้วยการลาของข้าราชการ พ.ศ. 2555)

    ทุกค่ามาจากชุดตัวแปรกลาง `App\Services\LeaveDocumentFields`
    รายละเอียดมาจากช่องเฉพาะแบบฟอร์ม (หน้าตั้งค่าประเภทการลา):
      level, organization_name, country, assigned_position, funding
--}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แบบใบลาไปปฏิบัติงานในองค์การระหว่างประเทศ</title>
    @include('pdf.partials.form_styles')
</head>
<body>

@php
    // ผู้สั่งการ = ขั้นสุดท้าย · ขั้นก่อนหน้า = ผู้บังคับบัญชา · ขั้นแรก (ถ้ามีสามขั้น) = หน่วยงานบริหารงานบุคคล/ส่วนราชการ
    $signBlocks = collect($approverBlocks ?? [])->values();
    $order = $signBlocks->last();
    $commander = $signBlocks->count() > 1 ? $signBlocks->get($signBlocks->count() - 2) : null;
    $checker = $signBlocks->count() > 2 ? $signBlocks->first() : null;
@endphp

@include('pdf.partials.form_header', ['formTitle' => 'แบบใบลาไปปฏิบัติงานในองค์การระหว่างประเทศ', 'formSubject' => 'ขอลาไปปฏิบัติงานในองค์การระหว่างประเทศ'])

{{-- ถ้อยคำและลำดับหัวข้อตามแบบท้ายระเบียบฯ พ.ศ. 2555 (แบบหลายหน้า) — ช่องที่ระบบไม่มีข้อมูลเว้นไว้กรอกด้วยมือ --}}
<table class="row" style="margin-top: 6px;">
    <tr>
        <td width="14%"></td>
        <td width="32%">ข้าพเจ้า (ชื่อและนามสกุลภาษาไทย)</td>
        <td width="54%" class="dot-left">&nbsp;{{ $user_name ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="12%">(ภาษาอังกฤษ)</td>
        <td width="88%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="8%">ตำแหน่ง</td>
        <td width="30%" class="dot-left">&nbsp;{{ $position ?? '' }}</td>
        <td width="6%" class="text-center">ระดับ</td>
        <td width="18%" class="dot-left">&nbsp;{{ $level ?? '' }}</td>
        <td width="7%" class="text-center">สังกัด</td>
        <td width="31%" class="dot-left">&nbsp;{{ $affiliation_line ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="54%">มีความประสงค์ขอลาไปปฏิบัติงานในองค์การระหว่างประเทศ ตั้งแต่วันที่</td>
        <td width="46%" class="dot">{{ $start_date_thai ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="9%">ถึงวันที่</td>
        <td width="35%" class="dot">{{ $end_date_thai ?? '' }}</td>
        <td width="56%"></td>
    </tr>
</table>
<div>จึงขอเสนอรายละเอียดเพื่อประกอบการพิจารณาดังนี้</div>

<div class="bold" style="margin-top: 4px;">๑. คำชี้แจงในการขออนุญาต</div>
<div style="margin-left: 20px;">๑.๑ ประวัติ</div>
<table class="row" style="margin-left: 40px; width: 94%;">
    <tr>
        <td width="26%">๑.๑.๑ วันเดือนปีเกิด</td>
        <td width="44%" class="dot">{{ $birth_date_thai ?? '' }}</td>
        <td width="8%" class="text-center">อายุ</td>
        <td width="12%" class="dot">&nbsp;</td>
        <td width="10%" class="text-center">ปี</td>
    </tr>
</table>
<div style="margin-left: 40px;">๑.๑.๒ ประวัติการศึกษา</div>
@foreach (['ประกาศนียบัตร', 'ปริญญาตรี', 'ปริญญาโท', 'ปริญญาเอก'] as $degreeLabel)
<table class="row" style="margin-left: 70px; width: 89%;">
    <tr>
        <td width="18%">{{ $degreeLabel }}</td>
        <td width="22%" class="dot-left">&nbsp;</td>
        <td width="6%" class="text-center">จาก</td>
        <td width="20%" class="dot-left">&nbsp;</td>
        <td width="8%" class="text-center">ประเทศ</td>
        <td width="12%" class="dot-left">&nbsp;</td>
        <td width="5%" class="text-center">พ.ศ.</td>
        <td width="9%" class="dot-left">&nbsp;</td>
    </tr>
</table>
@endforeach
<div style="margin-left: 40px;">๑.๑.๓ ประวัติการรับราชการ</div>
<table class="row" style="margin-left: 70px; width: 89%;">
    <tr>
        <td width="30%">เริ่มเข้ารับราชการเมื่อวันที่</td>
        <td width="40%" class="dot">{{ $hired_date_thai ?? '' }}</td>
        <td width="30%"></td>
    </tr>
</table>
<table class="row" style="margin-left: 70px; width: 89%;">
    <tr>
        <td width="44%">จนถึงกำหนดวันออกเดินทาง รวมเป็นเวลา</td>
        <td width="8%" class="dot">&nbsp;</td>
        <td width="5%" class="text-center">ปี</td>
        <td width="8%" class="dot">&nbsp;</td>
        <td width="7%" class="text-center">เดือน</td>
        <td width="8%" class="dot">&nbsp;</td>
        <td width="5%" class="text-center">วัน</td>
        <td width="15%"></td>
    </tr>
</table>
<table class="row" style="margin-left: 70px; width: 89%;">
    <tr>
        <td width="36%">โดยเริ่มเข้ารับราชการเป็นข้าราชการ</td>
        <td width="64%" class="dot-left">&nbsp;</td>
    </tr>
    <tr>
        <td width="36%">เป็นข้าราชการ</td>
        <td width="64%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row" style="margin-left: 40px; width: 94%;">
    <tr>
        <td width="30%">๑.๑.๔ ขณะนี้เป็นข้าราชการ</td>
        <td width="70%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row" style="margin-left: 70px; width: 89%;">
    <tr>
        <td width="10%">ตำแหน่ง</td>
        <td width="44%" class="dot-left">&nbsp;{{ $position ?? '' }}</td>
        <td width="8%" class="text-center">ระดับ</td>
        <td width="38%" class="dot-left">&nbsp;{{ $level ?? '' }}</td>
    </tr>
</table>
<table class="row" style="margin-left: 70px; width: 89%;">
    <tr>
        <td width="20%">ส่วน/กลุ่ม/ฝ่าย/งาน</td>
        <td width="32%" class="dot-left">&nbsp;{{ $division ?? '' }}</td>
        <td width="12%" class="text-center">สำนัก/กอง</td>
        <td width="36%" class="dot-left">&nbsp;{{ $department ?? '' }}</td>
    </tr>
</table>
<table class="row" style="margin-left: 70px; width: 89%;">
    <tr>
        <td width="6%">กรม</td>
        <td width="44%" class="dot-left">&nbsp;</td>
        <td width="16%" class="text-center">อัตราเงินเดือน</td>
        <td width="24%" class="dot">{{ $extra['salary'] ?? '' }}</td>
        <td width="10%" class="text-center">บาท</td>
    </tr>
</table>
<div style="margin-left: 40px;">
    ๑.๑.๕ เคยไปปฏิบัติงานในองค์การระหว่างประเทศหรือไม่
    &nbsp;<span class="box {{ ($done_before ?? null) === true ? 'checked' : '' }}"></span>เคย
    &nbsp;<span class="box {{ ($done_before ?? null) === false ? 'checked' : '' }}"></span>ไม่เคย
</div>
<div style="margin-left: 70px;">ถ้าเคย</div>
@foreach (['(๑)', '(๒)'] as $nth)
<table class="row" style="margin-left: 90px; width: 86%;">
    <tr>
        <td width="26%">{{ $nth }} ไปปฏิบัติงานให้กับ</td>
        <td width="40%" class="dot-left">&nbsp;</td>
        <td width="10%" class="text-center">ณ ประเทศ</td>
        <td width="24%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row" style="margin-left: 90px; width: 86%;">
    <tr>
        <td width="14%">เป็นระยะเวลา</td>
        <td width="22%" class="dot-left">&nbsp;</td>
        <td width="12%" class="text-center">ตั้งแต่วันที่</td>
        <td width="22%" class="dot-left">&nbsp;</td>
        <td width="9%" class="text-center">ถึงวันที่</td>
        <td width="21%" class="dot-left">&nbsp;</td>
    </tr>
</table>
@endforeach

<div style="margin-left: 20px; margin-top: 4px;">๑.๒ ข้อมูลเกี่ยวกับการไปปฏิบัติงาน</div>
<table class="row" style="margin-left: 40px; width: 94%;">
    <tr>
        <td width="30%">๑.๒.๑ ไปปฏิบัติงานให้กับ</td>
        <td width="70%" class="dot-left">&nbsp;{{ $extra['organization_name'] ?? '' }}</td>
    </tr>
</table>
<table class="row" style="margin-left: 70px; width: 89%;">
    <tr>
        <td width="12%">ณ ประเทศ</td>
        <td width="88%" class="dot-left">&nbsp;{{ $extra['country'] ?? '' }}</td>
    </tr>
    <tr>
        <td width="12%">ตามความตกลงระหว่าง</td>
        <td width="88%" class="dot-left">&nbsp;</td>
    </tr>
    <tr>
        <td width="12%">กับ</td>
        <td width="88%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<div style="margin-left: 70px;">
    <span class="box"></span>ติดต่อสมัครโดยผ่าน <span class="fill" style="width: 140px;">&nbsp;</span>
    &nbsp;<span class="box"></span>ติดต่อเอง
    &nbsp;<span class="box"></span>อื่น ๆ <span class="fill" style="width: 120px;">&nbsp;</span>
</div>
<table class="row" style="margin-left: 40px; width: 94%;">
    <tr>
        <td width="36%">๑.๒.๒ ตำแหน่งที่จะไปปฏิบัติงาน</td>
        <td width="34%" class="dot-left">&nbsp;{{ $extra['assigned_position'] ?? '' }}</td>
        <td width="8%" class="text-center">ระดับ</td>
        <td width="22%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row" style="margin-left: 70px; width: 89%;">
    <tr>
        <td width="36%">หน้าที่ที่จะไปปฏิบัติงาน (โดยย่อ)</td>
        <td width="64%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row" style="margin-left: 40px; width: 94%;">
    <tr>
        <td width="40%">๑.๒.๓ ระยะเวลาที่จะขอไปปฏิบัติงาน</td>
        <td width="8%" class="dot">{{ $duration_years ?? '' }}</td>
        <td width="5%" class="text-center">ปี</td>
        <td width="8%" class="dot">{{ $duration_months ?? '' }}</td>
        <td width="7%" class="text-center">เดือน</td>
        <td width="8%" class="dot">{{ $duration_days ?? '' }}</td>
        <td width="5%" class="text-center">วัน</td>
        <td width="19%"></td>
    </tr>
</table>
<table class="row" style="margin-left: 40px; width: 94%;">
    <tr>
        <td width="44%">๑.๒.๔ กำหนดออกเดินทางประมาณวันที่</td>
        <td width="40%" class="dot">{{ $start_date_thai ?? '' }}</td>
        <td width="16%"></td>
    </tr>
</table>
<div style="margin-left: 40px;">๑.๒.๕ ค่าตอบแทนที่ได้รับ</div>
<table class="row" style="margin-left: 70px; width: 89%;">
    <tr>
        <td width="30%">เงินเดือน อัตราเดือน/ปีละ</td>
        <td width="70%" class="dot-left">&nbsp;</td>
    </tr>
    <tr>
        <td width="30%">ค่าที่พัก</td>
        <td width="70%" class="dot-left">&nbsp;</td>
    </tr>
    <tr>
        <td width="30%">ค่าพาหนะในการเดินทาง</td>
        <td width="70%" class="dot-left">&nbsp;</td>
    </tr>
    <tr>
        <td width="30%">อื่น ๆ</td>
        <td width="70%" class="dot-left">&nbsp;{{ $extra['funding'] ?? '' }}</td>
    </tr>
</table>
<table class="row" style="margin-left: 40px; width: 94%;">
    <tr>
        <td width="24%">๑.๒.๖ สถานที่ติดต่อ</td>
        <td width="76%" class="dot-left">&nbsp;{{ $contact_address ?? '' }}</td>
    </tr>
</table>
<table class="row" style="margin-left: 70px; width: 89%;">
    <tr>
        <td width="20%">หมายเลขโทรศัพท์</td>
        <td width="40%" class="dot-left">&nbsp;{{ $phone ?? '' }}</td>
        <td width="40%"></td>
    </tr>
</table>
<div class="text-center" style="margin-top: 4px;">ขอรับรองว่าข้อความข้างต้นถูกต้องเป็นจริงทุกประการ</div>
@include('pdf.partials.requester_signature')

<div class="bold" style="margin-top: 8px;">๒. คำชี้แจงและคำรับรองของส่วนราชการระดับกรมเจ้าสังกัดหรือเทียบเท่า</div>
<div style="margin-left: 20px;">๒.๑ ขอชี้แจงว่าข้าราชการผู้นี้</div>
<div style="margin-left: 40px;">๒.๑.๑ ระยะเวลาการเป็นข้าราชการก่อนไปปฏิบัติงาน</div>
<div style="margin-left: 70px;"><span class="box"></span>เป็นข้าราชการประจำตลอดมาเป็นระยะเวลาติดต่อกันครบ ๒ ปี ก่อนถึงวันที่ได้รับอนุมัติให้ไปปฏิบัติงาน</div>
<div style="margin-left: 70px;"><span class="box"></span>เป็นข้าราชการประจำตลอดมาเป็นระยะเวลาติดต่อกันไม่ครบ ๒ ปี ก่อนถึงวันที่ได้รับอนุมัติให้ไปปฏิบัติงาน แต่กรณีมีความจำเป็นต้องสั่งให้ไปปฏิบัติงาน โดยมีเหตุผลอันสมควรและเป็นประโยชน์แก่ทางราชการอย่างยิ่งตามเอกสารแนบท้าย</div>
<div style="margin-left: 40px;">๒.๑.๒ อายุของผู้ไปปฏิบัติงาน</div>
<div style="margin-left: 70px;"><span class="box"></span>อายุไม่เกิน ๕๒ ปีบริบูรณ์ นับถึงวันที่ได้รับอนุมัติให้ไปปฏิบัติงาน</div>
<div style="margin-left: 70px;"><span class="box"></span>อายุเกิน แต่กรณีมีความจำเป็นต้องสั่งให้ไปปฏิบัติงาน โดยมีเหตุผลอันสมควรและเป็นประโยชน์แก่ทางราชการอย่างยิ่งตามเอกสารแนบท้าย</div>
<div style="margin-left: 40px;">๒.๑.๓ ความรู้ความสามารถ ความประพฤติ และการดำเนินการทางวินัย</div>
<div style="margin-left: 70px;"><span class="box"></span>เป็นผู้ปฏิบัติหน้าที่ราชการซึ่งมีความรู้ความสามารถเหมาะสม มีความประพฤติเรียบร้อย และไม่อยู่ระหว่างถูกดำเนินการทางวินัย</div>
<div style="margin-left: 70px;"><span class="box"></span>เป็นผู้ปฏิบัติหน้าที่ราชการซึ่ง <span class="fill" style="width: 300px;">&nbsp;</span> แต่กรณีมีความจำเป็นต้องสั่งให้ไปปฏิบัติงาน โดยมีเหตุผลอันสมควรและเป็นประโยชน์แก่ทางราชการอย่างยิ่งตามเอกสารแนบท้าย</div>
<div style="margin-left: 40px;">๒.๑.๔ ในกรณีที่เคยไปปฏิบัติงานในองค์การระหว่างประเทศมาแล้ว</div>
<div style="margin-left: 70px;"><span class="box"></span>ได้กลับมาปฏิบัติราชการในส่วนราชการครบ ๒ ปีแล้ว นับแต่วันกลับเข้าปฏิบัติหน้าที่ราชการหลังจากที่เดินทางกลับจากการไปปฏิบัติงานครั้งสุดท้าย</div>
<div style="margin-left: 70px;"><span class="box"></span>มีเวลาปฏิบัติราชการไม่ครบ ๒ ปี แต่กรณีมีความจำเป็นต้องสั่งให้ไปปฏิบัติงานโดยมีเหตุผลอันสมควรและเป็นประโยชน์แก่ทางราชการอย่างยิ่งตามเอกสารแนบท้าย</div>
<div style="margin-left: 20px;">๒.๒ ขอรับรองว่า การไปปฏิบัติงานของข้าราชการผู้นี้</div>
<div style="margin-left: 40px;"><span class="box"></span>๒.๒.๑ ไม่ทำให้เสียราชการ เพราะยังมีผู้อยู่ปฏิบัติงานเพียงพอ ไม่ต้องตั้งอัตรากำลังเพิ่ม</div>
<div style="margin-left: 40px;"><span class="box"></span>๒.๒.๒ เป็นประโยชน์ต่อประเทศไทยหรือต่อการปฏิบัติราชการของกระทรวง/กรมนี้ ประการใดบ้าง (โปรดระบุ)</div>
<div style="margin-left: 70px;">
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>
</div>
<div style="margin-left: 70px;"><span class="box"></span>เป็นประโยชน์ต่อประเทศไทยอย่างยิ่ง หรือเป็นประโยชน์แก่ทางราชการอย่างยิ่ง (โปรดระบุ)</div>
<div style="margin-left: 70px;">
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>
</div>
<div style="margin-left: 40px;">
    ๒.๒.๓ กระทรวง/กรม มีแผนงานหรือโครงการที่รองรับเมื่อกลับมาปฏิบัติหน้าที่ประการใดหรือไม่
    &nbsp;<span class="box"></span>มี &nbsp;<span class="box"></span>ไม่มี
</div>
<div style="margin-left: 70px;">โปรดชี้แจงรายละเอียดประกอบการพิจารณา</div>
<div style="margin-left: 70px;">
<div style="border-bottom: 1px dotted #000; min-height: 16px;">{{ $checker['comment'] ?? '' }}</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>
</div>
@include('pdf.partials.signer_block', ['block' => $checker])

<div class="bold" style="margin-top: 8px;">๓. การพิจารณาของกระทรวงเจ้าสังกัด</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">{{ $commander['comment'] ?? '' }}</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>
@include('pdf.partials.signer_block', ['block' => $commander])

<div class="bold" style="margin-top: 8px;">๔. คำสั่งของรัฐมนตรีเจ้าสังกัด</div>
<div style="margin-left: 40px;">
    <span class="box {{ ($order['status'] ?? '') === 'Approved' ? 'checked' : '' }}"></span>อนุญาต
    &nbsp;&nbsp;&nbsp;&nbsp;
    <span class="box {{ ($order['status'] ?? '') === 'Rejected' ? 'checked' : '' }}"></span>ไม่อนุญาต
</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">{{ $order['comment'] ?? '' }}</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>
@include('pdf.partials.signer_block', ['block' => $order, 'defaultPosition' => $director_position ?? ''])

@includeIf('pdf.partials.verification_qr')

</body>
</html>
