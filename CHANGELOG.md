# Changelog

All notable changes to this project are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) · Versioning: [SemVer](https://semver.org/).

## [Unreleased]

### Added
- AI-assisted development context layer:
  - Root and per-module `CLAUDE.md` files across the backend and frontend.
  - `docs/CODEBASE_AUDIT.md`, `docs/ARCHITECTURE.md`, `docs/PATTERNS.md`, `docs/ACCESS.md`, `docs/DEPLOY_LOG.md`, `docs/SSH_CONFIG.md`.
  - `tasks/todo.md`, `tasks/lessons.md`.
  - Custom slash commands: `/explore`, `/plan`, `/review`, `/done`, `/pickup`, `/test`, `/security`, `/deploy`, `/rollback`, `/db`, `/monitor`, `/logs`, `/status`, `/generate-manual`.
  - Testing commands: `/test-tenant`, `/test-api`, `/test-business`, `/test-integration`, `/test-security`, `/test-e2e`, `/test-live`, `/fix-e2e`, `/generate-qa-sheet`, `/fix-qa-bug`.
  - `doc-updater` subagent and `.claude/settings.json` shared tool permissions.
  - Impeccable frontend design skill (`.claude/skills/impeccable/`).
  - `.claudeignore` for context-window protection.
