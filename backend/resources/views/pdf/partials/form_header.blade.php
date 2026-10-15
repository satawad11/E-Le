{{--
    หัวแบบฟอร์ม: ชื่อแบบ (เขียนที่) วันที่ เรื่อง เรียน
    ตัวแปร: $formTitle, $formSubject + ตัวแปรมาตรฐานจาก LeaveDocumentFields
--}}
<div class="text-center bold" style="font-size: 18pt;">{{ $formTitle }}</div>

<table class="row" style="margin-top: 6px;">
    <tr>
        <td width="48%"></td>
        <td width="12%" class="text-right">(เขียนที่)&nbsp;</td>
        <td width="40%" class="dot">{{ $written_at ?? '' }}</td>
    </tr>
</table>
<table class="row">
    <tr>
        <td width="48%"></td>
        <td width="7%" class="text-right">วันที่&nbsp;</td>
        <td width="8%" class="dot">{{ $doc_day ?? '' }}</td>
        <td width="6%" class="text-center">เดือน</td>
        <td width="18%" class="dot">{{ $doc_month ?? '' }}</td>
        <td width="5%" class="text-center">พ.ศ.</td>
        <td width="8%" class="dot">{{ $doc_year ?? '' }}</td>
    </tr>
</table>

<table class="row" style="margin-top: 6px;">
    <tr>
        <td width="7%">เรื่อง</td>
        <td width="93%">{{ $formSubject }}</td>
    </tr>
    <tr>
        <td width="7%">เรียน</td>
        <td width="93%">{{ $director_position ?? '' }}</td>
    </tr>
</table>
