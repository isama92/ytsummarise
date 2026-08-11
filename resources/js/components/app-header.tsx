import { Form, usePage } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import AppearanceToggle from '@/components/appearance-toggle';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';

/**
 * Who you are and the two controls that go with it, parked in the corner.
 *
 * Translucent rather than transparent because a long summary scrolls underneath it.
 */
export default function AppHeader() {
    const { auth } = usePage().props;

    return (
        <header className="fixed top-3 right-3 z-10 flex items-center gap-1 rounded-full bg-background/70 p-1 backdrop-blur-sm">
            {auth.user !== null && (
                <span className="hidden px-2 text-sm text-muted-foreground sm:inline">
                    {auth.user.name}
                </span>
            )}

            {/*
             * With authentication off there is nothing to log out of: the next
             * request would sign the same user straight back in.
             */}
            {auth.enabled && (
                <Form {...logout.form()}>
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="ghost"
                            size="icon"
                            disabled={processing}
                            aria-label="Log out"
                            data-test="logout-button"
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <LogOut className="size-5" />
                            )}
                        </Button>
                    )}
                </Form>
            )}

            <AppearanceToggle />
        </header>
    );
}
