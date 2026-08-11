# CORE-01 Organization
Goal: hierarchy ไม่จำกัดระดับและ effective history.

Entities: organizations, organization_unit_types, organization_units, unit_managers.
Required fields: code, names, parent, type, status, effective dates, sort order.
Rules: no cycles; code unique in scope; moving unit creates auditable history; no hard-coded division names.
APIs/UI: tree, create/edit, move, activate/deactivate, assign manager, search.
Tests: cycle prevention, scope, effective dates, permission.
