<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Measure only headers whose captured fixed panel had script-owned padding. */
final class NavigationHeaderOffsetRuntime
{
    public static function script(): string
    {
        return <<<'JS'
(() => {
    const init = () => {
        document.querySelectorAll('.blocks-engine-navigation-header-offset').forEach(navigation => {
            const header = navigation.closest('header');
            if (!header) return;
            const update = () => navigation.style.setProperty('--blocks-engine-navigation-header-height', header.getBoundingClientRect().height + 'px');
            update();
            if (typeof ResizeObserver !== 'undefined') new ResizeObserver(update).observe(header);
            window.addEventListener('resize', update, { passive: true });
        });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
JS;
    }
}
