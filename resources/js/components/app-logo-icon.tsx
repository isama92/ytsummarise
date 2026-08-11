import type { SVGAttributes } from 'react';

/**
 * A play triangle and three lines: a video going in, a summary coming out.
 *
 * Fill only, with nothing set on the shapes themselves, so they inherit whatever the
 * caller puts on the svg. Every use passes the current text colour, which is what lets
 * one mark serve both Catppuccin flavours. The corner radii are here for the same reason
 * the pill and the buttons have them.
 *
 * public/favicon.svg is the same geometry on a rosewater tile; keep the two in step.
 *
 * The viewBox is cropped to the shapes plus a unit of air rather than being the square
 * canvas they were drawn on, so the mark fills whatever box a caller sizes it into.
 * Whitespace around the logo belongs to the layout, not to the asset.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="4 5.5 33 29"
            xmlns="http://www.w3.org/2000/svg"
        >
            <path d="M5 8.4A2.2 2.2 0 0 1 8.4 6.5L19.8 18.2a2.5 2.5 0 0 1 0 3.6L8.4 33.5A2.2 2.2 0 0 1 5 31.6Z" />
            <rect x="25" y="13" width="11" height="3.6" rx="1.8" />
            <rect x="25" y="19.7" width="11" height="3.6" rx="1.8" />
            <rect x="25" y="26.4" width="7" height="3.6" rx="1.8" />
        </svg>
    );
}
