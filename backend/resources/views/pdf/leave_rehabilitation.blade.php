{{--
    แบบใบลาไปฟื้นฟูสมรรถภาพด้านอาชีพ
    (ระเบียบสำนักนายกรัฐมนตรีว่าด้วยการลาของข้าราชการ พ.ศ. 2555)

    ทุกค่ามาจากชุดตัวแปรกลาง `App\Services\LeaveDocumentFields`
    รายละเอียดมาจากช่องเฉพาะแบบฟอร์ม (หน้าตั้งค่าประเภทการลา):
      level, injury_cause, injury_date, course, institution
--}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แบบใบลาไปฟื้นฟูสมรรถภาพด้านอาชีพ</title>
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

@include('pdf.partials.form_header', ['formTitle' => 'แบบใบลาไปฟื้นฟูสมรรถภาพด้านอาชีพ', 'formSubject' => 'ขอลาไปฟื้นฟูสมรรถภาพด้านอาชีพ'])

{{-- ถ้อยคำและลำดับหัวข้อตามแบบท้ายระเบียบฯ พ.ศ. 2555 (แบบหลายหน้า) — ช่องที่ระบบไม่มีข้อมูลเว้นไว้กรอกด้วยมือ --}}
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
        <td width="6%">ระดับ</td>
        <td width="24%" class="dot-left">&nbsp;{{ $level ?? '' }}</td>
        <td width="7%" class="text-center">สังกัด</td>
        <td width="63%" class="dot-left">&nbsp;{{ $affiliation_line ?? '' }}</td>
    </tr>
</table>
<div>มีความประสงค์ขอลาไปฟื้นฟูสมรรถภาพทางอาชีพ จึงขอเสนอรายละเอียดเพื่อประกอบการพิจารณา ดังนี้</div>

<div class="bold" style="margin-top: 4px;">๑. ข้อมูลเกี่ยวกับผู้ขอ</div>
<div style="margin-left: 20px;">๑.๑ ประวัติการรับราชการ</div>
<div style="margin-left: 40px;">๑.๑.๑ ขณะได้รับอันตรายหรือป่วยเจ็บจนทำให้ตกเป็นผู้ทุพพลภาพหรือพิการ</div>
<table class="row">
    <tr>
        <td width="12%">ดำรงตำแหน่ง</td>
        <td width="48%" class="dot-left">&nbsp;</td>
        <td width="7%" class="text-center">ระดับ</td>
        <td width="33%" class="dot-left">&nbsp;</td>
    </tr>
    <tr>
        <td width="12%">สังกัด</td>
        <td width="48%" class="dot-left">&nbsp;</td>
        <td width="7%" class="text-center">กระทรวง</td>
        <td width="33%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="22%">ปฏิบัติหน้าที่ (โดยย่อ)</td>
        <td width="78%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="10%">ตั้งแต่วันที่</td>
        <td width="38%" class="dot-left">&nbsp;</td>
        <td width="8%" class="text-center">ถึงวันที่</td>
        <td width="44%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row" style="margin-left: 40px; width: 94%;">
    <tr>
        <td width="30%">๑.๑.๒ ปัจจุบันดำรงตำแหน่ง</td>
        <td width="34%" class="dot-left">&nbsp;{{ $position ?? '' }}</td>
        <td width="8%" class="text-center">ระดับ</td>
        <td width="28%" class="dot-left">&nbsp;{{ $level ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="7%">สังกัด</td>
        <td width="53%" class="dot-left">&nbsp;{{ $affiliation_line ?? '' }}</td>
        <td width="9%" class="text-center">กระทรวง</td>
        <td width="31%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="22%">ปฏิบัติหน้าที่ (โดยย่อ)</td>
        <td width="78%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="10%">ตั้งแต่วันที่</td>
        <td width="38%" class="dot-left">&nbsp;</td>
        <td width="8%" class="text-center">ถึงวันที่</td>
        <td width="44%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row" style="margin-left: 40px; width: 94%;">
    <tr>
        <td width="26%">๑.๑.๓ ที่อยู่ปัจจุบัน</td>
        <td width="74%" class="dot-left">&nbsp;{{ $contact_address ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="16%">หมายเลขโทรศัพท์</td>
        <td width="34%" class="dot-left">&nbsp;{{ $phone ?? '' }}</td>
        <td width="50%"></td>
    </tr>
</table>

<div style="margin-left: 20px; margin-top: 4px;">๑.๒ สาเหตุที่ได้รับอันตรายหรือป่วยเจ็บ จนทำให้ตกเป็นผู้ทุพพลภาพหรือพิการ</div>
<table class="row" style="margin-left: 40px; width: 94%;">
    <tr>
        <td width="12%">เหตุเกิดที่</td>
        <td width="88%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="6%">ตำบล</td>
        <td width="22%" class="dot-left">&nbsp;</td>
        <td width="7%" class="text-center">อำเภอ</td>
        <td width="22%" class="dot-left">&nbsp;</td>
        <td width="8%" class="text-center">จังหวัด</td>
        <td width="35%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="10%">เมื่อวันที่</td>
        <td width="40%" class="dot-left">&nbsp;{{ $extra['injury_date'] ?? '' }}</td>
        <td width="50%"></td>
    </tr>
</table>
<div>เนื่องจาก</div>
<div style="margin-left: 70px;"><span class="box"></span>ได้รับอันตรายหรือการป่วยเจ็บเพราะเหตุปฏิบัติราชการในหน้าที่</div>
<div style="margin-left: 70px;"><span class="box"></span>ถูกประทุษร้ายเพราะเหตุกระทำการตามหน้าที่</div>
<div style="margin-left: 70px;"><span class="box"></span>เหตุอื่นใดนอกจากเหตุปฏิบัติราชการในหน้าที่/เหตุกระทำการตามหน้าที่</div>
<table class="row">
    <tr>
        <td width="12%">(โปรดระบุ)</td>
        <td width="88%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row" style="margin-left: 40px; width: 94%;">
    <tr>
        <td width="22%">ข้อเท็จจริงโดยสรุป</td>
        <td width="78%" class="dot-left">&nbsp;{{ $extra['injury_cause'] ?? '' }}</td>
    </tr>
</table>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>

<div style="margin-left: 20px; margin-top: 4px;">๑.๓ ความเห็นของแพทย์ซึ่งเป็นผู้ที่ได้ขึ้นทะเบียนและรับใบอนุญาตเป็นผู้ประกอบวิชาชีพทางเวชกรรม ผู้เคยตรวจหรือรักษา</div>
<table class="row" style="margin-left: 40px; width: 94%;">
    <tr>
        <td width="20%">๑.๓.๑ แพทย์ชื่อ</td>
        <td width="80%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="9%">ตำแหน่ง</td>
        <td width="51%" class="dot-left">&nbsp;</td>
        <td width="40%">ผู้ได้ตรวจหรือรักษา ณ โรงพยาบาล</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="18%">หรือสถานพยาบาล</td>
        <td width="82%" class="dot-left">&nbsp;</td>
    </tr>
</table>
<div style="margin-left: 40px;">๑.๓.๒ ความเห็นโดยสรุปของแพทย์ผู้ตรวจหรือรักษาที่เกี่ยวข้องกับสภาพความพิการหรือทุพพลภาพที่เกิดขึ้นตามเอกสารแนบ (เช่น มีความพิการทางอวัยวะใดบ้าง ตั้งแต่วันเดือนปีใด เป็นต้น)</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>

<div class="bold" style="margin-top: 6px;">๒. ข้อมูลเกี่ยวกับหลักสูตรการฝึกอบรม</div>
<div style="margin-left: 20px;">๒.๑ มีความประสงค์จะลาไปเข้ารับการฝึกอบรม</div>
<div style="margin-left: 70px;"><span class="box"></span>หลักสูตรเกี่ยวกับการฟื้นฟูสมรรถภาพที่จำเป็นต่อการปฏิบัติหน้าที่ราชการ หรือ</div>
<div style="margin-left: 70px;"><span class="box"></span>หลักสูตรเกี่ยวกับการฟื้นฟูสมรรถภาพที่จำเป็นต่อการประกอบอาชีพ</div>
<table class="row">
    <tr>
        <td width="12%">ชื่อหลักสูตร</td>
        <td width="88%" class="dot-left">&nbsp;{{ $extra['course'] ?? '' }}</td>
    </tr>
    <tr>
        <td width="12%">ซึ่งจัดโดย</td>
        <td width="88%" class="dot-left">&nbsp;{{ $extra['institution'] ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="22%">ระยะเวลาการฝึกอบรม</td>
        <td width="8%" class="dot">&nbsp;</td>
        <td width="8%" class="text-center">ชั่วโมง</td>
        <td width="8%" class="dot">{{ $duration_days ?? '' }}</td>
        <td width="5%" class="text-center">วัน</td>
        <td width="8%" class="dot">{{ $duration_months ?? '' }}</td>
        <td width="7%" class="text-center">เดือน</td>
        <td width="8%" class="dot">{{ $duration_years ?? '' }}</td>
        <td width="5%" class="text-center">ปี</td>
        <td width="21%"></td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="10%">ตั้งแต่วันที่</td>
        <td width="38%" class="dot">{{ $start_date_thai ?? '' }}</td>
        <td width="8%" class="text-center">ถึงวันที่</td>
        <td width="44%" class="dot">{{ $end_date_thai ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="30%">ค่าใช้จ่ายในการฝึกอบรม (ถ้ามี)</td>
        <td width="24%" class="dot-left">&nbsp;</td>
        <td width="6%" class="text-center">บาท</td>
        <td width="40%"></td>
    </tr>
</table>
<div style="margin-left: 20px;">๒.๒ พร้อมนี้ได้แนบหลักฐานเกี่ยวกับหลักสูตรที่ประสงค์จะลาไปเข้ารับการฝึกอบรมในข้อ ๒.๑ และเอกสารที่เกี่ยวข้อง (ถ้ามี) จำนวน <span class="fill" style="width: 40px;">&nbsp;</span> ฉบับ มาด้วยแล้ว</div>
<div style="margin-top: 4px; text-indent: 14%;">ข้าพเจ้าขอรับรองว่าข้อความข้างต้นถูกต้องตรงกับความเป็นจริงทุกประการ</div>
@include('pdf.partials.requester_signature')

<div class="bold" style="margin-top: 8px;">๓. ความเห็นของหน่วยงานเกี่ยวกับการบริหารงานบุคคล</div>
<div style="margin-left: 40px;"><span class="box"></span>เป็นผู้ได้รับอันตรายหรือการป่วยเจ็บเพราะเหตุปฏิบัติราชการในหน้าที่ หรือถูกประทุษร้ายเพราะเหตุกระทำการตามหน้าที่ จนทำให้ตกเป็นผู้ทุพพลภาพหรือพิการ (กรณีตามข้อ ๓๙ วรรคหนึ่ง)</div>
<div style="margin-left: 40px;"><span class="box"></span>เป็นผู้ได้รับอันตรายหรือการป่วยเจ็บจนทำให้ตกเป็นผู้ทุพพลภาพหรือพิการเพราะเหตุอื่น (กรณีตามข้อ ๓๙ วรรคสอง)</div>
<div style="margin-left: 40px;"><span class="box"></span>หลักสูตรที่ประสงค์ที่จะลาไปเข้ารับการฝึกอบรมเป็นหลักสูตรที่ส่วนราชการ หน่วยงานอื่นของรัฐ องค์กรการกุศลอันเป็นสาธารณะหรือสถาบันที่ได้รับการรับรองจากหน่วยงานของทางราชการ เป็นผู้จัดหรือร่วมจัด</div>
<div style="margin-left: 40px;"><span class="box"></span>ข้าราชการผู้นี้ยังไม่เคยลาไปเข้ารับการฝึกอบรมหลักสูตรเกี่ยวกับการฟื้นฟูสมรรถภาพที่จำเป็นต่อการปฏิบัติหน้าที่ราชการ หรือที่จำเป็นต่อการประกอบอาชีพ กรณีการทุพพลภาพหรือพิการในครั้งนี้</div>
<table class="row" style="margin-left: 40px; width: 94%;">
    <tr>
        <td width="18%"><span class="box"></span>อื่น ๆ (ระบุ)</td>
        <td width="82%" class="dot-left">&nbsp;{{ $checker['comment'] ?? '' }}</td>
    </tr>
</table>
@include('pdf.partials.signer_block', ['block' => $checker])

<div class="bold" style="margin-top: 8px;">ความเห็นผู้บังคับบัญชาระดับหัวหน้าส่วนราชการ</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">{{ $commander['comment'] ?? '' }}</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>
@include('pdf.partials.signer_block', ['block' => $commander])

<div class="bold" style="margin-top: 8px;">ความเห็นผู้บังคับบัญชาระดับปลัดกระทรวง/หัวหน้าส่วนราชการขึ้นตรง (กรณีการลาเกิน ๖ เดือน)</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>
<div style="border-bottom: 1px dotted #000; min-height: 16px;">&nbsp;</div>
@include('pdf.partials.signer_block', ['block' => null])

<div class="bold" style="margin-top: 8px;">คำสั่ง</div>
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
