---
name: review
description: Review recent changes for bugs and style issues
---
Review all uncommitted changes in the current git working tree:

1. Check each modified file for bugs, logic errors, and edge cases
2. Verify changes follow conventions in CLAUDE.md and docs/PATTERNS.md
3. Check for missing error handling
4. Check for missing or broken tests
5. Check for security issues:
   - SQL injection (string concatenation in queries)
   - XSS (unescaped user input in HTML/JSX)
   - Exposed secrets (API keys, tokens, passwords in code)
   - Missing tenant_id filter in database queries (multi-tenant)
   - Missing webhook signature verification
   - Missing rate limiting on new endpoints
   - Missing server-side validation on new form inputs
   - Auth tokens stored in localStorage instead of httpOnly cookies
   - Error responses leaking stack traces or internal paths
6. Check for performance concerns
7. Verify naming conventions
8. Check for stale documentation:
   - Do these changes add new API endpoints not listed in docs/ARCHITECTURE.md?
   - Do these changes introduce a new code pattern not in docs/PATTERNS.md?
   - Do these changes add new modules, tables, or integrations not documented?
   - Were any files in a module significantly changed while its CLAUDE.md is outdated?
   If any doc is stale, add to the issues list: "DOCS STALE — [which doc] needs update for [what changed]"
9. BLAST RADIUS DAMAGE CHECK:
   CRITICAL (auto-revert unless explicitly requested):
   - .claude/skills/ modified → shared team skills, revert immediately
   - .git/ touched → never modify, revert immediately
   - Lock files modified (package-lock.json, bun.lock, yarn.lock) → only valid if dependencies were intentionally changed

   WARNING (flag for human review):
   - Infrastructure files modified (docker-compose.yml, Dockerfile, nginx.conf, .github/workflows/*, Procfile, ecosystem.config.js) → deployment-affecting changes need explicit approval
   - CI/CD pipeline files modified → can break all future deployments
   - Shared config files modified (.env.example, tsconfig.json, next.config.*, vite.config.*, webpack.config.*) → affects entire project
   - Files modified OUTSIDE the module scope of the current task → check if this was intentional or accidental scope creep
   - Production-only files modified (server configs, cron jobs, systemd units) → verify these changes were part of the plan
   - Database migration files modified (not created, MODIFIED) → modifying existing migrations breaks deployed environments

   If any CRITICAL found, add revert instruction. If any WARNING found, list them with "NEEDS HUMAN REVIEW" tag.

List issues by severity: Critical, Warning, Suggestion, Docs Stale.
