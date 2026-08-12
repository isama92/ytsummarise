import { Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';

/**
 * Positioning belongs to the caller. This used to place itself against the viewport,
 * which meant it could never sit in a row alongside anything else.
 */
export default function AppearanceToggle({
    className = '',
}: {
    className?: string;
}) {
    const { appearance, toggleAppearance } = useAppearance();
    const isDark = appearance === 'dark';

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={toggleAppearance}
            aria-label={
                isDark
                    ? 'Switch to the light theme'
                    : 'Switch to the dark theme'
            }
            data-test="appearance-toggle"
            className={className}
        >
            {isDark ? <Moon className="size-5" /> : <Sun className="size-5" />}
        </Button>
    );
}
