---
name: status
description: Daily team status digest
---
STEP 0 — SSH PRE-CHECK:
Run: ssh [host-alias] "echo connected"
Skip server section if SSH fails (note it in report).

1. git log --all --since="24 hours ago" — who committed what
2. tasks/todo.md — what's in progress
3. CHANGELOG.md — what was completed
4. SSH to server: health check, resources, recent error logs

Format as:
## [Date] Daily Status
### Commits (last 24h)
### In Progress
### Server Health
- [status + resources + error summary]
### Issues Needing Attention
