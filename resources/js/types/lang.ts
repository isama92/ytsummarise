/**
 * A set of translation groups, in the shape the lang/en/*.php files already have.
 *
 * Nested rather than flattened, because that is what those files return and flattening on the
 * way through would be a second representation to keep in step. Which groups arrive is decided
 * by HandleInertiaRequests::TRANSLATED_GROUPS.
 */
export type Translations = {
    [key: string]: string | Translations;
};
