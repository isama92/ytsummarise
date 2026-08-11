import { Head, Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import AppearanceToggle from '@/components/appearance-toggle';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';

/*
 * Undefined in the value type on purpose: the handler in AppServiceProvider decides
 * which statuses come here, and this page should read sensibly if that list grows
 * before these do.
 */
const TITLES: Record<number, string | undefined> = {
    403: 'Not allowed',
    404: 'Not found',
    500: 'Something broke',
    503: 'Back shortly',
};

const DESCRIPTIONS: Record<number, string | undefined> = {
    403: 'You do not have access to this page.',
    404: 'That page does not exist, or it has been removed.',
    500: 'Something went wrong at our end. Nothing you did caused it.',
    503: 'The application is down for maintenance. Try again in a few minutes.',
};

/**
 * Deliberately independent of the shared auth props, unlike every other page.
 *
 * This is the one place they can be missing: a url matching no route never reaches the
 * Inertia middleware. The theme toggle is fine because it reads a hook and a cookie
 * rather than page props.
 */
export default function ErrorPage({ status }: { status: number }) {
    const title = TITLES[status] ?? 'Something went wrong';
    const description =
        DESCRIPTIONS[status] ?? 'That request could not be completed.';

    return (
        <>
            <Head title={title} />

            <div className="fixed top-4 right-4">
                <AppearanceToggle />
            </div>

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
