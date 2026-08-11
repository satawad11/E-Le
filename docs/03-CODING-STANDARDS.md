# CODING STANDARDS
- English identifiers, Thai UI copy
- Small cohesive modules; no god service
- Explicit domain errors and standard API error mapping
- Avoid `any`; validate external input
- No business rule in React components
- No database query directly from UI
- Functions should be deterministic where possible
- Comments explain why, not obvious what
- Use transaction for multi-table invariant changes
