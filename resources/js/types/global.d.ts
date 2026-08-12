import type { Auth } from '@/types/auth';
import type { Translations } from '@/types/lang';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            lang: Translations;
            [key: string]: unknown;
        };
    }
}
