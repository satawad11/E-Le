{{-- สไตล์ร่วมของแบบฟอร์มใบลา (ฟอนต์ TH Sarabun New, A4, เส้นประกรอกข้อมูล) --}}
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
    @page { margin: 8mm 14mm; size: A4 portrait; }
    body { font-family: 'THSarabunNew', sans-serif; font-size: 14pt; line-height: 1.0; color: #000; }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .bold { font-weight: bold; }
    .underline { text-decoration: underline; }
    table.row { width: 100%; border-collapse: collapse; }
    table.row td { vertical-align: bottom; padding: 1px 0; white-space: nowrap; }
    td.dot { border-bottom: 1px dotted #000; text-align: center; }
    td.dot-left { border-bottom: 1px dotted #000; text-align: left; white-space: normal; }
    .box { display: inline-block; width: 10px; height: 10px; border: 1px solid #000; margin-right: 3px; }
    .checked { background-color: #000; }
    .sign-img { height: 32px; }
</style>
