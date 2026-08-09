import { Form, Head } from '@inertiajs/react';
import FirstUserController from '@/actions/App/Http/Controllers/Auth/FirstUserController';
import AppearanceToggle from '@/components/appearance-toggle';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

export default function FirstUser() {
    return (
        <>
            <Head title="Create your account" />

            <AppearanceToggle />

            <Form
                {...FirstUserController.store.form()}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <label
                                htmlFor="name"
                                className="text-sm font-medium"
                            >
                                Name
                            </label>
                            <Input
                                id="name"
                                name="name"
                                required
                                autoFocus
                                autoComplete="name"
                                placeholder="Your name"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <label
                                htmlFor="email"
                                className="text-sm font-medium"
                            >
                                Email address
                            </label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoComplete="email"
                                placeholder="email@example.com"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            data-test="first-user-button"
                        >
                            {processing && <Spinner />}
                            Continue
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

FirstUser.layout = {
    title: 'Create your account',
    description: 'Sign in is turned off, so this account is all you need',
};
