import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import AppearanceToggle from '@/components/appearance-toggle';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center bg-background p-6 md:p-10">
            {/*
             * Here rather than in each page, and positioned against the viewport, because
             * rendering it inside the card put a fixed control in the middle of the
             * form's tab order.
             */}
            <AppearanceToggle className="fixed top-4 right-4" />

            <div className="flex w-full max-w-sm flex-col gap-6">
                <Link
                    href={home()}
                    className="flex flex-col items-center gap-2 font-medium"
                >
                    <AppLogoIcon className="size-9 fill-current text-foreground" />
                    <span className="sr-only">{title}</span>
                </Link>

                <Card>
                    <CardHeader className="text-center">
                        <CardTitle className="text-xl">{title}</CardTitle>
                        <CardDescription>{description}</CardDescription>
                    </CardHeader>

                    <CardContent>{children}</CardContent>
                </Card>
            </div>
        </div>
    );
}
