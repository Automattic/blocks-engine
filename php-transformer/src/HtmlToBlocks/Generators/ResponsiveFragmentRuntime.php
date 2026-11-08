<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

/** Resolve authored hashes without changing shared navigation or variant ids. */
final class ResponsiveFragmentRuntime
{
    public static function script(): string
    {
        return str_replace('__FRAGMENT__', CaptureEvidence::RESPONSIVE_FRAGMENT, <<<'JS'
(function () {
    function resolve() {
        let fragment;
        try { fragment = decodeURIComponent(location.hash.slice(1)); } catch (_) { return; }
        if (!fragment) return;
        const targets = Array.from(document.querySelectorAll('[__FRAGMENT__]')).filter(element =>
            element.getAttribute('__FRAGMENT__') === fragment && element.getClientRects().length);
        if (targets.length === 1) targets[0].scrollIntoView();
    }
    function schedule() { requestAnimationFrame(resolve); }
    window.addEventListener('hashchange', schedule);
    document.addEventListener('click', function (event) {
        const link = event.target.closest && event.target.closest('a[href]');
        if (link && link.origin === location.origin && link.pathname === location.pathname &&
            link.search === location.search && link.hash) schedule();
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', schedule);
    else schedule();
})();
JS);
    }
}
