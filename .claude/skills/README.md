
# Enterprise Laravel Architect

Version: 2.0.0

A professional Claude Skill for Laravel and PHP backend architecture, implementation, security, testing, and production-readiness reviews.

## Features

- Laravel architecture and engineering.
- Modular monolith design.
- REST API engineering.
- Database design and migrations.
- Authentication and authorization.
- Multi-tenant SaaS security.
- Queues, jobs, events, and integrations.
- Secure file handling.
- Performance and reliability.
- Automated testing.
- Production-readiness reviews.
- Proportional engineering workflows.

## Directory Structure

enterprise-laravel-architect/
├── SKILL.md
├── README.md
└── references/
    ├── backend-quality-gates.md
    ├── security-guidelines.md
    ├── multi-tenancy.md
    ├── project-management-domain.md
    └── examples-and-evals.md

## Installation: Claude Code

### Project-level installation

Copy the skill directory into:

.claude/skills/enterprise-laravel-architect/

### Personal installation

Copy the skill directory into:

~/.claude/skills/enterprise-laravel-architect/

### Verification

Open Claude Code and run:

/skills

Invoke the skill using:

/enterprise-laravel-architect

The skill can also be selected automatically when Claude determines that its description matches the task.

## Installation: Claude.ai

1. Keep the directory structure intact.
2. Create a ZIP containing the enterprise-laravel-architect directory.
3. Open Claude.
4. Go to Customize > Skills.
5. Select Create skill.
6. Choose Upload a skill.
7. Upload the ZIP.
8. Enable the skill.

Follow Claude's current account and feature requirements.

## Engineering Principles

- Inspect before modifying.
- Preserve existing architecture.
- Prefer simplicity over unnecessary abstraction.
- Secure every server-side operation.
- Enforce tenant isolation.
- Protect production data.
- Test successful and denied behavior.
- Verify actual execution results.
- Report limitations honestly.

## Security Notice

This skill provides engineering instructions and review procedures.

It is not a security boundary, sandbox, permission system, or substitute for human review.

Use Claude Code permissions, environment isolation, backups, and deployment controls independently.

## Version History

### 2.0.0

- Improved architecture and proportional task handling.
- Flexible backend/full-stack scope.
- Expanded security guidelines.
- Added multi-tenancy architecture and testing.
- Added destructive-operation approval rules.
- Improved quality gates.
- Added practical evaluation scenarios.
- Improved progressive reference loading.
- Standardized skill naming and description.
