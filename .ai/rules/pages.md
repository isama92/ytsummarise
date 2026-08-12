---
paths:
  - 'resources/js/pages/**'
---

# Pages

## usePoll cannot be started and stopped from an effect
usePoll creates its poll in a mount effect with an empty dependency array, so autoStart is read once and never re-evaluated, and it returns freshly allocated start/stop closures on every render. Passing those to an effect either restarts the timer each render or needs a lint suppression.

Mount a tiny component conditionally instead and let it call usePoll with the default autoStart, as pages/home.tsx does with SummaryPoll: the poll's lifecycle becomes the condition and there is no dependency array to get wrong.

Polling cannot clobber form state. router.doReload() hardcodes preserveState and preserveScroll to true, which is why ReloadOptions omits both from its type.

## Strict mode runs mount effects twice, so make them repeatable
`createInertiaApp` sets `strictMode: true`, so in development React runs every mount effect, cleans it up, and runs it again. An effect that consumes a flag before arming a timer breaks on that second pass: the flag is gone, the effect returns early at its guard, and the cleanup has already cancelled the timer. The symptom is dev-only and looks like a timer that never fires.

Write the effect so running it twice is the same as running it once. `useJustFinished` in `pages/home.tsx` leaves its ref set instead of clearing it, and relies on its dependency array (not on consuming state) to stop repeating - see the comment there.

Related: nothing in `resources/js` is covered by a React test (vitest runs in `node` with no jsdom), so hook behaviour like this is only ever checked by hand. Keep the decidable part in a pure helper under `lib/` where it can be tested - `stageKeyOf` takes the "did they watch it finish" answer as an argument rather than working it out itself.

## Page state survives a form submit but not a back navigation
Submitting the form on `pages/home.tsx` keeps the component mounted (`options={{ preserveState: true }}`), so `useState` and `useRef` values carry across from one video to the next - which is what makes per-video state like `useJustFinished`'s announcement worth keying on the video id. A browser back navigation does not: Inertia remounts the page component and every hook starts again from its initial value. Verified in a browser, after a code review predicted a stuck-label bug via history navigation and the repro came back clean for exactly this reason.

The practical consequence for testing one of these bugs: reach it with a second submit rather than with `page.goBack()`.

There is no React test environment here (vitest runs in `node`, no jsdom), so hook behaviour is only ever checked by hand. Drive it from Playwright and sample the DOM on a timer rather than taking screenshots, and remember the tool round-trip is seconds long: put the state change on a delayed background command so the browser is already sampling when it lands, or the three-second window is over before the first sample.
