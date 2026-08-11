import type { FormComponentRef } from '@inertiajs/core';
import { Form, Head, usePoll } from '@inertiajs/react';
import { ArrowUp, ClipboardPaste } from 'lucide-react';
import { useRef, useState } from 'react';
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
 * The entire payload. Stated as a type because transform() replaces whatever the form
 * collected, so this shape is not inferable from the fields.
 */
type SummaryForm = {
    video_id: string;
};

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
    const [query, setQuery] = useState(videoId ?? '');
    const formRef = useRef<FormComponentRef<SummaryForm>>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const extractedVideoId = extractVideoId(query);
    const isUnrecognised = query.trim() !== '' && extractedVideoId === null;

    /*
     * Reading the clipboard needs a secure context and a permission the browser can
     * refuse, so the button only exists where it could work. Read during render because
     * nothing here is server rendered; see config/inertia.php.
     */
    const canReadClipboard =
        typeof navigator !== 'undefined' &&
        typeof navigator.clipboard?.readText === 'function';

    /**
     * Fill the field and, when there is an id in what arrived, summarise it right away.
     *
     * The flush is what makes the second half work: submitting reads the form's own
     * fields, so without it the request would carry whatever the field held before the
     * paste. This is the case flushSync exists for, handing state to an imperative API.
     */
    const summarisePastedText = (text: string): void => {
        flushSync(() => setQuery(text));

        if (extractVideoId(text) !== null) {
            formRef.current?.submit();
        }
    };

    const pasteFromClipboard = async (): Promise<void> => {
        try {
            summarisePastedText(await navigator.clipboard.readText());
        } catch {
            /*
             * Refused, or a prompt the person dismissed. Nothing worth reporting:
             * leave them in the field they were about to use.
             */
            inputRef.current?.focus();
        }
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
                className="flex min-h-svh flex-col items-center px-6 pb-24"
            >
                {({ processing, errors }) => {
                    const hasResult = processing || summary !== null;
                    const isWorking =
                        processing || summary?.status === 'pending';
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
                                    {isWorking && (
                                        <div className="space-y-3">
                                            <div className="h-4 w-full animate-pulse rounded bg-muted" />
                                            <div className="h-4 w-11/12 animate-pulse rounded bg-muted" />
                                            <div className="h-4 w-8/12 animate-pulse rounded bg-muted" />
                                        </div>
                                    )}

                                    {!isWorking &&
                                        summary?.status === 'ready' && (
                                            <div
                                                key={videoId}
                                                className="animate-in space-y-4 text-pretty duration-700 fade-in slide-in-from-bottom-4"
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
                                                className="text-sm text-muted-foreground"
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
