import { Head, Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import AppearanceToggle from '@/components/appearance-toggle';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';

/*
 * One entry per status rather than two parallel maps, so a title cannot arrive without
 * its description. Undefined in the value type on purpose: the handler in
 * AppServiceProvider decides which statuses come here, and this page should read sensibly
 * if that list grows before this one does.
 *
 * 500 and 503 are here but are not currently sent; see the comment on HANDLED_STATUSES.
 */
const MESSAGES: Record<
    number,
    { title: string; description: string } | undefined
> = {
    403: {
        title: 'Not allowed',
        description: 'You do not have access to this page.',
    },
    404: {
        title: 'Not found',
        description: 'That page does not exist, or it has been removed.',
    },
    419: {
        title: 'Session expired',
        description: 'You were away a while. Reload the page and try again.',
    },
    429: {
        title: 'Too many requests',
        description: 'That was a lot at once. Wait a moment and try again.',
    },
    500: {
        title: 'Something broke',
        description:
            'Something went wrong at our end. Nothing you did caused it.',
    },
    503: {
        title: 'Back shortly',
        description:
            'The application is down for maintenance. Try again in a few minutes.',
    },
};

const FALLBACK = {
    title: 'Something went wrong',
    description: 'That request could not be completed.',
};

/**
 * Deliberately independent of the shared auth props, unlike every other page.
 *
 * This is the one place they can be missing: a url matching no route never reaches the
 * Inertia middleware. The theme toggle is fine because it reads a hook and a cookie
 * rather than page props.
 */
export default function ErrorPage({ status }: { status: number }) {
    const { title, description } = MESSAGES[status] ?? FALLBACK;

    return (
        <>
            <Head title={title} />

            <AppearanceToggle className="fixed top-4 right-4" />

            <div className="flex min-h-svh flex-col items-center justify-center px-6 pb-24">
                <AppLogoIcon className="size-8 fill-current text-foreground" />

                <p className="mt-8 text-sm text-muted-foreground">{status}</p>

                <h1 className="mt-1 text-2xl font-medium">{title}</h1>

                <p className="mt-3 max-w-sm text-center text-muted-foreground">
                    {description}
                </p>

                <Button asChild variant="outline" className="mt-8">
                    <Link href={home()} data-test="error-home-link">
                        Back to the summariser
                    </Link>
                </Button>
            </div>
        </>
    );
}
