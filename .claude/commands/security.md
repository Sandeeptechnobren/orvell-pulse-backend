---
name: security
description: Run a full security audit across the entire project
---
Run a security audit on this project. Use subagents for each section. Do NOT modify any code.

Run these 7 audits sequentially:

AUDIT 1 — AUTHENTICATION & SESSIONS:
- Where are auth tokens stored? (httpOnly cookies vs localStorage)
- Do sessions have expiry? What's the TTL?
- Do password reset links expire?
- Are admin routes protected with role checks?
- Is login rate-limited?

AUDIT 2 — API & NETWORK:
- Is rate limiting on all API endpoints?
- Is CORS configured with specific origins (not wildcard)?
- Do error responses leak stack traces or internal paths?
- Are webhook endpoints verifying signatures?
- Is pagination enforced on list endpoints?
- Does a health check endpoint exist?

AUDIT 3 — DATA & DATABASE:
- Are queries parameterized (no string concatenation)?
- Do ALL queries filter by tenant_id (multi-tenant)?
- Are indexes on frequently queried fields?
- Is connection pooling configured?
- Are passwords hashed (bcrypt/argon2)?

AUDIT 4 — SECRETS & ENVIRONMENT:
- Any API keys or secrets in frontend code?
- Is there env validation at startup?
- Does .env.example exist?
- Is .env in .gitignore?
- Run: git log --all --full-history -- "*.env" to check for accidentally committed secrets

AUDIT 5 — FRONTEND & CLIENT:
- CAPTCHA on public forms?
- Server-side validation on all form inputs?
- User content escaped before rendering (XSS)?
- File uploads validated (MIME, size, filename)?
- Assets served via CDN?

AUDIT 6 — INFRASTRUCTURE:
- Structured logging in production?
- Emails/notifications sent via queue (not sync)?
- Strict type checking enabled?
- Run: npm audit (or equivalent) for dependency vulnerabilities
- HTTPS enforced?
- Search for console.log, logger.info, logger.debug that output user objects, req.body, or auth tokens. Do any log statements capture PII (emails, passwords, payment data, IP addresses)?

AUDIT 7 — SERVER ACCESS:
- Is the Claude SSH user NOT root? (should be dedicated user like claude-server)
- Is Claude's SSH key separate from developer personal keys?
- Does /tmp/deploy.lock prevent concurrent deployments?
- Does circuit breaker limit auto-fix to 3 attempts?
- Do destructive DB operations (DROP, TRUNCATE, DELETE without WHERE) require APPROVED?
- Are all deployments logged to docs/DEPLOY_LOG.md?
- Does test data use isolated TEST_TENANT_ID?
- Can Claude create SSH users or modify SSH config? (should NOT)
- Are SSH keys revoked when team member leaves?
- Does server firewall allow SSH only from known IPs?

Format each finding as:
✅ PASS — [item] (file: [path])
❌ FAIL — [item] (risk: [why], fix: [what to do])
⚠ MANUAL CHECK — [item] (reason)

List FAIL items first in each section. End with a summary count of PASS/FAIL/MANUAL CHECK.
