# LEGACY-11 — Evaluation & Reporting

**Date:** 2026-08-15

**Input reviewed:** relevant sections of LEGACY-04, LEGACY-06, LEGACY-07, LEGACY-08, LEGACY-09, LEGACY-10 and the LEGACY-11 migration plan

**Result:** **LEGACY-11 = PASS / STOP**

## Outcome

An additive, unified reporting layer now provides Personnel, Leave, Leave Balance, Attendance, Late, Absence, Overtime, Saraban and Executive Summary datasets. Existing Government Report URLs and response formats remain available.

The new evaluation service returns evidence metrics only. It intentionally contains no score, weight, ranking, threshold, pass/fail decision or compensation formula because the organization has not approved those rules.

## API contracts

- `GET /api/reporting/{report}` — JSON dataset
- `GET /api/reporting/{report}/export/{format}` — `pdf`, `xlsx`, `docx` or `csv`
- `GET /api/reporting/evaluation-metrics` — raw personnel evaluation evidence

Supported report names are `personnel`, `leave`, `leave_balance`, `attendance`, `late`, `absence`, `ot`, `saraban` and `executive`.

All datasets share fiscal year, date range, organization unit, employee and personnel-type filters. Explicit `from`/`to` dates take precedence over fiscal-year dates.

## Permission and scope

| Dataset | Permission |
|---|---|
| Personnel | `view_users` |
| Leave / Leave Balance | `leave.report.view` |
| Attendance / Late / Absence | `attendance.view_all` |
| Overtime | `attendance.ot_summary` |
| Saraban | `saraban.document.report` |

`OrgScopeResolver` is applied before query execution and before every export. An organization filter outside the caller's visible subtree returns 403. Saraban classified documents are omitted unless the caller has `saraban.document.view_classified`.

Government leave register/summary, promotion screening and Saraban summary/CSV retain their old contracts, but now also enforce configured permission scope. Existing organization-wide behavior remains unchanged when no narrower explicit scope exists.

## Design and compatibility

- `ReportFilter` owns fiscal-year and common filter resolution.
- `ReportingScopeService` owns permission mapping and scoped personnel queries.
- `ReportingDataService` performs aggregate queries and eager loading without per-row queries.
- `ReportDataset` is the common JSON/export contract.
- `ReportExportService` creates CSV, XLSX and DOCX; PDF uses the common report view.
- `EvaluationMetricsService` returns Leave, Attendance, Late, Absence and optional OT facts without evaluating a person.
- Executive Summary includes only modules the caller may view.
- Spreadsheet/CSV text beginning with formula characters is escaped.
- Every unified export writes `report.exported` to central audit with non-sensitive context.
- No schema, approval workflow or business status changed.
- Existing promotion-screening formulas remain untouched for backward compatibility; the new evaluation endpoint does not reuse them.
- OT approved, actual and certified minutes remain independent values.

## Performance

Reporting uses database aggregation, scoped subqueries and eager loading. A query-budget regression with 20 additional users proves personnel query count remains bounded rather than growing per employee.

## Verification

| Gate | Result |
|---|---|
| Reporting + Government Report regression | **24 passed / 97 assertions** |
| Full backend suite | **448 passed / 1,186 assertions** |
| Laravel Pint | **PASS / 440 files** |
| Frontend production build | **PASS / 879 modules** |
| PDF response | `%PDF` signature and `application/pdf` verified |
| Excel / Word / CSV | download contracts and scoped CSV content verified |

The reporting suite covers permission denial, explicit organization scope, date filters, Personnel/Leave/Attendance/Late isolation, raw evaluation metrics, OT time separation, all four exports, export audit and bounded query count. Existing Government leave register, promotion screening and Saraban audit suites also pass.

## Residual risks

- Large production exports are synchronous. If real volume causes timeouts, move generation behind an authorized queued export job without changing scope rules.
- XLSX/DOCX output is a minimal standards-based table. Rich organization-specific styling needs an approved template.
- Existing promotion screening still contains legacy configurable criteria. It is preserved for compatibility and is not the new evaluation formula.
- Query performance is guarded in SQLite tests; production slow-query monitoring should confirm index selectivity for actual data distributions.

## Final status

**PASS แล้วหยุด** — reporting is scope-safe, export-capable, N+1 guarded and backward compatible; evaluation remains evidence-only until the organization defines a formula.
