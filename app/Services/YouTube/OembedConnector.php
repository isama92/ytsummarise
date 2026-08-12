<?php

declare(strict_types=1);

namespace App\Services\YouTube;

use Override;

/**
 * YouTube's oEmbed endpoint, which answers almost every lookup on its own.
 *
 * No key, no quota and no account, which is why it is asked first and why the application works
 * without any YouTube credentials at all.
 */
class OembedConnector extends YouTubeConnector
{
    #[Override]
    public function resolveBaseUrl(): string
    {
        return 'https://www.youtube.com';
    }
}
