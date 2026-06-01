---
name: explore
description: Explore and explain a module without writing code
---
Use subagents to explore the module or directory at: $ARGUMENTS

1. Read all files in the directory
2. Identify entry points and exports
3. Map internal data flow
4. List dependencies (imports from other modules)
5. List dependents (what imports from this module)
6. Summarize key business logic
7. Note any technical debt or TODOs found

Output a clear summary. Save the exploration summary to docs/explorations/$ARGUMENTS.md (create the docs/explorations/ folder if needed, and use the module name as filename). This way future sessions can reference it without re-exploring.

Do NOT write or modify any application code.
