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

/*
 * Eleven characters of the id alphabet that are not an id.
 *
 * /embed/videoseries?list=... embeds a playlist and /embed/live_stream?channel=... embeds
 * whatever a channel is streaming. Both sit exactly where an id sits and both are exactly
 * eleven legal characters, so the boundary check has nothing to object to and the server
 * cannot tell either: it would create a row and pay for a summary of a video that does
 * not exist.
 */
const NOT_IDS = ['videoseries', 'live_stream'];

/**
 * Reduce whatever was pasted to the id the backend accepts, or null when it holds none.
 *
 * The host is deliberately not checked. Anything that carries an id in a shape YouTube
 * uses is good enough, and being strict here only ever rejects something that would have
 * worked: youtube.com, m.youtube.com, youtube-nocookie.com and youtu.be are all real.
 */
/**
 * The canonical watch url for an id.
 *
 * Built rather than stored. The id is the only part that varies, so a column holding one
 * of these would be the same eleven characters with thirty fixed ones in front of them,
 * and a link that could go stale against a row nobody would think to migrate.
 *
 * The short host without a www, which is what YouTube itself redirects to.
 */
export function watchUrl(videoId: string): string {
    return `https://youtube.com/watch?v=${videoId}`;
}

export function extractVideoId(input: string): string | null {
    const trimmed = input.trim();

    for (const pattern of PATTERNS) {
        const match = pattern.exec(trimmed);

        if (match !== null && !NOT_IDS.includes(match[1])) {
            return match[1];
        }
    }

    return null;
}
