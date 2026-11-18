
# Enterprise Backend Quality Gates

## Purpose

Use these gates for significant features, architecture reviews, security reviews, code reviews, and production-readiness assessments.

Adjust review depth according to actual risk.

For each applicable check, record:

- PASS: Verified with evidence.
- FAIL: A known defect exists.
- NOT APPLICABLE: The check does not apply, with reason.
- NOT VERIFIED: Evidence is unavailable.

Never mark a check as PASS based only on generated code or assumptions.

## 1. Requirements and Architecture

- [ ] Business outcome is understood.
- [ ] Acceptance criteria are defined.
- [ ] Confirmed requirements are separated from assumptions.
- [ ] Domain boundaries are clear.
- [ ] Dependencies are intentional.
- [ ] Architecture matches installed Laravel and PHP versions.
- [ ] No unnecessary abstractions are introduced.
- [ ] Failure and recovery behavior is considered.
- [ ] Backward compatibility is reviewed.
- [ ] Scope is respected.
- [ ] Complexity is proportional to the task.

## 2. Database and Persistence

- [ ] Relationships reflect business rules.
- [ ] Foreign keys are appropriate.
- [ ] Unique constraints prevent invalid duplicates.
- [ ] Data types and precision are correct.
- [ ] Nullability is deliberate.
- [ ] Deletion behavior is explicit.
- [ ] Indexes support expected queries.
- [ ] Transactions cover atomic operations.
- [ ] Concurrent updates are handled where necessary.
- [ ] N+1 and unbounded queries are prevented.
- [ ] Migrations preserve existing data.
- [ ] Large-table migration impact is considered.
- [ ] Rollback or forward-fix strategy is documented.
- [ ] Destructive database commands require approval.

## 3. Security and Authorization

- [ ] Authentication is appropriate.
- [ ] Authorization is enforced server-side.
- [ ] Resource ownership is checked.
- [ ] IDOR risks are tested.
- [ ] Cross-user access is tested.
- [ ] Cross-project and cross-team access are tested where relevant.
- [ ] Client-supplied identity and ownership fields are not trusted.
- [ ] Writable fields are explicitly controlled.
- [ ] Sensitive data is not exposed.
- [ ] Files and exports are protected.
- [ ] Background jobs respect authorization requirements.
- [ ] Rate limits are appropriate.
- [ ] Secrets are protected.
- [ ] Webhook security is verified where applicable.
- [ ] Error responses do not leak internals.
- [ ] Relevant security guidelines are applied.

## 4. Multi-Tenancy

When applicable:

- [ ] Tenancy strategy is documented.
- [ ] Tenant resolution is trusted.
- [ ] Tenant membership is verified.
- [ ] Tenant scope is enforced for reads and writes.
- [ ] Cross-tenant relationships are prevented.
- [ ] Cache keys are tenant-aware.
- [ ] Jobs restore and clear tenant context.
- [ ] Files are tenant-protected.
- [ ] Search and reporting are tenant-scoped.
- [ ] Exports are tenant-scoped.
- [ ] Administrative cross-tenant access is restricted.
- [ ] Cross-tenant denial tests exist.

## 5. API Contracts

- [ ] Endpoint purpose and HTTP semantics are clear.
- [ ] Authentication is defined.
- [ ] Authorization is explicit.
- [ ] Input validation is complete.
- [ ] Response fields are controlled.
- [ ] Status codes are appropriate.
- [ ] Error structures are consistent.
- [ ] Pagination is applied where required.
- [ ] Page sizes are bounded.
- [ ] Filters and sorting are whitelisted.
- [ ] Search complexity is bounded.
- [ ] Rate limits are defined.
- [ ] Idempotency is handled where necessary.
- [ ] API documentation matches actual behavior.

## 6. Business Logic and Workflows

- [ ] Business invariants are centralized.
- [ ] Valid states are explicit.
- [ ] Valid transitions are defined.
- [ ] Transition permissions are enforced.
- [ ] Invalid transitions are rejected.
- [ ] Actor and timestamp are recorded.
- [ ] Side effects are intentional.
- [ ] Rejection and reopening paths are defined.
- [ ] Failure and compensation behavior is considered.
- [ ] Duplicate submissions are handled.
- [ ] Concurrent updates are addressed.
- [ ] Critical calculations have an authoritative definition.

## 7. Queues and Integrations

- [ ] Jobs are idempotent where required.
- [ ] Retries are bounded.
- [ ] Timeouts are appropriate.
- [ ] Permanent failures are observable.
- [ ] Recovery and replay are considered.
- [ ] Dispatch timing respects transaction boundaries.
- [ ] Duplicate events are handled.
- [ ] Webhook signatures are verified.
- [ ] Replay and out-of-order events are considered.
- [ ] External calls have timeouts.
- [ ] Provider failures are handled.
- [ ] Credentials are scoped and protected.

## 8. Files and Storage

- [ ] Upload content and size are validated.
- [ ] Storage names are server-generated.
- [ ] Path traversal is prevented.
- [ ] Private file access is authorized.
- [ ] File ownership is verified.
- [ ] Temporary URLs are appropriately scoped.
- [ ] Retention and deletion behavior is defined.
- [ ] Tenant isolation is enforced where applicable.

## 9. Performance and Reliability

- [ ] Queries have been reviewed.
- [ ] N+1 behavior is addressed.
- [ ] Collection operations are bounded.
- [ ] Pagination or chunking is used appropriately.
- [ ] Indexes are justified.
- [ ] Payload sizes are reasonable.
- [ ] Expensive work is queued where appropriate.
- [ ] Cache invalidation is defined.
- [ ] Locking and contention risks are considered.
- [ ] Performance claims have evidence or are marked unverified.

## 10. Testing and Code Quality

- [ ] Tests cover successful behavior.
- [ ] Expected failures are tested.
- [ ] Authorization denials are tested.
- [ ] Validation failures are tested.
- [ ] State transitions are tested.
- [ ] Database constraints are tested.
- [ ] Concurrency is tested where warranted.
- [ ] Integration failures are tested where relevant.
- [ ] Regression tests are added.
- [ ] Existing testing conventions are followed.
- [ ] Installed quality tools are executed where relevant.
- [ ] Skipped tests are reported.
- [ ] No dependency is added without justification.

## 11. Production Readiness

- [ ] Configuration is production-appropriate.
- [ ] Secrets use approved management.
- [ ] APP_DEBUG is disabled in production.
- [ ] Migrations and deployment order are documented.
- [ ] Queue workers are accounted for.
- [ ] Scheduler behavior is documented.
- [ ] Storage permissions are reviewed.
- [ ] Health monitoring requirements are identified.
- [ ] Error and queue-failure monitoring is considered.
- [ ] Backup and restore implications are understood.
- [ ] Recovery strategy is documented.
- [ ] Rate limits and timeouts are appropriate.
- [ ] Remaining risks are visible.
- [ ] Production operations have appropriate approval.

## 12. Review Report

For significant changes, produce:

### Scope
Modules, endpoints, migrations, workflows, and infrastructure reviewed.

### Gate Summary

| Gate | Status | Evidence |
|---|---|---|
| Architecture | PASS / FAIL / NOT VERIFIED | Evidence |
| Database | PASS / FAIL / NOT VERIFIED | Evidence |
| Security | PASS / FAIL / NOT VERIFIED | Evidence |
| Authorization | PASS / FAIL / NOT VERIFIED | Evidence |
| Multi-tenancy | PASS / FAIL / N/A | Evidence |
| Testing | PASS / FAIL / NOT VERIFIED | Evidence |
| Production | PASS / FAIL / NOT VERIFIED | Evidence |

### Findings

For each finding:

- Severity.
- Affected component.
- Description.
- Business impact.
- Evidence.
- Required correction.
- Acceptance criteria.

### Residual Risks

Document accepted risks, missing evidence, environmental limitations, and decisions requiring human approval.

## Final Rule

Passing a checklist does not automatically prove production readiness.

Production readiness depends on the actual application, dependencies, data volume, deployment topology, threat model, infrastructure, monitoring, backup, and operational processes.
