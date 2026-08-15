---
paths:
  - '**'
  - '{Dockerfile,compose.yml,docker/**}'
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

## Triage a review before fixing any of it
When a code review comes back, do not start fixing. Verify the findings, then give an overview first: what is real, what is severe, what is mine, what is disputed, and what needs a decision rather than a patch. Then wait.

The reasons are the point of the rule. A finding that reverses an earlier decision is the user's call, not a patch. A finding that looks small can turn out to be the design (and vice versa), and a batch of fixes already applied is harder to argue with than a list. And the triage itself is the useful artefact - it says which findings were checked and found wrong, which no diff records.

Verifying first is not optional: reviews assert things that are stale, or true only under conditions that do not hold here. Check before repeating a claim, and say plainly which ones were checked.

## Ask before starting or stopping the dev server
The dev server belongs to whoever is at the keyboard. Do not run `composer run dev`, `php artisan serve`, `npm run dev` or a queue worker, and do not kill one that is already running: say what you need and ask them to start or stop it. The same goes for a spare-port server started "just for a check" - it is still a process left behind in somebody else's session.

If a browser check is wanted and nothing is listening, say so and wait. `ss -ltnp | grep -E ":(8000|5173)"` is the cheap way to find out.

Practical trap when a process does need finding: `pkill -f "artisan serve --port=8123"` kills the shell running it, because that shell's own command line contains the pattern. Exit code 144 with no output is what that looks like. Use `pgrep -af "port=812[3]"` to look, and ask the user to stop it.

## Container user is UID/GID build args plus compose `user:`, never a PUID entrypoint
The prod image runs as 1000:1000 (`ARG UID`/`ARG GID`, user `app`), not the base image's www-data (82:82, an Alpine id matching nothing on a host). Operators override at run time with compose's `user: "${PUID:-1000}:${PGID:-1000}"`, anchored across ytsummarise, horizon and scheduler.

Do not replace this with the linuxserver.io PUID/PGID entrypoint. That starts as root and drops with su-exec, which needs CAP_SETUID and CAP_SETGID - and every service here runs `cap_drop: ALL`. It would also only cover one container: horizon and scheduler set their own `entrypoint:` and never run app-entrypoint.sh, so two of the three writers to the shared storage volume would keep the wrong uid.

Writable paths are chowned UID:GID and then `chmod -R g+rwX`, because an empty named volume copies image ownership once at first mount and never again. That is what lets `user: "1500:1000"` write to a volume created at 1000:1000; `user: "1500:1500"` cannot, and needs the volume chowned by hand.
