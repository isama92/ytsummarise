---
paths:
  - 'resources/css/**'
---

# Css

## Catppuccin theme: two token layers, and where naming a class in a comment matters
app.css has two layers. Raw palette (--ctp-*, Latte in :root, Frappe in .dark) sits deliberately OUTSIDE @theme so it generates no utilities; semantic shadcn tokens alias it. Never read --ctp-* from a component - go through the semantic token so a flavour swap stays a one file change.

The colour block must stay `@theme inline`. Plain @theme emits a second variable on :root that the utility reads, so the value resolves once at the root element and a .dark scope below it does nothing. It also matches what the shadcn CLI emits.

Naming a utility class in a **.tsx** comment emits a dead rule for it: those files are scanned for candidates and the extractor cannot tell code from comment. Describe classes there, do not spell them.

This does NOT apply to app.css itself. Verified by building with a class named only in a CSS comment and again with every comment stripped: the emitted selector set is identical either way, so the stylesheet is not part of the candidate scan. Comments here may name classes freely, which is why several of them do.

--input is a BORDER colour in shadcn v4, not a surface. Pointing it at a surface makes every form field's outline invisible.

Latte --primary is rosewater at 3.02:1, knowingly below WCAG AA; see the comment in the file before "fixing" it.
