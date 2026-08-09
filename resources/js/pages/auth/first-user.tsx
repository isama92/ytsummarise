import { Form, Head } from '@inertiajs/react';
import FirstUserController from '@/actions/App/Http/Controllers/Auth/FirstUserController';
import AppearanceToggle from '@/components/appearance-toggle';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
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
                        <FieldGroup>
                            <Field data-invalid={!!errors.name}>
                                <FieldLabel htmlFor="name">Name</FieldLabel>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoFocus
                                    autoComplete="name"
                                    placeholder="Your name"
                                    aria-invalid={!!errors.name}
                                />
                                <FieldError>{errors.name}</FieldError>
                            </Field>

                            <Field data-invalid={!!errors.email}>
                                <FieldLabel htmlFor="email">
                                    Email address
                                </FieldLabel>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                    aria-invalid={!!errors.email}
                                />
                                <FieldError>{errors.email}</FieldError>
                            </Field>
                        </FieldGroup>

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
