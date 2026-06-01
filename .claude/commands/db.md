---
name: db
description: Database operations with safety guardrails
---
STEP 0 — SSH PRE-CHECK:
Run: ssh [host-alias] "echo connected"
If fails → STOP. Report: "SSH not configured. See docs/SSH_CONFIG.md."

Run database operation: $ARGUMENTS

SAFETY PROTOCOL:

1. Identify the target database — show me: database name, host

For MIGRATIONS:
- Show the migration SQL/commands BEFORE running
- Backup the database BEFORE running (confirm backup succeeded)
- After migration: verify table structure matches expectations
- Report: tables affected, rows affected, time taken

For QUERIES (SELECT/read-only):
- Run directly and show results
- For large result sets, add LIMIT 100

For DATA MODIFICATIONS (INSERT/UPDATE/DELETE):
- Show me the exact query and estimated rows affected BEFORE running
- Run directly after showing the query
- NEVER run DELETE or UPDATE without a WHERE clause

For DESTRUCTIVE OPERATIONS (DROP/TRUNCATE):
- Backup the database FIRST
- Show the exact command and wait for APPROVED before executing
- NEVER proceed without explicit APPROVED

For SEEDING:
- Show what data will be inserted
- Run and report results

CRITICAL RULES:
- ALWAYS backup before DROP or TRUNCATE
- If anything fails, STOP and report — do not attempt to fix automatically
