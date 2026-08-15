<?php

declare(strict_types=1);

/*
 * Every word the summariser puts in front of somebody, including the ones only a screen
 * reader says out loud.
 *
 * Shared with the frontend through HandleInertiaRequests::TRANSLATED_GROUPS and read with the
 * useTranslate hook. See .ai/rules/i18n.md.
 */
return [
    'page_title' => 'Home',

    'tagline' => 'Paste a YouTube link and get a short summary of the video.',

    /*
     * One string for the placeholder and the accessible name both, so the field cannot end
     * up announced as one thing and labelled as another.
     */
    'field' => [
        'label' => 'YouTube link or video code',
    ],

    'actions' => [
        'paste' => 'Paste a link and summarise it',
        'submit' => 'Summarise this video',

        /*
         * On a link that leaves the application, so it says where it goes. "Watch" alone
         * would be the same words as a play button on a video embedded here, which this
         * deliberately is not.
         */
        'watch' => 'Watch on YouTube',
    ],

    /*
     * There is deliberately no string for the cover image's alt text. The image is decorative:
     * it carries nothing the title beside it does not already say, and the link it sits inside
     * goes where the one below it goes. So it is alt="" and hidden from assistive technology
     * rather than announced as a second link with almost the same name; see pages/home.tsx.
     */

    /*
     * Where an attempt has got to, in a word. Queued and processing are one status on the
     * server; the difference is whether a worker has claimed the row, and saying so is what
     * explains a long wait when the queue is backed up.
     */
    'stage' => [
        'queued' => 'Queued',
        'processing' => 'Processing',
        'ready' => 'Ready',
    ],

    'unrecognised' => 'That does not look like a YouTube link or video code.',

    /*
     * The parts of a summary, as headings above them.
     *
     * Named for what each part is for rather than for what it contains, because that is the
     * question somebody scanning the page is asking: which of these do I read.
     */
    'sections' => [
        'headline' => 'In short',
        'points' => 'What it covers',
        'takeaways' => 'Worth remembering',
    ],

    /*
     * The heading over the English version of a summary of a video that was not in English.
     *
     * Only ever shown when there are two, so it does not need to say which language the one
     * above is in - it says which language this one is in, which is the useful half.
     */
    'translation' => 'In English',

    /*
     * Keyed by the values of App\Enums\SummaryError, so a reason from the database needs no
     * lookup table to become a sentence.
     *
     * Every reason worth another attempt says so; not_found deliberately does not, because
     * submitting a video that does not exist a second time fails in exactly the same way.
     */
    'errors' => [
        'not_found' => 'That video does not exist, or it is private.',
        'unreachable' => 'We could not reach YouTube. Submit it again to try once more.',

        /*
         * The other reason that does not invite a retry, for the same reason not_found does
         * not: the captions are what gets summarised, and a video without any has nothing to
         * summarise however many times it is asked for.
         */
        'no_transcript' => 'That video has no subtitles, so there is nothing to summarise.',
        'unavailable' => 'We could not get the subtitles for that video. Submit it again to try once more.',
        'timed_out' => 'This one waited too long and was given up on. Submit it again to try once more.',
        'unknown' => 'Summarising this video did not work. Submit it again to try once more.',
    ],
];
