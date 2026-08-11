---
paths:
  - '**'
---

# General

## Run composer refactor, not refactor:check
Rector is meant to fix, not to gate. Run `composer refactor` and let it rewrite the files; the autofix workflow does the same thing on push, so a dry run only tells you what is about to be changed for you anyway.

`composer refactor:check` (rector --dry-run) exits non-zero on any pending change, which turns a self-healing step into a failure to hand-patch. Reserve it for CI.

Note its JSON output lists files under a trailing "changed_files" key even when nothing changed; `totals.changed_files` is the number that means anything.
