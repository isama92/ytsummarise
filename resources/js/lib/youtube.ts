/**
 * A YouTube video id: always eleven characters, and the alphabet includes - and _.
 */
const VIDEO_ID = '[A-Za-z0-9_-]{11}';

/*
 * Every pattern is followed by a check that the id ends where it ends. Without it a
 * twelve character run would quietly match its first eleven characters and we would
 * summarise the wrong video, or a video that does not exist, instead of saying no.
 */
const BOUNDARY = '(?![A-Za-z0-9_-])';

const PATTERNS = [
    /* watch?v=ID, wherever v sits among the other query parameters. */
    new RegExp(`[?&]v=(${VIDEO_ID})${BOUNDARY}`),

    /* youtu.be/ID, plus the /shorts/ /embed/ /live/ /v/ path forms. */
    new RegExp(
        `(?:youtu\\.be|/shorts|/embed|/live|/v)/(${VIDEO_ID})${BOUNDARY}`,
    ),

    /* The id on its own, which is the other thing people paste. */
    new RegExp(`^(${VIDEO_ID})$`),
];

/**
 * Reduce whatever was pasted to the id the backend accepts, or null when it holds none.
 *
 * The host is deliberately not checked. Anything that carries an id in a shape YouTube
 * uses is good enough, and being strict here only ever rejects something that would have
 * worked: youtube.com, m.youtube.com, youtube-nocookie.com and youtu.be are all real.
 */
export function extractVideoId(input: string): string | null {
    const trimmed = input.trim();

    for (const pattern of PATTERNS) {
        const match = pattern.exec(trimmed);

        if (match !== null) {
            return match[1];
        }
    }

    return null;
}
