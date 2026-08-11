---
paths:
  - '**'
---

# General

## Run composer refactor, not refactor:check
Rector is meant to fix, not to gate. Run `composer refactor` and let it rewrite the files; the autofix workflow does the same thing on push, so a dry run only tells you what is about to be changed for you anyway.

`composer refactor:check` (rector --dry-run) exits non-zero on any pending change, which turns a self-healing step into a failure to hand-patch. Reserve it for CI.

Note its JSON output lists files under a trailing "changed_files" key even when nothing changed; `totals.changed_files` is the number that means anything.

## Do not check CI unless asked
CI belongs to whoever opened the PR. After pushing, do not poll `gh pr checks` / `gh run list` / `--watch` and do not wait on a run to finish; say the branch is pushed and stop there.

Run the same gates locally instead (`composer ci:check`, then `composer refactor`), which is what the workflows run anyway, so a green local run is the useful signal. Only look at CI when explicitly asked to.

## Regenerate wayfinder with --with-form
vite.config.ts configures the wayfinder plugin with formVariants: true, so anything generated during a build or dev run carries the .form() helpers the pages spread into <Form>. Running `php artisan wayfinder:generate` by hand does not read that config and silently omits them, and the resulting type error looks like the controller action is missing rather than the flag.

Regenerate by hand with `php artisan wayfinder:generate --with-form`, or just let vite do it.

(resources/js/actions, resources/js/routes and resources/js/wayfinder are all gitignored and generated, which is why this rule is filed here rather than against those paths: a glob over them could never match a file anyone is working on.)
