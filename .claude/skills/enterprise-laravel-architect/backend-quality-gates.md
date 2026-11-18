# Backend Quality Gates

## Purpose and use

Apply the relevant gates to significant Laravel backend features, architecture and security reviews, code reviews, and production-readiness assessments. Tailor depth to risk: authentication, authorization, financial or employee data, destructive operations, and externally exposed endpoints require stricter review. A checklist is a verification aid, not proof by itself.

For each applicable item, record one of: **Pass** (verified), **Fail** (known defect), **Not applicable** (with reason), or **Not verified** (evidence unavailable). Do not mark an item as passed based only on code generation or assumption.

## 1. Requirements and architecture gate

- [ ] Business outcome, actors, acceptance criteria, and non-functional requirements are understood.
- [ ] Confirmed requirements are distinguished from assumptions and unresolved policy decisions.
- [ ] Domain ownership and module boundaries are clear.
- [ ] Dependencies are intentional; no circular module coupling is introduced.
- [ ] The design fits the existing Laravel version, project conventions, and installed dependencies.
- [ ] Abstractions have a concrete benefit; no unnecessary repository/service/interface/DTO layers.
- [ ] Failure modes, concurrency, retries, and side effects are considered.
- [ ] Backward compatibility and rollout/rollback implications are understood.
- [ ] Frontend implementation remains out of scope.

## 2. Database and persistence gate

- [ ] Tables/entities, keys, relationships, nullability, and deletion behavior reflect business invariants.
- [ ] Foreign keys and unique constraints prevent invalid or duplicate data where supported.
- [ ] Data types, precision, timezone semantics, and JSON usage are appropriate.
- [ ] Indexes support actual filters, joins, sort orders, and aggregate query patterns.
- [ ] Transactions cover multi-write operations that must be atomic.
- [ ] Concurrent updates and duplicate submissions are handled where relevant.
- [ ] Migrations are compatible with existing data and production deployment constraints.
- [ ] Large-table locks, backfills, defaults, and expand/contract rollout are considered.
- [ ] No destructive migration or database command is run without explicit authorization.
- [ ] Seeders/factories do not introduce unsafe production behavior.

## 3. Security and authorization gate

- [ ] Authentication is required where appropriate and uses the project's approved mechanism.
- [ ] Authorization is enforced server-side for every relevant read and write.
- [ ] Resource ownership and organization/project/team scope are checked.
- [ ] IDOR and cross-user/cross-project/cross-team access are tested.
- [ ] Roles are not treated as a substitute for resource-level authorization.
- [ ] Administrative privileges do not silently grant unrestricted business-data access.
- [ ] Client-supplied actor, owner, role, permission, and scope identifiers are not trusted.
- [ ] Input is validated and writable fields are explicitly controlled.
- [ ] Sensitive attributes, relationships, and internal implementation details are not exposed.
- [ ] File access, exports, nested resources, bulk operations, and background jobs enforce authorization.
- [ ] Rate limits protect sensitive and abuse-prone operations.
- [ ] Secrets are not hardcoded, committed, logged, or returned.
- [ ] Webhook signatures and replay/deduplication protections are verified where applicable.
- [ ] Error responses do not leak secrets, stack traces, or sensitive internals.

## 4. API contract gate

- [ ] Each endpoint has a clear purpose, HTTP method, URI, and authentication requirement.
- [ ] Authorization behavior and resource scope are explicit.
- [ ] Request parameters and validation rules are documented and tested.
- [ ] Response fields are explicit and do not expose raw model internals accidentally.
- [ ] HTTP status codes and error structure are consistent with project conventions.
- [ ] Pagination is applied to collections and page sizes are bounded.
- [ ] Filtering, sorting, and search fields are whitelisted and bounded.
- [ ] Rate limiting and idempotency behavior are defined where needed.
- [ ] API changes are backward-compatible or an approved breaking change is documented.
- [ ] OpenAPI or other API documentation matches the implementation when used.

## 5. Business logic and workflow gate

- [ ] Business invariants are centralized and cannot be bypassed through alternate entry points.
- [ ] Valid states and transitions are explicit.
- [ ] Transition permissions and required data are enforced.
- [ ] Invalid transitions are rejected with predictable errors.
- [ ] Actor, timestamp, and relevant before/after state are recorded.
- [ ] Side effects, notifications, and audit events are intentional.
- [ ] Rejection, cancellation, reopening, blocking, and rollback/compensation paths are considered.
- [ ] Critical calculations and metric definitions have one authoritative implementation.
- [ ] Concurrency and duplicate-request behavior are defined.

## 6. Performance and scalability gate

- [ ] Queries have been inspected for avoidable N+1 behavior.
- [ ] Eager loading and selected columns are appropriate.
- [ ] Collection endpoints and large data operations are bounded/paginated/chunked.
- [ ] Aggregate calculations are performed efficiently and consistently.
- [ ] Indexes are justified by query patterns or measured plans.
- [ ] API payload size and serialization cost are appropriate.
- [ ] Slow work is queued when appropriate, without moving consistency-critical work out of the transaction incorrectly.
- [ ] Cache use has a documented key, invalidation strategy, staleness tolerance, and fallback.
- [ ] Performance claims are supported by measurements or clearly labeled as unverified.

## 7. Queues, events, notifications, and schedules gate

- [ ] Jobs are idempotent or have a deliberate duplicate-processing strategy.
- [ ] Timeouts, retries, backoff, rate limits, and failure behavior are configured appropriately.
- [ ] Permanent failures are observable and have a recovery/replay path where required.
- [ ] Dispatch timing respects transaction commit when necessary.
- [ ] Scheduled tasks handle overlap, timezone, and missed runs deliberately.
- [ ] Notification recipients are authorized and preferences are respected.
- [ ] Duplicate notifications and self-notifications are handled according to policy.
- [ ] Tests cover dispatch, handling, failure, and relevant side effects.

## 8. File and external integration gate

- [ ] Upload size and content/type are validated.
- [ ] Storage names are server-generated and path traversal is prevented.
- [ ] Private files are served only through authorized access or appropriately scoped temporary URLs.
- [ ] File metadata and parent-record authorization are enforced.
- [ ] External credentials are stored securely and scoped minimally.
- [ ] External calls have timeouts and bounded retry behavior.
- [ ] Idempotency, provider rate limits, and partial failure are handled.
- [ ] Webhook signatures, replay windows, duplicate events, and out-of-order events are considered.
- [ ] Optional integrations cannot unnecessarily break core business operations.
- [ ] Integration status and failure details are observable without leaking secrets.

## 9. Audit and observability gate

- [ ] Important business changes capture actor, action, entity, and timestamp.
- [ ] Before/after values or a safe change summary are recorded when required.
- [ ] Audit history is protected from unauthorized mutation and access.
- [ ] Secrets and unnecessary personal data are excluded from logs and audit payloads.
- [ ] Business audit, application logs, security events, integration logs, and queue failures are distinguishable.
- [ ] Logs include sufficient safe context/correlation identifiers for diagnosis.
- [ ] Retention and access rules are considered for sensitive records.

## 10. Testing and code quality gate

- [ ] Tests cover successful behavior and expected failures.
- [ ] Authorization denials and cross-scope access are explicitly tested.
- [ ] Validation, edge cases, and invalid state transitions are tested.
- [ ] Database constraints and transactional behavior are tested where relevant.
- [ ] Duplicate requests and concurrency are tested where risk warrants.
- [ ] Queue, notification, event, webhook, and integration behavior is tested where relevant.
- [ ] Regression tests cover affected existing behavior.
- [ ] Tests follow the existing Pest/PHPUnit conventions.
- [ ] Pint, PHPStan/Larastan, and other installed quality tools are run when relevant.
- [ ] Actual commands, environment limitations, failures, and skipped tests are reported accurately.
- [ ] No dependency was added without compatibility and necessity review.

## 11. Production-readiness gate

- [ ] Configuration and secrets are supplied through approved environment/secret management.
- [ ] Required migrations and deployment order are documented.
- [ ] Queue workers, scheduler, storage, and external dependencies are accounted for operationally.
- [ ] Health, error, queue-failure, and integration monitoring needs are identified.
- [ ] Backup, restore, retention, and recovery implications are understood for data changes.
- [ ] Rollback or forward-fix strategy is documented for risky releases.
- [ ] Rate limits, timeouts, and resource limits are appropriate.
- [ ] API and operational documentation reflect actual behavior.
- [ ] Remaining risks and unverified assumptions are visible to the release owner.

## 12. Review outcome

For each significant change, provide a concise record:

- **Scope reviewed:** modules, endpoints, migrations, and workflows.
- **Gate status:** Pass / Fail / Not verified, with material exceptions.
- **Evidence:** tests, commands, query plans, code paths, or configuration inspected.
- **Findings:** severity, impact, and affected behavior.
- **Required fixes:** concrete actions and acceptance criteria.
- **Residual risks:** accepted risks or items requiring product/operations decisions.

Do not label a feature "production-ready" solely because all checklist boxes were checked. Production readiness also depends on the actual deployment environment, operational controls, data volume, threat model, and release process.
