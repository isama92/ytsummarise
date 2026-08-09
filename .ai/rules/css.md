---
paths:
  - 'resources/css/**'
---

# Css

## Catppuccin theme: two token layers, and never name a class in a comment
app.css has two layers. Raw palette (--ctp-*, Latte in :root, Frappe in .dark) sits deliberately OUTSIDE @theme so it generates no utilities; semantic shadcn tokens alias it. Never read --ctp-* from a component - go through the semantic token so a flavour swap stays a one file change.

The colour block must stay `@theme inline`. Plain @theme emits a second variable on :root that the utility reads, so the value resolves once at the root element and a .dark scope below it does nothing. It also matches what the shadcn CLI emits.

Tailwind scans this file (and .tsx files) for class candidates, so writing a utility class inside a comment emits a dead rule for it. Describe classes, do not spell them.

--input is a BORDER colour in shadcn v4, not a surface. Pointing it at a surface makes every form field's outline invisible.

Latte --primary is rosewater at 3.02:1, knowingly below WCAG AA; see the comment in the file before "fixing" it.
