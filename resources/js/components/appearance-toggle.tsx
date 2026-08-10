import { Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

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
            className={cn('fixed top-4 right-4', className)}
        >
            {isDark ? <Moon className="size-5" /> : <Sun className="size-5" />}
        </Button>
    );
}
