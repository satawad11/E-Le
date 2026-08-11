# TESTING STANDARDS
Required: unit, integration, API, authorization, IDOR, workflow, policy, E2E smoke.

Critical matrices:
- role x scope x action
- personnel type x policy version
- workflow state x allowed transition
- effective date boundaries
- concurrency/idempotency for imports and approvals

No phase passes with failing security-critical tests.
