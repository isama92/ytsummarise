import type { FormComponentRef } from '@inertiajs/core';
import { Form, Head, usePoll } from '@inertiajs/react';
import { ArrowUp, ClipboardPaste } from 'lucide-react';
import { useCallback, useRef, useState, useSyncExternalStore } from 'react';
import type { ClipboardEvent } from 'react';
import { flushSync } from 'react-dom';
import SummaryController from '@/actions/App/Http/Controllers/SummaryController';
import AppHeader from '@/components/app-header';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { extractVideoId } from '@/lib/youtube';
import type { Summary } from '@/types';

type HomeProps = {
    videoId: string | null;
    summary: Summary | null;
};

/**
 * The entire payload: one hidden field, named below, carrying the extracted id.
 *
 * Stated as a type so `errors` is keyed by something real. Inferring it would mean
 * inferring from the fields, and the field a person actually types into is deliberately
 * nameless so that what they typed never leaves the browser.
 */
type SummaryForm = {
    video_id: string;
};

/**
 * Minutes and seconds since a summary was asked for.
 */
function elapsedSince(requestedAt: string, now: number): string {
    const seconds = Math.max(
        0,
        Math.floor((now - Date.parse(requestedAt)) / 1000),
    );

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

/*
 * Quantised to the second because a snapshot has to be stable between calls: Date.now()
 * would hand back a different number every time it was asked and React would object.
 * Still milliseconds, so it can be subtracted from a parsed date.
 */
const currentSecond = (): number => Math.floor(Date.now() / 1000) * 1000;

/**
 * The current time, refreshed once a second while something is being waited on.
 *
 * The clock is not React state - it changes on its own - so it is read as an external
 * store, the same way the appearance hook reads the stored theme. Holding it in state
 * instead made it as old as the last tick, and since the ticking only runs while a
 * summary is pending, a resubmit of an hour-old row showed the time the page was opened.
 * Read this way the value is correct at every render whether it is ticking or not.
 */
function useNow(ticking: boolean): number {
    const subscribe = useCallback(
        (onChange: () => void) => {
            if (!ticking) {
                return () => {};
            }

            const timer = setInterval(onChange, 1000);

            return () => clearInterval(timer);
        },
        /*
         * Memoised because useSyncExternalStore resubscribes whenever this function's
         * identity changes: a fresh closure each render would clear and recreate the
         * interval every render, and it would never survive long enough to fire.
         */
        [ticking],
    );

    return useSyncExternalStore(subscribe, currentSecond);
}

/**
 * Asks the server for the summary again every couple of seconds.
 *
 * Rendered only while one is being produced, so that mounting and unmounting is the
 * whole condition. usePoll reads autoStart once and hands back a new pair of start and
 * stop functions on every render, which makes driving it from an effect either wrong or
 * noisy; a component's lifecycle has none of that problem.
 */
function SummaryPoll() {
    usePoll(2000, { only: ['summary'] });

    return null;
}

export default function Home({ videoId, summary }: HomeProps) {
    /*
     * Always empty, including on a page that is showing a summary. The field is there to
     * ask for the next video, not to describe the one on screen, and prefilling it left
     * something to clear before it could be used.
     */
    const [query, setQuery] = useState('');
    const formRef = useRef<FormComponentRef<SummaryForm>>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const extractedVideoId = extractVideoId(query);
    const isUnrecognised = query.trim() !== '' && extractedVideoId === null;
    const isPending = summary?.status === 'pending';

    const now = useNow(isPending);

    /*
     * Reading the clipboard needs a secure context and a permission the browser can
     * refuse, so the button only exists where it could work. Read during render because
     * nothing here is server rendered; see config/inertia.php.
     */
    const canReadClipboard =
        typeof navigator !== 'undefined' &&
        typeof navigator.clipboard?.readText === 'function';

    /**
     * Insert pasted text where a paste would put it, and summarise if that yields an id.
     *
     * Inserting rather than replacing: pasting a link into a field that already has
     * something in it used to throw that something away, with no undo entry to get it
     * back, and then submit before there was any chance to notice.
     *
     * The flush is what makes the second half work: submitting reads the form's own
     * fields, so without it the request would carry whatever the field held before the
     * paste. This is the case flushSync exists for, handing state to an imperative API.
     */
    const summarisePastedText = (text: string): void => {
        const field = inputRef.current;

        /*
         * Only a focused field has a caret worth respecting. selectionStart on an
         * unfocused input reads 0 rather than null, so without this the paste button
         * silently prepended to whatever was already typed instead of appending.
         */
        const caret = field !== null && field === document.activeElement;
        const start = caret
            ? (field.selectionStart ?? query.length)
            : query.length;
        const end = caret ? (field.selectionEnd ?? query.length) : query.length;
        const pasted = query.slice(0, start) + text + query.slice(end);

        flushSync(() => setQuery(pasted));

        /*
         * Read from the result, not from what arrived. Pasting an id up against existing
         * text makes something that is no longer an id, and guessing which part of it was
         * meant would be worse than waiting to be told.
         */
        if (extractVideoId(pasted) !== null) {
            formRef.current?.submit();
        }
    };

    const pasteFromClipboard = async (): Promise<void> => {
        let text: string;

        /*
         * Only the read is guarded. Wrapping the paste that follows meant a failure in
         * there was reported as a refused clipboard and swallowed, so the field would
         * quietly refocus and nothing would say why.
         */
        try {
            text = await navigator.clipboard.readText();
        } catch {
            /*
             * Refused, or a prompt the person dismissed. Nothing worth reporting:
             * leave them in the field they were about to use.
             */
            inputRef.current?.focus();

            return;
        }

        summarisePastedText(text);
    };

    const handlePaste = (event: ClipboardEvent<HTMLInputElement>): void => {
        const text = event.clipboardData.getData('text');

        /*
         * Anything unrecognisable is left to the browser, so pasting a fragment in
         * order to edit it still behaves the way pasting normally does.
         */
        if (extractVideoId(text) === null) {
            return;
        }

        event.preventDefault();
        summarisePastedText(text);
    };

    return (
        <>
            <Head title="Home" />

            <AppHeader />

            {summary?.status === 'pending' && <SummaryPoll />}

            <Form<SummaryForm>
                ref={formRef}
                {...SummaryController.store.form()}
                /*
                 * Keeping the component mounted across the submit is what lets the
                 * field animate to the top rather than jump there.
                 */
                options={{ preserveState: true, preserveScroll: true }}
                /*
                 * And because it stays mounted, emptying the field is this rather than the
                 * initial state: what was asked for is on the screen now, so leaving it
                 * behind in the field is one thing to clear before asking for the next.
                 */
                onSuccess={() => setQuery('')}
                className="flex min-h-svh flex-col items-center px-6 pb-24"
            >
                {({ processing, errors }) => {
                    const hasResult = processing || summary !== null;
                    const isWorking =
                        processing || summary?.status === 'pending';

                    /*
                     * While a submit is in flight the summary prop still describes the
                     * previous attempt, so anything drawn from it would be about the wrong
                     * video: a clock counting from when that one was asked for, under the
                     * title of the video being replaced. The skeleton stands alone until
                     * the redirect lands and says what is actually being summarised.
                     */
                    const describes = processing ? null : summary;

                    const message = isUnrecognised
                        ? 'That does not look like a YouTube link or video code.'
                        : errors.video_id;

                    return (
                        <>
                            {/*
                             * Carries everything below it from the middle of the page
                             * to the top. A height is animatable in every browser,
                             * which the alignment properties that would express this
                             * more directly are not.
                             */}
                            <div
                                className={cn(
                                    'shrink-0 transition-[height] duration-500 ease-out',
                                    hasResult ? 'h-16' : 'h-[38svh]',
                                )}
                            />

                            <div className="flex w-full max-w-2xl flex-col items-center">
                                <AppLogoIcon className="size-8 fill-current text-foreground" />

                                <p className="mt-4 text-center text-sm text-muted-foreground">
                                    Paste a YouTube link and get a short summary
                                    of the video.
                                </p>

                                <div
                                    className={cn(
                                        'mt-6 flex w-full items-center gap-1 rounded-full border bg-background pr-1.5 pl-5 shadow-xs transition-[color,box-shadow] focus-within:ring-[3px]',
                                        message
                                            ? 'border-destructive focus-within:ring-destructive/20 dark:focus-within:ring-destructive/40'
                                            : 'border-input focus-within:border-ring focus-within:ring-ring/50',
                                    )}
                                >
                                    {/*
                                     * The only named field, so the request carries the
                                     * eleven character id and nothing else. Whatever was
                                     * typed or pasted never leaves the browser, which is
                                     * why the visible field below has no name.
                                     */}
                                    <input
                                        type="hidden"
                                        name="video_id"
                                        value={extractedVideoId ?? ''}
                                    />

                                    <input
                                        ref={inputRef}
                                        type="text"
                                        value={query}
                                        onChange={(event) =>
                                            setQuery(event.target.value)
                                        }
                                        onPaste={handlePaste}
                                        placeholder="YouTube link or video code"
                                        autoFocus
                                        autoComplete="off"
                                        spellCheck={false}
                                        aria-label="YouTube link or video code"
                                        aria-invalid={Boolean(message)}
                                        aria-describedby={
                                            message
                                                ? 'video-message'
                                                : undefined
                                        }
                                        className="h-12 min-w-0 flex-1 bg-transparent text-base outline-none placeholder:text-muted-foreground md:text-sm"
                                        data-test="video-input"
                                    />

                                    {canReadClipboard && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() =>
                                                void pasteFromClipboard()
                                            }
                                            aria-label="Paste a link and summarise it"
                                            className="shrink-0 rounded-full"
                                            data-test="paste-button"
                                        >
                                            <ClipboardPaste className="size-5" />
                                        </Button>
                                    )}

                                    {/*
                                     * The default variant, unlike anywhere with a
                                     * label to read: an icon only needs to clear the
                                     * 3:1 bar for non text contrast, which rosewater
                                     * does. See resources/css/app.css.
                                     */}
                                    <Button
                                        type="submit"
                                        size="icon"
                                        disabled={
                                            extractedVideoId === null ||
                                            processing
                                        }
                                        aria-label="Summarise this video"
                                        className="shrink-0 rounded-full"
                                        data-test="summarise-button"
                                    >
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <ArrowUp className="size-5" />
                                        )}
                                    </Button>
                                </div>

                                {message && (
                                    <p
                                        id="video-message"
                                        role="alert"
                                        className="mt-3 text-sm text-destructive"
                                    >
                                        {message}
                                    </p>
                                )}

                                <div
                                    aria-live="polite"
                                    aria-busy={isWorking}
                                    className="mt-12 w-full"
                                >
                                    {/*
                                     * Hidden from assistive technology on purpose: this
                                     * sits inside a polite live region, and a number that
                                     * changes every second would be read out every
                                     * second. The busy state already says it is working.
                                     */}
                                    {isWorking && describes !== null && (
                                        <p
                                            aria-hidden="true"
                                            className="text-sm text-muted-foreground tabular-nums"
                                            data-test="elapsed"
                                        >
                                            {elapsedSince(
                                                describes.requestedAt,
                                                now,
                                            )}
                                        </p>
                                    )}

                                    {/*
                                     * Known before the summary is, so it holds this spot
                                     * for the whole wait rather than appearing with the
                                     * text. The page's only heading, and absent entirely
                                     * when the lookup could not tell us the title.
                                     */}
                                    {describes?.title != null && (
                                        <h1
                                            className="mt-1 text-xl font-medium text-balance"
                                            data-test="summary-title"
                                        >
                                            {describes.title}
                                        </h1>
                                    )}

                                    {isWorking && (
                                        <div className="mt-6 space-y-3">
                                            <div className="h-4 w-full animate-pulse rounded bg-muted" />
                                            <div className="h-4 w-11/12 animate-pulse rounded bg-muted" />
                                            <div className="h-4 w-8/12 animate-pulse rounded bg-muted" />
                                        </div>
                                    )}

                                    {!isWorking &&
                                        summary?.status === 'ready' && (
                                            <div
                                                key={videoId}
                                                className="mt-6 animate-in space-y-4 text-pretty duration-700 fade-in slide-in-from-bottom-4"
                                                data-test="summary"
                                            >
                                                {summary.body
                                                    ?.split('\n\n')
                                                    .map((paragraph, index) => (
                                                        <p
                                                            // Keyed by position: the
                                                            // list never reorders, and
                                                            // two identical paragraphs
                                                            // would collide on content.
                                                            key={index}
                                                            className="leading-relaxed"
                                                        >
                                                            {paragraph}
                                                        </p>
                                                    ))}
                                            </div>
                                        )}

                                    {!isWorking &&
                                        summary?.status === 'failed' && (
                                            <p
                                                className="mt-6 text-sm text-muted-foreground"
                                                data-test="summary-failed"
                                            >
                                                Summarising this video did not
                                                work. Submit it again to try
                                                once more.
                                            </p>
                                        )}
                                </div>
                            </div>
                        </>
                    );
                }}
            </Form>
        </>
    );
}
