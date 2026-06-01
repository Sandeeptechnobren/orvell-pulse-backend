---
name: deploy
description: Deploy to server with autonomous execution
---
STEP 0 — SSH PRE-CHECK:
Run: ssh [host-alias] "echo connected"
If fails → STOP. Report: "SSH not configured. See docs/SSH_CONFIG.md."
Check for /tmp/deploy.lock on server. If exists → STOP: "Another deployment is in progress."

1. Create /tmp/deploy.lock on server
2. git pull origin [current-branch]
3. Install dependencies if package files changed
4. Run database migrations if any pending — BACKUP FIRST. Record the pre-migration commit hash in docs/DEPLOY_LOG.md
5. Build the project
6. Restart application (pm2 restart / docker-compose up -d)
7. Wait 10 seconds, hit health check endpoint
8. If health check passes → run /test-live
9. If tests pass → report SUCCESS, log to docs/DEPLOY_LOG.md (include: date, branch, commit hash, server, result, pre-deploy commit for rollback reference), remove /tmp/deploy.lock
10. If tests fail → read error logs, diagnose root cause, fix locally, commit, push, repeat from step 1
11. CIRCUIT BREAKER: After 3 failed cycles, STOP and report what failed and current server state. Remove /tmp/deploy.lock.
For destructive DB ops (DROP, TRUNCATE, DELETE without WHERE) → show command and wait for APPROVED.
NEVER run two deployments simultaneously.
