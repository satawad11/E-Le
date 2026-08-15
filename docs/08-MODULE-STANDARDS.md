# MODULE STANDARDS
Every module registers code, Thai/English name, route, icon key, version, enabled flag, dependencies, permissions, order.

A module must provide: schema/migration, service/API, authorization policies, UI, audit events, tests, docs, health/observability where relevant.

Sidebar is generated from enabled modules + effective permissions; hiding menu is not authorization.

LEGACY-12 implements this contract through `ModuleContract`, `ModuleRegistry`, and `config/module_registry.php`. New permissions must use `module.resource.action`; legacy aliases are compatibility-only. Dependencies are evaluated transitively, disabled modules expose neither routes nor menus, and backend policies/permissions remain mandatory independently of menu visibility.

Registration, migration, deployment and test steps are documented in `docs/MODULE-EXTENSION-GUIDE.md`.
