---
paths:
  - 'resources/js/pages/auth/**'
---

# Pages Auth

## The sign-in control must be a plain anchor, never an Inertia Link
/auth/redirect answers with a cross-origin 302 to Authentik. An Inertia visit is an XHR and cannot follow that: it either trips CORS or receives HTML it refuses to parse. Use <Button asChild><a href={redirect.url()}> so the browser does a full page navigation.

The same applies to any future endpoint that hands off to an external provider.
