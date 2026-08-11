# MODULE STANDARDS
Every module registers code, Thai/English name, route, icon key, version, enabled flag, dependencies, permissions, order.

A module must provide: schema/migration, service/API, authorization policies, UI, audit events, tests, docs, health/observability where relevant.

Sidebar is generated from enabled modules + effective permissions; hiding menu is not authorization.
