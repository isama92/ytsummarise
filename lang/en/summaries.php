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
    ],

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
     * Keyed by the values of App\Enums\SummaryError, so a reason from the database needs no
     * lookup table to become a sentence.
     *
     * Every reason worth another attempt says so; not_found deliberately does not, because
     * submitting a video that does not exist a second time fails in exactly the same way.
     */
    'errors' => [
        'not_found' => 'That video does not exist, or it is private.',
        'unreachable' => 'We could not reach YouTube. Submit it again to try once more.',
        'timed_out' => 'This one waited too long and was given up on. Submit it again to try once more.',
        'unknown' => 'Summarising this video did not work. Submit it again to try once more.',
    ],
];
