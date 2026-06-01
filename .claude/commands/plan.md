---
name: plan
description: Create a detailed implementation plan before coding
---
Create a detailed implementation plan for: $ARGUMENTS

1. Read tasks/lessons.md first — avoid any documented pitfalls
2. Identify all files that need to be created or modified
3. For each file, describe what changes are needed and why
4. Check docs/PATTERNS.md and follow existing patterns
5. Identify potential breaking changes or side effects
6. List edge cases to handle
7. Define what tests should be written or updated
8. Estimate complexity (simple/medium/complex)
9. BROAD OPERATION CHECK: If this task involves rename/refactor touching 5+ files, run grep first to find all occurrences. Include the EXACT file list in the plan. Flag any files in protected directories (.claude/skills/, node_modules/, .git/, dist/, build/). The plan is INCOMPLETE without this list.

Save the plan to tasks/todo.md under "## In Progress" with today's date.
Present the plan in numbered steps. Do NOT write any code.

After presenting the plan, ask: "Type APPROVED to proceed, or describe what to change."
Only begin implementation if the user responds with the exact word APPROVED.
