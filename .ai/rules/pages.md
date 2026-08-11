---
paths:
  - 'resources/js/pages/**'
---

# Pages

## usePoll cannot be started and stopped from an effect
usePoll creates its poll in a mount effect with an empty dependency array, so autoStart is read once and never re-evaluated, and it returns freshly allocated start/stop closures on every render. Passing those to an effect either restarts the timer each render or needs a lint suppression.

Mount a tiny component conditionally instead and let it call usePoll with the default autoStart, as pages/home.tsx does with SummaryPoll: the poll's lifecycle becomes the condition and there is no dependency array to get wrong.

Polling cannot clobber form state. router.doReload() hardcodes preserveState and preserveScroll to true, which is why ReloadOptions omits both from its type.
