import { Head, usePage } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import AppearanceToggle from '@/components/appearance-toggle';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { redirect } from '@/routes/auth';

type Props = {
    providerName: string;
};

export default function Login({ providerName }: Props) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Log in" />

            <AppearanceToggle />

            <div className="flex flex-col gap-6">
                {errors.oidc && (
                    <Alert variant="destructive">
                        <CircleAlert />
                        <AlertDescription>{errors.oidc}</AlertDescription>
                    </Alert>
                )}

                {/*
                 * A plain anchor, deliberately: the target answers with a cross-origin
                 * redirect to Authentik, which an Inertia visit cannot follow.
                 */}
                <Button asChild className="w-full" data-test="login-button">
                    <a href={redirect.url()}>Sign in with {providerName}</a>
                </Button>
            </div>
        </>
    );
}

Login.layout = {
    title: 'Log in to your account',
    description: 'Use your organisation account to continue',
};
