# Agent Instructions

Read this file before making changes in this repository.

## Project Workflow

- Do not run `npm run build`, `npm.cmd run build`, or other production frontend builds unless the user explicitly asks for a build or approves it for the current task.
- Prefer lighter checks for frontend edits, such as JSX parsing, targeted lint/type checks if available, or focused tests.
- Use Laravel Boost MCP before Laravel/PHP/API/database work. Start with `application_info`, and use `search_docs` for Laravel ecosystem documentation when documentation is needed.
- Use the Ant Design MCP before Ant Design UI work. Check component docs/API/tokens through the MCP instead of guessing component behavior.
- Keep edits scoped to the user's request and preserve unrelated uncommitted changes.

