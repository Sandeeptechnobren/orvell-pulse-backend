---
name: rollback
description: Rollback a failed deployment to last known good state
---
STEP 0 — SSH PRE-CHECK:
Run: ssh [host-alias] "echo connected"
If fails → STOP. Report: "SSH not configured. See docs/SSH_CONFIG.md."

EMERGENCY ROLLBACK PROCEDURE:

1. Check current state:
   - Is the app running? (pm2 ls / docker ps)
   - What commit is deployed? (git log --oneline -1 on server)
   - Are there pending database migrations that already ran?

2. Identify rollback target:
   - Read docs/DEPLOY_LOG.md — find the last SUCCESS entry
   - Confirm the commit hash of the last known good deployment
   - Show: "Rolling back to [commit hash] from [date]. Proceed? Type APPROVED."

3. Wait for APPROVED before proceeding.

4. Application rollback:
   - git checkout [last-good-commit] on server
   - Install dependencies for that commit
   - Rebuild the project
   - Restart application (pm2 restart / docker-compose up -d)

5. Database rollback (if migrations ran):
   - Check if the migration has a down/rollback command
   - If yes: show the rollback SQL and wait for APPROVED before running
   - If no: restore from the backup that /deploy created before migration
   - Verify data integrity after restore

6. Verify rollback:
   - Hit health check endpoint
   - Run /test-live to verify core functionality
   - Check error logs for new errors

7. Log the rollback:
   - Add entry to docs/DEPLOY_LOG.md: date, "ROLLBACK", from-commit, to-commit, reason
   - Remove /tmp/deploy.lock if it exists

8. Report: what was rolled back, current server state, what caused the failure, recommended next steps.

CRITICAL RULES:
- NEVER rollback without APPROVED
- NEVER skip the database check — a code rollback with a forward-migrated DB can corrupt data
- If unsure about database state, STOP and report — let a human decide
