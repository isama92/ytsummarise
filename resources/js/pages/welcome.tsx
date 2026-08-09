import { Form, Head, usePage } from '@inertiajs/react';
import AppearanceToggle from '@/components/appearance-toggle';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Home" />

            <AppearanceToggle />

            <div className="flex min-h-screen flex-col items-center justify-center bg-background p-6">
                <Card className="w-full max-w-sm">
                    <CardHeader className="text-center">
                        <CardTitle className="text-2xl">
                            Hello, {auth.user?.name}
                        </CardTitle>
                    </CardHeader>

                    {/*
                     * With authentication off there is nothing to log out of: the next
                     * request would sign the same user straight back in.
                     */}
                    {auth.enabled && (
                        <CardContent className="flex justify-center">
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
                        </CardContent>
                    )}
                </Card>
            </div>
        </>
    );
}
