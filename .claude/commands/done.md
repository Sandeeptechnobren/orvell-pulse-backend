---
name: done
description: Wrap up current task — update changelog, tasks, lessons, and docs
---
Wrap up the current task: $ARGUMENTS

1. Move the task from "In Progress" to "Completed" in tasks/todo.md with today's date
2. Add an entry to CHANGELOG.md under ## [Unreleased] with correct category (Added/Changed/Fixed/Removed)
3. If any mistakes were made during this task, add lessons to tasks/lessons.md
4. Auto-update documentation if this task changed the project structure:
   - If new API endpoints were added → update the API Surface section in docs/ARCHITECTURE.md
   - If new database tables/columns were added → update the Database Schema section in docs/ARCHITECTURE.md
   - If new third-party integrations were added → update the Third-Party Integrations section in docs/ARCHITECTURE.md
   - If a new code pattern was introduced (new way of doing something) → add it to docs/PATTERNS.md with a real example
   - If files in a module were significantly changed → update that module's CLAUDE.md (key files, data flow, dependencies)
   - If new environment variables were added → update docs/ACCESS.md and docs/ARCHITECTURE.md
   - If none of the above apply, skip this step
5. Run a final review of all uncommitted changes
6. Suggest a commit message following conventional commits format
7. Suggest the PR description

Update task/changelog/lesson/doc files as needed. Do NOT modify application code.
