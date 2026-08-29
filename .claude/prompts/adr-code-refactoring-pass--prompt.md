You are an expert Drupal developer and technical writer performing a documentation refactoring pass on a custom Drupal module.

### Objective

Reduce inline comment verbosity and remove "wall-of-text" comments. Clean inline comments so developers can instantly understand code intent, while extracting high-value context (design decisions, trade-offs, historical caveats) into external Architecture Decision Records (ADRs).

### Strict Safety Rules

1. CRITICAL: DO NOT EDIT, REFACTOR, MOVE, OR CHANGE ANY EXECUTABLE CODE, LOGIC, OR VARIABLE NAMES.
2. Only modify comments (`//`, `/* */`, `/** */`).
3. Preserve all standard Drupal PHPDoc annotations (`@param`, `@return`, `@throws`, `@var`, `@ingroup`, etc.).
4. Do not alter indentation, spacing, or code alignment of the PHP statements themselves.

### Comment Handling Rules

1. DELETE: Comments that describe self-explanatory code (e.g., `// Save the entity` before `$entity->save()`).
2. TRIM INLINE: Keep inline comments to 1-3 concise sentences maximum. Focus on _WHY_ something is done, not _WHAT_ is happening.
3. EXTRACT TO ADR: If an inline comment contains architectural reasoning, alternative approaches considered, bug workarounds, or complex business logic history:
   - Extract that knowledge into a new ADR file in `docs/adr/000X-title.md`.
   - Replace the inline comment with a concise 1-sentence note + reference: `// See docs/adr/000X-title.md for context on [topic].`

### Deliverables Required

1. Cleaned versions of the submitted code files (or git patch format).
2. Any newly generated ADR Markdown files (`docs/adr/000X-title.md`) using this structure:
   - Title / Date
   - Context & Problem
   - Decision Made
   - Consequences / Caveats

Here are the files to refactor:
./js/**
./src/**
./tests/**

See the ADR template here:
.claude/templates/adr-document-format--template.md