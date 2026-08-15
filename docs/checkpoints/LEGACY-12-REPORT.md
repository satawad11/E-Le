# LEGACY-12 — Future Module Readiness

**Date:** 2026-08-15

**Result:** **LEGACY-12 = PASS / STOP**

## 1. Outcome

E-la now has an extension architecture for adding modules without adding business dependencies to Core. LEGACY-12 did not create business logic, schema, workflow or permissions for Finance, Procurement, Asset, Planning & Budget, Vehicle Booking, Room Booking, Maintenance, Meeting or any other hypothetical module.

The registry contains only capabilities that exist today: `system`, `leave`, `attendance`, `overtime`, `saraban`, `notification` and `reporting`.

## 2. Module contract and registry

`ModuleContract` and `ModuleDefinition` provide one declaration surface for:

- module code and Thai/English display name
- semantic version, order, enabled state and dependencies
- routes and module-owned route files
- menus and permissions
- policies and workflow definitions
- settings and notification types
- reports and audit events
- module service providers and safe-disable route patterns

`ModuleRegistry` validates required fields, code format, semantic version, duplicate codes, missing dependencies, circular dependencies and new permission naming. New permissions must use `module.resource.action`. Existing non-canonical names remain explicitly grandfathered for compatibility and are not the standard for future modules.

## 3. Dependency direction

- shared `ModuleRegistry`, module middleware, Core `AppServiceProvider` and generic `WorkflowEngine` do not import Leave, Attendance, OT or Saraban classes
- domain policies/listeners/bindings moved from `AppServiceProvider` into module-owned providers
- domain workflow provisioners remain adapters that call the generic Workflow Engine; the engine does not call back into those adapters
- modules depend on `system`; `overtime` also depends on `attendance`
- a disabled dependency disables dependants transitively

The architectural dependency test scans the shared registry/provider/middleware/engine boundary for forbidden business imports.

## 4. Safe enable and disable

Deployment switches are available for every current module:

`MODULE_SYSTEM_ENABLED`, `MODULE_LEAVE_ENABLED`, `MODULE_ATTENDANCE_ENABLED`, `MODULE_OVERTIME_ENABLED`, `MODULE_SARABAN_ENABLED`, `MODULE_NOTIFICATION_ENABLED`, and `MODULE_REPORTING_ENABLED`.

Effective enabled state includes all transitive dependencies. Disabled modules:

- return 404 for their declared route surface
- do not load new module route files
- disappear from permission-filtered menus, including for administrators
- are omitted as source datasets in reporting/evaluation

Providers remain registered as container adapters so queued/in-flight objects can still resolve during a disable rollout. This does not enable routes or grant authorization.

## 5. Menu and backend authorization

`MenuService` now combines active database menus, effective module state and effective user permissions. Its cache key includes the module-state fingerprint.

Menu visibility remains presentation only. A regression test attaches an unrelated permission to an Admin route menu, proves the menu becomes visible, and proves the backend endpoint still returns 403 because its own route authorization is independent.

Unknown/unregistered menu module codes are hidden by default.

## 6. Extension workflow

`docs/MODULE-EXTENSION-GUIDE.md` documents:

- domain boundary and dependency direction
- complete manifest fields
- module route files and route patterns
- mandatory backend permission/policy/scope checks
- additive and forward-only migration rules
- idempotent permission/workflow/template registration
- deployment ordering and enable switches
- required tests, observability and rollback considerations

The existing admin module metadata endpoint remains compatible and now returns validated registry metadata and effective/configured state.

## 7. Database and API impact

- **Database migration:** none
- **Schema change:** none
- **New business module:** none
- **New public API:** none
- existing `/api/admin/permissions/modules` is enriched with registry metadata
- current routes remain unchanged while all modules are enabled, which is the default

## 8. Verification

| Gate | Result |
|---|---|
| Module Registry critical tests | **8 passed / 142 assertions** |
| Provider/policy/reporting targeted regression | **61 passed / 325 assertions** |
| Full backend suite | **456 passed / 1,328 assertions** |
| Laravel Pint | **PASS / 452 files** |
| Frontend production build | **PASS / 879 modules** |
| Composer audit | **0 security advisories** |
| Docker Compose validation | **PASS** |
| MySQL migration status | **all migrations Ran; no LEGACY-12 migration** |

Critical tests cover complete declarations, absence of invented future modules, permission naming, unknown/circular dependencies, transitive disable, route 404, menu disable, permission-driven menu visibility, independent backend authorization, Core dependency scan and admin registry metadata.

## 9. Residual risks

- current legacy module routes still live mainly in central route files and are protected by registry route patterns; new modules should use their own `route_files`
- several legacy domain workflow adapter classes still use the historical `App\\Services\\Workflow` namespace even though the Core engine is dependency-clean; relocate them into module namespaces during a future non-functional cleanup
- existing legacy permission aliases remain until roles and callers can be migrated safely
- enable switches are deployment configuration, not a database-driven live feature-toggle UI; this avoids unreviewed runtime changes but requires a controlled rollout/restart
- route-pattern completeness becomes a review obligation for a new module and is covered by the extension checklist

## 10. Final status

**PASS แล้วหยุด** — the platform has a validated Module Registry/Contract, dependency-safe Core boundary, transitive enable/disable behavior, permission-derived menus, independent backend authorization and a clear registration/migration guide, without inventing future business modules.
