---
name: monitor
description: Check server health and resources
---
STEP 0 — SSH PRE-CHECK:
Run: ssh [host-alias] "echo connected"
If fails → STOP. Report: "SSH not configured. See docs/SSH_CONFIG.md."

SSH to server and check:
1. Application status (pm2 ls / docker ps)
2. Health check endpoint response
3. Disk usage (df -h) — flag if >80%
4. Memory usage (free -m) — flag if >80%
5. CPU load (uptime)
6. Database connections — flag if near pool limit
7. Redis memory usage (if applicable)
8. SSL certificate expiry — flag if <30 days
9. Last 50 lines of error logs
10. Uptime

Non-destructive. Read-only. Run anytime.
