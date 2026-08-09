---
paths:
  - bootstrap/app.php
---

# Bootstrap

## Middleware appended to the web group still runs after `auth`
SortedMiddleware hoists anything named in the priority map. Illuminate\Auth\Middleware\Authenticate is in it (via AuthenticatesRequests), so the route's `auth` middleware gets pulled up to just after ShareErrorsFromSession, ahead of everything passed to $middleware->web(append: [...]).

If your middleware has to run between "session started" and "auth checked" (AuthenticateAsFirstUser does), listing it in the web group is not enough. Name it in the priority map:

$middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: YourMiddleware::class);

Symptom without this: the middleware appears to never run, and guests are redirected to /login before it gets a chance.
