# ytsummarise

A simple app where you paste a YouTube video and it will give you a summary.

## Summarising

Three steps, and only the last two involve a model.

1. **The transcript.** `yt-dlp` is asked to describe the video, and the caption track it
   names is fetched over http. This is the half no AI package does for you, and it is the
   step that decides whether a video can be summarised at all: no captions, no summary.
2. **The ideas, then the summary.** One pass pulls out what the video actually says; a
   second shapes that into a headline, ten points and five takeaways. Two passes rather
   than one because a transcript is an hour of speech with the shape taken out, and asking
   one prompt to both find what matters *and* phrase it well summarises the opening five
   minutes.
3. **The translation**, for a video that was not in English. The finished summary is
   translated rather than the transcript, so the judgements are made against what was
   actually said. Both versions are kept and shown, the original above.

### Setting it up

`yt-dlp` has to be on `PATH`. The published image ships it, so this is a local development
step; set `YT_DLP_BINARY` to an absolute path if yours lives somewhere unusual.

```sh
yt-dlp --version
```

Keep it current. It is the one dependency here that breaks on somebody else's schedule —
YouTube changes its player and yt-dlp follows, so a version left alone for months eventually
stops finding caption tracks and every summary fails as "unavailable". The image picks up
whatever Alpine has each time it is rebuilt.

Then point the application at a model. Any provider in `config/ai.php` that generates text
will do; `cohere`, `jina` and `voyageai` do embeddings and reranking and `eleven` does
audio, so naming one of those fails at the first prompt rather than at startup.

```ini
AI_PROVIDER=openai-compatible
OPENAI_COMPATIBLE_URL=https://your-openwebui.example.com/api
OPENAI_COMPATIBLE_API_KEY=sk-…
OPENAI_COMPATIBLE_MODEL=gemma4:e4b
```

Two things that are easy to get wrong here:

- **The url is the api root, not the host.** OpenWebUI serves its OpenAI-compatible
  endpoints under `/api`, so the value ends there. Without it every prompt gets a 404.
- **The model is required.** Hosted providers know their own model names and this driver
  cannot, so leaving it empty fails with `requires a default text model`.

The `openai-compatible` driver posts to `{url}/chat/completions` and never touches the
Responses API, which belongs to the separate `openai` driver — so an endpoint with the
Responses API disabled works here.

Check the lot before summarising anything:

```sh
php artisan ai:check
```

It reports the resolved provider, url and model, lists what the endpoint offers, sends one
tiny prompt, and then proves structured output works. That last check is the one worth
having: both summarising agents ask for a json schema, and whether an OpenAI-compatible
gateway passes that through to the model underneath is the likeliest thing to be wrong
about a self-hosted setup that otherwise answers perfectly well. The key is never printed.

Summaries are produced by a queued batch of five steps, so a worker has to be running.
Horizon's Batches tab is where a video's progress is: one batch per attempt, named for
the video, counting one to five as the steps finish.

`composer dev` starts one for you — its `queue` tab is Horizon, which works every queue this application
has. On its own:

```sh
php artisan horizon
```

Queues live in Redis, so there has to be one of those too. Locally that is a throwaway
container next to the Postgres one; both are documented at the top of `compose.yml`.

```sh
docker run --rm -d --name ytsummarise-redis -p 127.0.0.1:6379:6379 redis:8-alpine
```

Redis is not only the queue — the cache and the sessions are on it as well, so without it
the application fails on the first request rather than just failing to summarise.

### Watching the queue

`/horizon` is the dashboard: what is queued, what is running, what failed and why, and how
long things waited. It is restricted to **the first user in the database** — user id 1,
whoever set the application up. Anyone else gets a 403.

Two things about that worth knowing before you go looking for a bug:

- In `APP_ENV=local` Horizon lets everyone through regardless, which is its own behaviour
  and not something configured here. The gate only ever applies to a deployed application.
- With `AUTH_ENABLED=false` it is open to anyone who can reach the site, because that
  setting signs every visitor in as user 1 by design. That mode is only safe behind a
  private network to begin with, and the dashboard is inside the same "anyone".

Queue names are in `app/Enums/Queue.php` and who works them is `config/horizon.php`:
`high`, `default` and `low` share a supervisor and are worked in that order, and
`summaries` has its own, pinned to one process because a summary is a long paid model call
and two at once is two of them competing for the same machine.

### Suggested context window

There is no chunking and no length cap: the whole transcript goes into one prompt. So the
context window is what decides the longest video this can summarise, and a window too small
for one means the provider either refuses the prompt or quietly truncates it — and a
summary of the first half of a video looks exactly like a summary.

Speech runs at roughly **200 tokens per minute** of video. Measured rather than guessed: a
64-minute lecture transcribes to 46,718 characters, about 11,700 tokens, or 183 per minute.
Add ~1,500 for the instructions and the ideas the first pass writes out.

| Context window | Longest video |
| --- | --- |
| 8k | about half an hour |
| 16k | about an hour |
| 32k | about two and a half hours |
| 64k | about five hours |
| 128k and up | anything you are likely to paste |

**32k is the sensible minimum and 64k is comfortable.** Below 16k this is only useful for
short videos. The other passes are nowhere near the limit — they read the ideas rather than
the transcript, which is a thousand tokens or so — so the first pass is the only one worth
sizing for.

### Retention

Summaries are deleted after **seven days**, along with the transcripts stored beside them:

```ini
SUMMARY_RETENTION_DAYS=7
```

`summaries:prune` does it, scheduled daily at 03:00, and it is not a maintenance convenience.
A transcript is a recording of somebody speaking, written down and kept by us, and nobody
asked them; keeping it indefinitely because deleting it was never anybody's job is the
storage limitation the AVG is about. So the window is short by default and it runs on a
schedule rather than waiting to be remembered.

The window is measured from when a video was **last asked for**, not from when its row was
created — so it deletes what nobody has wanted in a week, and asking for a video again
renews it. Deleting a summary is less destructive than it sounds: asking for the same video
again produces a new one, at the cost of making it again.

`SUMMARY_RETENTION_DAYS=0` switches it off and keeps everything. The command says so on
every run rather than silently deleting nothing, since a nightly job that removes nothing
looks identical to one that is working.

Transcripts are stored, so you can measure your own corpus rather than trusting the table:

```sh
php artisan tinker --execute 'App\Models\Summary::query()
    ->whereNotNull("transcript")
    ->get(["video_id", "transcript"])
    ->each(fn ($s) => print($s->video_id." ~".intdiv(strlen($s->transcript), 4)." tokens\n"));'
```

A local model is often slower than a hosted one rather than smaller, and the AI SDK's own
default timeout is 60 seconds. `SUMMARY_MODEL_TIMEOUT` is the budget for one prompt and
defaults to 600.

## Deploying

`compose.yml` is a template for a production stack: three containers off the same GHCR
image (the web server, Horizon, the scheduler) and a Redis beside them, behind an existing
Traefik. It builds nothing and needs no checkout, so a host needs only that file and a
`.env` beside it. The file documents its own knobs, the router rule and the two external
networks among them.

Postgres is deliberately not in there and Redis deliberately is: the database lives in its
own stack and is shared, while Redis holds nothing but this application's queue, sessions
and cache. It keeps an appendonly volume, so a restart does not sign everybody out or drop
what was queued.

### What compose sets for you

`APP_ENV`, `APP_DEBUG`, `DB_CONNECTION`, `REDIS_HOST` and `LOG_CHANNEL` are declared under
`environment:` on every service, and compose resolves that ahead of `env_file:`. So a
`.env` copied straight from `.env.example`, still saying `APP_ENV=local` and
`APP_DEBUG=true`, runs as production with debug off regardless. Pinning them beats
documenting them: a stack that can be deployed into debug mode by an editing slip leaks
stack traces and environment values to the browser.

`REDIS_HOST` is there for a duller reason — `.env.example` says `127.0.0.1`, which is
right on a development machine and points at nothing inside a container. The stack's own
Redis is reachable as `redis`.

### What you have to set

| Value | Why |
| --- | --- |
| `APP_KEY` | Empty in `.env.example`, and nothing fills it in for you. See below. |
| `APP_URL` | Sessions and every generated link. `AUTHENTIK_REDIRECT_URI` interpolates from it, so leaving it at `localhost` breaks signing in too. |
| `DB_*` | `DB_HOST` is the Postgres container's service name on the shared `database` network. |
| `AUTHENTIK_*` | Unless `AUTH_ENABLED=false`. |
| `AI_PROVIDER`, `OPENAI_COMPATIBLE_*` | The provider, url and model from [Setting it up](#setting-it-up). |

Everything else has a working default. `YOUTUBE_API_KEY` is genuinely optional, and the
`SUMMARY_*` budgets are tuned for a local model already.

### APP_KEY

Generate one on the host and keep it. The `key:generate` in the Dockerfile belongs to
the asset build, and its throwaway `.env` never leaves that stage by design: a key baked
into a public image is not a secret, and one made fresh on every container start would
sign everybody out on every deploy.

```sh
docker run --rm --entrypoint php ghcr.io/isama92/ytsummarise:latest \
    artisan key:generate --show
```

`--show` prints and writes nothing, so this is safe to run against the production image.
Without the image pulled yet, `echo "base64:$(openssl rand -base64 32)"` produces the
same thing: the cipher is `AES-256-CBC`, so the key is 32 random bytes, base64 encoded.

Paste the `base64:…` value into `.env` and leave it there. Rotating it invalidates every
session and every signed url; `config/app.php` has `previous_keys` for rotating without
that.

**An empty key does not fail loudly on its own**, which is why the entrypoint refuses to
start without one. Nothing in the boot sequence touches the encrypter: all four caches
build, `migrate --force` runs, the server starts listening. Laravel registers `/up` with
no middleware group, so the healthcheck answers 200 and the container is marked healthy,
which then releases the queue and the scheduler through their `depends_on`. Only real
requests go through the `web` group, reach `EncryptCookies` and throw. So the unguarded
symptom is three healthy containers, a satisfied Traefik, and a 500 for every visitor
with `APP_DEBUG=false` hiding the reason.

### Which user it runs as

The image runs unprivileged as **1000:1000**, and `compose.yml` reads `PUID` and `PGID`
from the `.env` beside it:

```sh
PGID=100
```

**The uid owns everything writable, and the gid is free.** `1000:100`, `1000:1000`,
anything — set `PGID` to whatever your host wants and nothing else has to change. The
image chowns every path the application writes to, so the owner bits decide and the group
is never consulted. There is deliberately no group-writable fallback: it would cover the
directories but not the cover images, which are written `0600`, and a rule with an
exception is worse than one without.

`PUID` is the half that is not free, because a storage volume that already exists still
belongs to whoever created it. Nothing in an image can change that, so the entrypoint
tests for it and stops with the chown command rather than letting it become a puzzle.

That is docker's own `user:` key rather than the root-then-drop entrypoint linuxserver.io
images use, and the difference is not cosmetic. Dropping privileges with `su-exec` needs
`CAP_SETUID` and `CAP_SETGID`, and every service in this stack runs with `cap_drop: ALL`;
`user:` is applied by the kernel at exec and costs no capability and no root phase. It
also covers all three containers, which an entrypoint could not — `horizon` and
`scheduler` set their own `entrypoint:` and never run `app-entrypoint.sh`.

1000 rather than the base image's `www-data`, which is 82:82 on Alpine and corresponds to
nothing on a host. Being able to hand the container a directory your own user already
owns is the whole point of the move.

#### Upgrading from an older image

**Read this before pulling.** Every version before this one ran as `82:82`, and a
`storage` volume created then still says so. The new container cannot write to it, and it
will refuse to start until you fix that:

```sh
docker compose down
docker volume ls                                  # <name> is the directory this ran from
docker run --rm -v <name>_storage:/s alpine chown -R 1000:1000 /s
docker compose pull && docker compose up -d
```

Use `$PUID` in place of `1000` if you set one. A bind mount is chowned on the host
instead. A fresh stack needs none of this — an empty named volume takes the image's
ownership at first mount.

Skip it and the failure is loud but not where you would look: the entrypoint runs
`view:cache` long before frankenphp listens, so the **web** container restart-loops, `/up`
never answers, and `horizon` and `scheduler` stay blocked on `depends_on:
service_healthy`. The whole stack stays down, and the guard exists so the logs say why.

#### A different uid

Rebuild rather than override, so the image and the volume agree from the start:

```sh
docker build --target prod --build-arg UID=1500 -t ytsummarise .
```

`--build-arg GID=` exists too, but it only sets the default that `PGID` overrides anyway.

## Test coverage

`phpunit.xml` scopes coverage to `app/`, and the suite is kept at 100%:

```sh
php artisan test --coverage --min=100
```

CI does not check this. `composer ci:check` runs the suite without coverage and the
workflow installs PHP with `coverage: none`, so the 100% is a convention this README
records rather than a gate. Run it yourself before opening a pull request.

That command needs a coverage driver. If your PHP has neither pcov nor Xdebug, or is a
static build you cannot add extensions to, measure it in a throwaway container instead:

```sh
docker build -t ytsummarise-coverage - <<'EOF'
FROM php:8.5-cli
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions mbstring pdo_sqlite pcov
EOF

docker run --rm -v "$PWD":/app -w /app ytsummarise-coverage \
    php vendor/bin/pest --coverage --min=100
```

`vendor/` is pure PHP and the tests run against in-memory SQLite, so the mounted
checkout works as it is; only the extensions differ. Nothing is written back into the
repository, and `docker rmi ytsummarise-coverage` removes the image again.

## TODO
- add progress (x/5, reading batch state) instead of just saying "processing"
- check coverage
- remove solo user with creation on first access
- real admin users, rather than horizon being restricted to user id 1
- associate summaries with users
- add list of requested videos with the status
- notify by email/ntfy when ready (if user wants to, toggle in profile)

