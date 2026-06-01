---
name: generate-manual
description: Generate a role-specific user manual
---
Generate a user manual for the role: $ARGUMENTS

1. Read docs/ARCHITECTURE.md to understand the system
2. Search the codebase for all routes, pages, and features accessible to this role
3. For each accessible feature, document what it does, how to access it, step-by-step instructions, and common mistakes
4. Organize into: Getting Started, Core Features, Settings & Configuration, Troubleshooting, FAQ
5. Use simple language. Assume the reader is NOT technical.
6. Add [SCREENSHOT: ...] placeholders throughout.

Save as docs/USER_MANUAL_[ROLE].md
Do NOT modify any application code.
