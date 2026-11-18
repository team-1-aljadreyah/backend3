# Project and Team Management Platform — Backend Domain Reference

## Purpose and applicability

This document describes the business domain for the company's Project and Team Management Platform. It is a reusable domain reference, not a universal Laravel schema and not a mandate to implement every capability. Load it only when the active project concerns project, team, employee, task, sprint, issue, communication, performance, or management reporting, or when the user explicitly requests this platform.

Treat the requirements below as domain context. Confirm project-specific policies, role scopes, lifecycle details, retention rules, and metric definitions before encoding them. Do not infer missing approval rules, evaluation formulas, or organization policies.

## 1. Business objective and scope

The platform provides backend capabilities to manage and monitor:

- Projects, objectives, plans, phases, and templates.
- Teams, employees, project membership, and role assignments.
- Tasks, dependencies, workflow, reviews, testing, and completion.
- Optional sprints and agile reporting.
- Issues and bugs.
- Project/team/direct conversations and announcements.
- Notifications and read receipts.
- Objective work indicators and managerial evaluations.
- Executive, project, team, employee, and sprint reporting.
- Optional GitHub and future external integrations.
- Business audit history.

The platform must support software and non-software projects. Do not force every project into phases, sprints, or a single delivery methodology.

## 2. Actors, roles, and access scope

Initial role concepts:

- CEO.
- Project Manager.
- Monitoring & Evaluation Officer.
- Team Leader.
- Employee / Developer.
- System Administrator.
- HR (future optional role).

Roles should be configurable rather than hard-coded as the only authorization mechanism. Actual access may also depend on organization scope, project membership, team membership, resource ownership, assignment, and workflow state.

Model authorization as capabilities plus resource scope. Examples of policy questions to resolve:

- Can a CEO view all company projects, all employee records, and all message content, or only defined summaries?
- Can a Project Manager manage only assigned projects?
- Can a Monitoring & Evaluation Officer read progress and evaluations across all projects, or only a portfolio?
- Can a Team Leader manage team membership, task assignments, and approvals?
- Can employees see only assigned tasks or all tasks in projects they belong to?
- What can System Administrators do to platform configuration versus business data?
- Which actions require separation of duties or approval by another actor?

A System Administrator must not automatically receive unrestricted access to business content unless explicitly approved. Enforce access in backend policies and scoped queries; frontend visibility is never a security control.

## 3. Core domain concepts

The following are conceptual entities, not a prescribed schema. Inspect actual requirements and existing code before selecting tables, aggregates, or relationships.

### Organization and identity

- User / Employee: authenticated identity and, where applicable, employment profile.
- Role and Permission: configurable capabilities and role assignments.
- Team: organizational group with members and leadership.
- Team Membership: membership, role, effective dates, and status where historical membership matters.
- Project Membership: user participation, project-specific role, and effective dates.

Decide whether identity and employee profile are separate concerns. Avoid putting all HR attributes into the authentication user record. HR functionality is future optional scope.

### Project

A project may contain:

- Name, description, objectives, manager, assigned team, and members.
- Start date, expected completion date, status, priority, and progress.
- Attachments and optional project template.
- Optional phases and tasks directly attached to the project.
- Optional workflow settings and integration settings.

Project statuses, priority vocabulary, ownership rules, archive behavior, and progress calculation must be approved. Progress must not be an arbitrary client-supplied percentage. Define whether progress is derived from tasks, weighted deliverables, milestones, manual manager input, or a documented combination.

Project Manager replacement, plan changes after work begins, suspension, cancellation, and team membership changes require explicit authorization, audit history, and rules for existing assignments.

### Phase and deliverable

Phases are optional. A phase belongs to a project and may group tasks, dates, deliverables, and progress. Projects may also contain tasks without phases. Do not require a phase foreign key for every task.

Clarify whether phases are sequential, parallel, reorderable, approval-gated, or purely organizational. Define how moving a task between phases affects progress and history.

### Task

A task may contain:

- Title and description.
- Project and optional phase.
- Assignee and, if required, creator/reporter.
- Start date, due date, priority, status, and attachments.
- Comments, activity history, dependencies, and review/testing data.

Canonical task statuses and reporting meanings:

| Canonical status | Meaning |
|---|---|
| New | Captured but not yet committed to active work |
| To Do | Ready and accepted into a work queue |
| In Progress | Work is actively underway |
| In Review | Submitted for review/approval |
| Testing | Under verification or quality testing |
| Completed | Completion criteria and required approval are satisfied |
| Blocked | Work cannot proceed and a reason is recorded |

Projects may define custom statuses, but each custom status must map to a canonical reporting meaning or an explicitly approved reporting category. Preserve historical status values/meanings when workflow configuration changes.

Do not allow arbitrary status updates. Define allowed transitions, permitted actors, required fields, side effects, and notification recipients. Require a reason when a task becomes Blocked. Define the review rejection path (for example, return to In Progress or To Do) and the testing failure path. Completion must follow the approved completion/approval workflow; do not assume that an assignee can self-approve.

Record every status transition with actor, timestamp, previous state, new state, and an appropriate reason/context. Track overdue state from due dates and completion state rather than relying on a manually maintained flag, unless a snapshot is explicitly required for reporting.

### Task dependencies

Support dependencies between tasks when needed. Prevent self-dependencies and cycles. Define dependency semantics (for example, finish-to-start) before implementing scheduling behavior. Clarify what happens when a predecessor is reopened, blocked, deleted, or moved to another project. Enforce project scope if cross-project dependencies are not explicitly allowed.

### Sprint

Sprints are optional and primarily relevant to agile projects. Support creation, name/goal, start and end dates, sprint task assignment, planned work, completed work, remaining work, and velocity reporting.

Define whether tasks may be added or removed after a sprint starts, how estimates are represented, and whether velocity is based on points, hours, or another approved unit. When a sprint closes, unfinished tasks must be handled by an approved rule (carry forward, return to backlog, or another explicit outcome) and audited. Do not require sprints for non-agile projects.

### Issue and bug

An issue may include title, detailed description, attachments, related project, optional phase/task, severity, priority, assigned developer, expected resolution date, and current status.

Lifecycle concepts:

1. Reported.
2. Assigned.
3. In Progress.
4. In Testing.
5. Reopened.
6. Resolved.
7. Closed.

Define which roles may assign, resolve, test, reopen, and close issues. A failed test or unresolved issue must be returnable to the developer through a valid transition. Preserve a complete status and assignment history. Support reports for open, closed, overdue, and project-specific issues. Clarify whether "Resolved" and "Closed" represent separate verification/administrative steps.

## 4. Communication and announcements

### Conversations and messages

Support project conversations, team conversations, direct conversations, text messages, attachments, replies, mentions, and conversation history.

Access rules:
- Project conversations: authorized project members only, subject to any approved exceptions.
- Team conversations: authorized team members only.
- Direct conversations: participants only.
- Message attachments: same or stricter authorization as the parent message/conversation.

Derive sender identity from the authenticated principal. Never trust client-supplied sender IDs or membership claims. Re-check membership and visibility when reading history, downloading attachments, replying, mentioning users, or performing bulk operations. Define behavior when a participant leaves a team/project or loses access: historical access, retention, and export rules require explicit policy.

### Announcements

Support company-wide announcements, publishing permissions, read receipts, lists/counts of users who have and have not read an announcement, and notification delivery.

Define the eligible audience at publish time or dynamically at read time; this choice affects read-receipt interpretation when membership changes. Enforce one read record per announcement and user (or an equivalent unique invariant). Make read receipt creation idempotent. Distinguish "published", "delivered/notification attempted", and "read"; a notification delivery attempt does not prove the user read the announcement.

## 5. Notifications

Support database notifications and email or other channels when required, user notification preferences, read/unread state, event-triggered delivery, scheduled reminders, and deduplication where appropriate.

Potential triggers include assignment, due-date changes, blocked tasks, review requests/results, task completion, issue assignment/reopening, mentions, project/team membership changes, announcements, and reminders. Define recipient eligibility and preference rules for each event. Avoid notifying actors about their own changes unless desired. Queue delivery, handle provider failures, and separate notification intent from delivery outcome.

## 6. Performance and evaluation

Track objective indicators such as:

- Assigned, completed, overdue, and blocked tasks.
- Completion rates and deadline adherence.
- Reasons for delays and blocked work.
- Manager observations.
- Task complexity and quality context where approved.

Team indicators may include completion rate, completed/remaining/overdue/blocked work, actual versus planned progress, and project obstacles.

Critical rules:
- Never evaluate employees solely by raw task counts.
- Separate objective operational metrics from managerial assessment.
- Support management-approved criteria and configurable weights only after definitions are approved.
- Account for task complexity, quality, dependencies, reassignment, leave/availability, and changes in scope where policy requires.
- Preserve historical evaluations, criteria versions, evaluator, evaluation period, and approval history.
- Do not invent a final score formula, targets, thresholds, or employee ranking.
- Do not finalize or publish performance scoring until business owners approve the methodology and governance.

Treat evaluations as sensitive employee information. Enforce role- and scope-based access, audit reads/changes where required, and define retention and correction workflows.

## 7. Reporting and analytics

Potential report families:

### Executive / CEO
- Total, active, completed, and suspended projects.
- Overall portfolio progress and overdue projects.
- Completed and pending tasks.
- Team performance summaries.
- Employee performance summaries subject to approved access.
- Open and overdue bugs/issues.

### Project
- Progress and completion.
- Remaining, overdue, and blocked tasks.
- Planned versus actual progress.
- Phase/deliverable progress where phases are used.
- Risks, blockers, and obstacles.

### Team and employee
- Completion rates and deadline adherence.
- Workload and assignment history.
- Blocked and overdue work.
- Historical performance and approved evaluation records.

### Sprint
- Planned, completed, and remaining work.
- Velocity using an approved unit and consistent definition.

Every metric must have a documented definition, grain, time window, inclusion/exclusion rules, timezone, and treatment of reopened/cancelled/reassigned work. Centralize metric calculations so dashboards, exports, and API endpoints cannot disagree. Use date ranges and project/team/employee filters with authorization applied before aggregation. Do not expose records through aggregate reports if the caller is not permitted to see the underlying scope. Never invent statistics or progress percentages.

## 8. GitHub integration

GitHub is optional. Potential capabilities include repository connections, task-to-commit and issue-to-commit associations, Pull Request tracking, developer activity associations, webhook processing, and project-specific integration settings.

Define the mapping between platform projects/tasks/issues and GitHub repositories/issues/PRs. Do not assume commit activity is a complete measure of individual productivity. Verify webhook signatures, deduplicate provider event IDs, handle replay and out-of-order delivery, record integration status, and process expensive work asynchronously. Store credentials securely and scope them to the minimum required permissions. Core project management must continue when GitHub is disconnected or unavailable.

## 9. Audit history

Record important operations with:

- Actor (including system/service actor where appropriate).
- Action.
- Entity type and identifier.
- Timestamp.
- Previous and new values or a safe change summary.
- Relevant correlation/context identifiers.

Important events include project plan/manager/team changes, membership changes, task assignment and state transitions, dependency changes, review decisions, issue lifecycle changes, sprint closure, announcement publishing, evaluation creation/approval, and integration configuration changes.

Do not store secrets, tokens, passwords, or unnecessary personal data in audit payloads. Restrict audit access and modification. Define retention, export, and tamper-resistance requirements. Keep business audit events separate from application logs and provider delivery logs.

## 10. Exceptional scenarios requiring explicit rules

Before implementing the relevant workflow, decide and test:

- Overdue tasks and due-date changes.
- Blocked tasks and mandatory reasons.
- Dependency cycles and predecessor changes.
- Review rejection and failed testing.
- Completion approval and later reopening.
- Unresolved/reopened bugs.
- Project Manager replacement.
- Team or project membership removal.
- Project plan changes after execution begins.
- Sprint closure with incomplete work.
- User suspension, termination, or temporary access loss.
- Duplicate or delayed webhook delivery.
- Notification provider outage.
- Concurrent edits to the same task, project, or evaluation.

For each scenario, specify permitted actors, state/data changes, audit records, notification behavior, rollback/compensation, and access to historical records.

## 11. Suggested domain boundaries

Use these as candidate modules, not mandatory packages or namespaces:

- Identity & Access.
- Organization & Teams.
- Projects & Planning.
- Tasks & Workflow.
- Sprints.
- Issues.
- Conversations & Messaging.
- Announcements.
- Notifications.
- Performance & Evaluations.
- Reporting & Analytics.
- Integrations.
- Audit & Activity.

Keep ownership clear. For example, Task Workflow owns task state transitions; Notifications owns delivery preferences and dispatch; Reporting owns shared metric definitions; Integrations owns provider event normalization. Avoid circular dependencies and duplicated business rules.

## 12. Questions to resolve before schema or workflow implementation

Ask only the questions material to the requested scope, but do not silently guess:

1. Is there one organization or a real multi-organization requirement?
2. What are the exact role capabilities and resource scopes?
3. Can users belong to multiple teams and projects simultaneously?
4. Are project phases optional, and can tasks exist directly under a project?
5. What are the project status, priority, and progress definitions?
6. Who can assign/reassign tasks and approve completion?
7. What are the permitted task transitions and custom-status mapping rules?
8. What dependency types and cross-project rules are required?
9. Are estimates measured in points, hours, or not used?
10. What is the sprint close policy?
11. What is the issue severity/priority model and close/reopen authority?
12. What are message retention and membership-removal access rules?
13. How is the announcement audience determined?
14. Which notifications are mandatory, configurable, or suppressible?
15. What are the approved performance criteria and scoring governance?
16. What are the exact definitions of each report metric?
17. Which GitHub events and permissions are required?
18. What audit retention, privacy, and export rules apply?

Do not block unrelated work on answers that are not relevant to the requested feature. Record unresolved decisions as assumptions or explicit follow-up items.
