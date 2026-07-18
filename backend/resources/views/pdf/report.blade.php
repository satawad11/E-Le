<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รายงานสถิติการลา</title>
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
  @font-face {
      font-family: 'THSarabunNew';
      font-style: italic;
      font-weight: normal;
      src: url("{{ public_path('fonts/THSarabunNew/THSarabunNew Italic.ttf') }}") format('truetype');
  }
  @font-face {
      font-family: 'THSarabunNew';
      font-style: italic;
      font-weight: bold;
      src: url("{{ public_path('fonts/THSarabunNew/THSarabunNew BoldItalic.ttf') }}") format('truetype');
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: 'THSarabunNew', 'Tahoma', sans-serif;
    font-size: 14px;
    color: #1a1a1a;
    padding: 28px 36px;
  }
  .header {
    text-align: center;
    margin-bottom: 18px;
    border-bottom: 2px solid #800000;
    padding-bottom: 14px;
  }
  .header h1 {
    font-size: 22px;
    font-weight: bold;
    color: #800000;
    margin-bottom: 6px;
  }
  .header .filter-row {
    font-size: 13px;
    color: #444;
    margin-top: 4px;
  }
  .header .filter-row span {
    display: inline-block;
    background: #f5f0f0;
    border: 1px solid #d5b8b8;
    border-radius: 4px;
    padding: 1px 8px;
    margin: 2px 3px;
    color: #800000;
    font-weight: bold;
  }
  .header .print-date {
    font-size: 11px;
    color: #888;
    margin-top: 6px;
  }
  .section { margin-bottom: 18px; }
  .section-title {
    font-size: 15px;
    font-weight: bold;
    color: #800000;
    border-left: 3px solid #800000;
    padding-left: 8px;
    margin-bottom: 8px;
  }
  .summary-row {
    display: flex;
    gap: 12px;
    margin-bottom: 14px;
  }
  .summary-card {
    flex: 1;
    border: 1px solid #e0d0d0;
    border-radius: 6px;
    padding: 10px 12px;
    text-align: center;
    background: #fdf8f8;
  }
  .summary-card .number { font-size: 26px; font-weight: bold; color: #800000; }
  .summary-card .label { font-size: 12px; color: #666; margin-top: 2px; }
  table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
  }
  thead tr { background-color: #800000; color: #fff; }
  thead th { padding: 8px 10px; text-align: left; font-weight: bold; }
  tbody tr:nth-child(even) { background-color: #fdf5f5; }
  tbody tr:hover { background-color: #f5e8e8; }
  tbody td { padding: 6px 10px; border-bottom: 1px solid #eedada; }
  tfoot tr { background-color: #f3eded; font-weight: bold; }
  tfoot td { padding: 7px 10px; border-top: 2px solid #c9a0a0; }
  .no-data {
    text-align: center;
    padding: 30px;
    color: #aaa;
    font-size: 13px;
    border: 1px dashed #ddd;
    border-radius: 6px;
  }
  .footer {
    margin-top: 24px;
    border-top: 1px solid #ddd;
    padding-top: 10px;
    text-align: right;
    font-size: 11px;
    color: #999;
  }
</style>
</head>
<body>

{{-- ===== HEADER ===== --}}
<div class="header">
  <h1>รายงานสถิติการลา</h1>
  <div class="filter-row">
    ปี พ.ศ. <span>{{ $thaiYear }}</span>
    @if($thaiMonthName)
      &nbsp;เดือน <span>{{ $thaiMonthName }}</span>
    @else
      &nbsp;เดือน <span>ทั้งปี</span>
    @endif
    @if($selectedUserName)
      &nbsp;บุคคล <span>{{ $selectedUserName }}</span>
    @else
      &nbsp;บุคคล <span>ทุกคน</span>
    @endif
  </div>
  <div class="print-date">พิมพ์เมื่อ: {{ $thaiDateService->toThaiDate(now()->toDateString()) }}</div>
</div>

{{-- ===== SUMMARY CARDS ===== --}}
@php $totalCount = $leaves->count(); $totalDays = $leaves->sum('total_days'); @endphp
<div class="section">
  <div class="summary-row">
    <div class="summary-card">
      <div class="number">{{ $totalCount }}</div>
      <div class="label">รายการลาทั้งหมด</div>
    </div>
    <div class="summary-card">
      <div class="number">{{ $totalDays }}</div>
      <div class="label">รวมวันลา (วัน)</div>
    </div>
    @if(!$selectedUserName)
    <div class="summary-card">
      <div class="number">{{ $leaves->unique('user_id')->count() }}</div>
      <div class="label">พนักงานที่ลา (คน)</div>
    </div>
    @endif
    <div class="summary-card">
      <div class="number">{{ $leaves->unique('leave_type_id')->count() }}</div>
      <div class="label">ประเภทการลาที่ใช้</div>
    </div>
  </div>
</div>

{{-- ===== SUMMARY BY TYPE ===== --}}
<div class="section">
  <div class="section-title">สรุปตามประเภทการลา</div>
  @if($totalCount === 0)
    <div class="no-data">ไม่มีข้อมูลการลาในช่วงเวลาที่เลือก</div>
  @else
  <table>
    <thead>
      <tr>
        <th>ประเภทการลา</th>
        <th style="text-align:right;">จำนวนครั้ง</th>
        <th style="text-align:right;">รวมวันลา</th>
        <th style="text-align:right;">สัดส่วน</th>
      </tr>
    </thead>
    <tbody>
      @foreach($leaveTypes as $lt)
        @php
          $typeLeaves = $leaves->where('leave_type_id', $lt->id);
          $count = $typeLeaves->count();
          $days  = $typeLeaves->sum('total_days');
          $pct   = $totalCount > 0 ? round(($count / $totalCount) * 100) : 0;
        @endphp
        @if($count > 0)
        <tr>
          <td>{{ $lt->name }}</td>
          <td style="text-align:right;">{{ $count }} ครั้ง</td>
          <td style="text-align:right;">{{ $days }} วัน</td>
          <td style="text-align:right;">{{ $pct }}%</td>
        </tr>
        @endif
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <td>รวมทั้งหมด</td>
        <td style="text-align:right;">{{ $totalCount }} ครั้ง</td>
        <td style="text-align:right;">{{ $totalDays }} วัน</td>
        <td style="text-align:right;">100%</td>
      </tr>
    </tfoot>
  </table>
  @endif
</div>

{{-- ===== DETAIL TABLE ===== --}}
<div class="section">
  <div class="section-title">รายละเอียดการลา</div>
  @if($totalCount === 0)
    <div class="no-data">ไม่มีข้อมูลการลาในช่วงเวลาที่เลือก</div>
  @else
  <table>
    <thead>
      <tr>
        <th style="width:4%;">#</th>
        @if(!$selectedUserName)
        <th style="width:18%;">ชื่อ-นามสกุล</th>
        <th style="width:14%;">หน่วยงาน</th>
        @endif
        <th>ประเภทการลา</th>
        <th style="width:15%;">วันที่เริ่มลา</th>
        <th style="width:15%;">วันที่สิ้นสุด</th>
        <th style="width:8%; text-align:right;">จำนวนวัน</th>
        <th>เหตุผล</th>
      </tr>
    </thead>
    <tbody>
      @foreach($leaves as $i => $leave)
      <tr>
        <td>{{ $i + 1 }}</td>
        @if(!$selectedUserName)
        <td>{{ $leave->user->name ?? '—' }}</td>
        <td>{{ $leave->user->department->name ?? '—' }}</td>
        @endif
        <td>{{ $leave->type->name ?? '—' }}</td>
        <td>{{ $thaiDateService->toThaiDate($leave->start_date) }}</td>
        <td>{{ $thaiDateService->toThaiDate($leave->end_date) }}</td>
        <td style="text-align:right;">{{ $leave->total_days }} วัน</td>
        <td>{{ $leave->reason ?? '—' }}</td>
      </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <td colspan="{{ $selectedUserName ? 5 : 7 }}" style="text-align:right;">รวมทั้งหมด</td>
        <td style="text-align:right;">{{ $totalDays }} วัน</td>
        <td></td>
      </tr>
    </tfoot>
  </table>
  @endif
</div>

<div class="footer">
  ระบบลางานออนไลน์ E-Leave — พิมพ์โดยระบบอัตโนมัติ
</div>
</body>
</html>
