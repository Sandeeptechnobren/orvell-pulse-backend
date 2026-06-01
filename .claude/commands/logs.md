---
name: logs
description: Tail recent server logs
---
STEP 0 — SSH PRE-CHECK:
Run: ssh [host-alias] "echo connected"
If fails → STOP. Report: "SSH not configured. See docs/SSH_CONFIG.md."

1. SSH to server
2. Show last 200 lines of application logs
3. Highlight ERROR and WARN lines
4. If errors found, group by type and show count
5. Show last 50 lines of nginx/access logs if relevant
6. Suggest fixes for any recurring errors

Read-only. Does not modify anything.
