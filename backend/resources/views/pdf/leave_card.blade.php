<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>บัตรลา</title>
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
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'THSarabunNew', 'Tahoma', sans-serif; font-size: 14px; color: #1a1a1a; padding: 28px 36px; }
  .header { text-align: center; margin-bottom: 16px; border-bottom: 2px solid #800000; padding-bottom: 12px; }
  .header h1 { font-size: 22px; color: #800000; }
  .header p { font-size: 15px; margin-top: 3px; }
  .info { width: 100%; margin-bottom: 14px; font-size: 15px; }
  .info td { padding: 2px 0; vertical-align: top; }
  .info .label { color: #555; width: 90px; }
  .info .value { font-weight: bold; }
  table.data { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  table.data th, table.data td { border: 1px solid #999; padding: 4px 6px; font-size: 14px; }
  table.data th { background: #f2ebeb; color: #800000; text-align: center; }
  table.data td.num { text-align: right; }
  table.data td.center { text-align: center; }
  table.data tr.total td { background: #f7f7f7; font-weight: bold; }
  h2.section { font-size: 16px; color: #800000; margin: 4px 0 6px; }
  .sign { margin-top: 34px; width: 100%; }
  .sign td { width: 50%; text-align: center; font-size: 14px; padding-top: 6px; }
  .dots { display: inline-block; width: 200px; border-bottom: 1px dotted #333; }
  .note { font-size: 13px; color: #666; margin-top: 6px; }
</style>
</head>
<body>
  <div class="header">
    <h1>บัตรลา</h1>
    <p>สรุปสถิติการลาประจำปีงบประมาณ พ.ศ. {{ $card['fiscal_year_th'] }}</p>
    <p style="font-size:13px;color:#666;">
      ({{ $thaiDateService->formatToThaiText($card['period']['from']) }} –
      {{ $thaiDateService->formatToThaiText($card['period']['to']) }})
    </p>
  </div>

  <table class="info">
    <tr>
      <td class="label">ชื่อ-สกุล</td><td class="value">{{ $card['user']['name'] }}</td>
      <td class="label">ตำแหน่ง</td><td class="value">{{ $card['user']['position'] }}</td>
    </tr>
    <tr>
      <td class="label">สังกัด</td><td class="value">{{ $card['user']['department'] }}</td>
      <td class="label">ประเภทบุคลากร</td><td class="value">{{ $card['user']['personnel_type'] }}</td>
    </tr>
    <tr>
      <td class="label">รหัสบุคลากร</td><td class="value">{{ $card['user']['personnel_code'] }}</td>
      <td class="label">วันเริ่มปฏิบัติงาน</td>
      <td class="value">{{ $card['user']['start_date'] ? $thaiDateService->formatToThaiText($card['user']['start_date']) : '—' }}</td>
    </tr>
  </table>

  <h2 class="section">สรุปตามประเภทการลา</h2>
  <table class="data">
    <thead>
      <tr>
        <th style="width:24%">ประเภทการลา</th>
        <th style="width:9%">ยกมา</th>
        <th style="width:10%">สิทธิ์รวม</th>
        <th style="width:9%">ครั้ง</th>
        <th style="width:10%">ลาไป (วัน)</th>
        <th style="width:12%">รับเงินเดือน</th>
        <th style="width:12%">ไม่รับเงินเดือน</th>
        <th style="width:14%">คงเหลือ</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($card['summary'] as $row)
        <tr>
          <td>{{ $row['leave_type'] }}</td>
          <td class="num">{{ $row['carried_forward'] ?: '—' }}</td>
          <td class="num">{{ $row['quota'] === null ? 'ไม่จำกัด' : $row['quota'] }}</td>
          <td class="num">{{ $row['times'] ?: '—' }}</td>
          <td class="num">{{ $row['used_days'] ?: '—' }}</td>
          <td class="num">{{ $row['paid_days'] ?: '—' }}{{ $row['paid_quota'] !== null ? ' / ' . $row['paid_quota'] : '' }}</td>
          <td class="num">{{ $row['unpaid_days'] ?: '—' }}</td>
          <td class="num">{{ $row['remaining'] === null ? 'ไม่จำกัด' : $row['remaining'] }}</td>
        </tr>
      @empty
        <tr><td colspan="8" class="center">ไม่มีข้อมูลสิทธิ์การลาในปีงบประมาณนี้</td></tr>
      @endforelse
      <tr class="total">
        <td colspan="3">รวมทั้งสิ้น</td>
        <td class="num">{{ $card['totals']['times'] }}</td>
        <td class="num">{{ $card['totals']['used_days'] }}</td>
        <td class="num">{{ $card['totals']['paid_days'] }}</td>
        <td class="num">{{ $card['totals']['unpaid_days'] }}</td>
        <td></td>
      </tr>
    </tbody>
  </table>

  <h2 class="section">รายการใบลา</h2>
  <table class="data">
    <thead>
      <tr>
        <th style="width:18%">เลขที่ใบลา</th>
        <th style="width:22%">ประเภทการลา</th>
        <th style="width:28%">ช่วงวันที่ลา</th>
        <th style="width:10%">จำนวนวัน</th>
        <th style="width:10%">ไม่รับเงินเดือน</th>
        <th style="width:12%">สถานะ</th>
      </tr>
    </thead>
    <tbody>
      @php $statusLabels = ['Approved' => 'อนุมัติแล้ว', 'Pending' => 'รออนุมัติ']; @endphp
      @forelse ($card['entries'] as $entry)
        <tr>
          <td class="center">{{ $entry['document_number'] ?: '—' }}</td>
          <td>{{ $entry['leave_type'] }}</td>
          <td class="center">
            {{ $thaiDateService->formatToThaiSlash($entry['start_date']) }} –
            {{ $thaiDateService->formatToThaiSlash($entry['end_date']) }}
          </td>
          <td class="num">{{ $entry['total_days'] }}</td>
          <td class="num">{{ $entry['unpaid_days'] ?: '—' }}</td>
          <td class="center">{{ $statusLabels[$entry['status']] ?? $entry['status'] }}</td>
        </tr>
      @empty
        <tr><td colspan="6" class="center">ไม่มีการลาในปีงบประมาณนี้</td></tr>
      @endforelse
    </tbody>
  </table>

  <p class="note">หมายเหตุ: นับเฉพาะใบลาที่มีวันเริ่มลาอยู่ในปีงบประมาณนี้ รายการที่ยังรออนุมัติแสดงไว้เพื่อประกอบการพิจารณาแต่ไม่รวมในยอดสรุป</p>

  <table class="sign">
    <tr>
      <td>
        ลงชื่อ <span class="dots"></span> ผู้จัดทำ<br>

        ( <span class="dots"></span> )<br>
        เจ้าหน้าที่งานบุคลากร
      </td>
      <td>
        ลงชื่อ <span class="dots"></span> ผู้รับรอง<br>
        ( <span class="dots"></span> )<br>
        หัวหน้างานบุคลากร
      </td>
    </tr>
  </table>
</body>
</html>
