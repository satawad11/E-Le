# WORKFLOW ENGINE
Versioned configurable workflows supporting sequential, parallel, conditional, reject, return, cancel, delegation, acting approver, escalation and timeout.

## Core entities
workflow_definitions, workflow_versions, workflow_steps, workflow_transitions, workflow_instances, workflow_tasks, workflow_actions, delegations.

## Rules
A running instance pins its workflow version. Editing a workflow never mutates active historical semantics. Every transition is authorized and audited.
