<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Frontend-only passive artwork; the editor and stored core save stay native. */
final class VectorIconPresentation
{
    /** @param array<string, string> $artwork */
    public function script(array $artwork): string
    {
        if (array() === $artwork) return '';
        $payload = json_encode($artwork, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        return "(() => {\nconst artwork = {$payload};\n" . <<<'JS'
const paint = () => {
    for (const [selector, markup] of Object.entries(artwork)) {
        for (const carrier of document.querySelectorAll(selector)) {
            if (carrier.hasAttribute('data-blocks-engine-vector-icon')) continue;
            const template = document.createElement('template');
            template.innerHTML = markup;
            const svg = template.content.firstElementChild;
            if (!svg || svg.namespaceURI !== 'http://www.w3.org/2000/svg' || svg.localName !== 'svg') continue;
            svg.setAttribute('aria-hidden', 'true');
            svg.setAttribute('focusable', 'false');
            carrier.replaceChildren(svg);
            carrier.setAttribute('data-blocks-engine-vector-icon', '');
        }
    }
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', paint, {once: true});
else paint();
})();
JS;
    }
}
