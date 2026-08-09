import { Form, Head, usePage } from '@inertiajs/react';
import AppearanceToggle from '@/components/appearance-toggle';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Home" />

            <AppearanceToggle />

            <div className="flex min-h-screen flex-col items-center justify-center gap-6 bg-background p-6 text-foreground">
                <h1 className="text-center text-2xl font-medium">
                    Hello, {auth.user?.name}
                </h1>

                <Form {...logout.form()}>
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            data-test="logout-button"
                        >
                            {processing && <Spinner />}
                            Log out
                        </Button>
                    )}
                </Form>
            </div>
        </>
    );
}
