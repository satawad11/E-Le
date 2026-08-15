<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #222; }
        h1 { font-size: 16px; margin: 0 0 5px; }
        .meta { color: #555; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #777; padding: 4px; text-align: left; }
        th { background: #eee; }
    </style>
</head>
<body>
<h1>{{ $dataset->title }}</h1>
<div class="meta">
    Fiscal year {{ data_get($dataset->meta, 'filters.fiscal_year') }} ·
    {{ data_get($dataset->meta, 'filters.from') }} – {{ data_get($dataset->meta, 'filters.to') }}
</div>
<table>
    <thead><tr>@foreach ($dataset->columns as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse ($dataset->rows as $row)
        <tr>@foreach ($dataset->columns as $key => $label)<td>{{ is_bool($row[$key] ?? null) ? (($row[$key] ?? false) ? 'Yes' : 'No') : ($row[$key] ?? '') }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ max(count($dataset->columns), 1) }}">No data</td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
