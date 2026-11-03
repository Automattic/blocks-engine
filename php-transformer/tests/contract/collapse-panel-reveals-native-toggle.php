<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Reduced from an issue report: a disclosure header whose collapsed panel
// holds the menu list plus a sibling CTA button. The panel keeps its own
// wrapper in the projection and, with it, the author closed state
// (`.collapse:not(.show){display:none}`) below the desktop breakpoint. The
// native navigation's overlay replaces that collapse behaviour, so the
// closed state must not hide Core's open button inside the wrapper. Without
// the sibling the panel is absorbed into the navigation block, and its own
// toggle rules already own the closed state — no wrapper reveal may appear.
$css = <<<'CSS'
.navbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;padding:24px 16px}
.brand{font-size:24px;font-weight:700;color:#222;text-decoration:none}
.toggler{padding:4px 12px;font-size:20px;background:transparent;border:1px solid #ccc;border-radius:4px}
.collapse:not(.show){display:none}
.panel{flex-basis:100%;flex-grow:1;align-items:center}
.menu{display:flex;flex-direction:column;list-style:none;margin:0;padding:0}
@media (min-width:992px){
 .navbar{flex-wrap:nowrap}
 .toggler{display:none}
 .panel{display:flex!important;flex-basis:auto;justify-content:flex-end}
 .menu{flex-direction:row}
}
CSS;
$menu = '<ul class="menu"><li><a href="/about/">About</a></li><li><a href="/news/">News</a></li><li><a href="/contact/">Contact</a></li></ul>';
$failures = array();
foreach (array(true, false) as $panelKeepsSibling) {
    $label = $panelKeepsSibling ? 'sibling' : 'absorbed';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Collapse toggle</title><style>' . $css . '</style></head><body>'
        . '<header><nav class="navbar"><a class="brand" href="/">Acme</a>'
        . '<button class="toggler" type="button" aria-controls="panel" aria-expanded="false" aria-label="Toggle navigation">&#9776;</button>'
        . '<div class="collapse panel" id="panel">' . $menu . ($panelKeepsSibling ? '<button class="cta" type="button">Donate</button>' : '') . '</div>'
        . '</nav></header><main><h1>Hello</h1><p>Body text.</p></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    $xpath = new DOMXPath($dom);
    $wrapper = $xpath->query('//*[@id="panel"]')->item(0);
    $navigation = null;
    foreach ($xpath->query('//comment()') as $comment) {
        if ($comment instanceof DOMComment && preg_match('/^ wp:navigation (\{.*\}) /s', $comment->data, $match)) {
            $navigation = json_decode($match[1], true);
            break;
        }
    }
    if (!is_array($navigation) || 'mobile' !== ($navigation['overlayMenu'] ?? null)
        || !preg_match('/blocks-engine-native-navigation-toggle-[0-9a-f]+/', (string) ($navigation['className'] ?? ''), $markerMatch)) {
        $failures[] = "$label: the menu must project as a native responsive navigation carrying its toggle marker.";
        continue;
    }
    $marker = $markerMatch[0];
    $wrapperKept = $wrapper instanceof DOMElement;
    if ($panelKeepsSibling !== $wrapperKept) {
        $failures[] = "$label: the panel wrapper must survive exactly when the panel holds content beside the menu.";
        continue;
    }
    if ($panelKeepsSibling && false === strpos((string) ($wrapper->getAttribute('class') ?? ''), 'collapse')) {
        $failures[] = "$label: the kept wrapper must carry the source closed-state class the author rules address.";
    }
    if (!$panelKeepsSibling && false === strpos((string) ($navigation['className'] ?? ''), 'collapse')) {
        $failures[] = "$label: the absorbed panel must hand its identity to the navigation block.";
    }

    // The wrapper reveal: keyed on the wrapper through this occurrence's
    // marker, and scoped to the collapsed range so the desktop cascade keeps
    // owning the panel's shown state.
    $panelReveals = array();
    foreach ($result['assets'] ?? array() as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) {
            continue;
        }
        (new CssStylesheetTransformer())->visitStyleRules((string) ($asset['content'] ?? ''), static function (string $selector, string $body, array $conditions) use ($marker, &$panelReveals): void {
            if (str_contains($selector, $marker) && str_contains($selector, '.wp-block-group:has(>') && preg_match('/(?:^|;)display:flex!important(?:;|$)/', $body)) {
                $panelReveals[] = $conditions;
            }
        });
    }
    $revealedAt = static function (float $width) use ($panelReveals): bool {
        foreach ($panelReveals as $conditions) {
            $active = true;
            foreach ($conditions as $condition) {
                $condition = (string) $condition;
                if (preg_match('/min-width:\s*([\d.]+)px/', $condition, $m) && $width < (float) $m[1]) {
                    $active = false;
                }
                if (preg_match('/max-width:\s*([\d.]+)px/', $condition, $m) && $width > (float) $m[1]) {
                    $active = false;
                }
            }
            if ($active) {
                return true;
            }
        }
        return false;
    };
    if ($panelKeepsSibling) {
        foreach (array(390.0, 768.0) as $width) {
            if (!$revealedAt($width)) {
                $failures[] = "$width: the closed panel must be revealed across the collapsed range, or it hides Core's open button and mobile visitors lose the menu.";
            }
        }
        if ($revealedAt(1200.0)) {
            $failures[] = '1200: the wrapper reveal must stay inside the collapsed range; the desktop panel state belongs to the source cascade.';
        }
    } elseif (array() !== $panelReveals) {
        $failures[] = "$label: a panel holding only the menu is absorbed into the navigation block and must not gain a wrapper reveal.";
    }

    // Core's open button keeps the source toggler's box inside the wrapper at
    // the collapsed widths (the reported failing check: geometry at 390/768).
    $host = $dom->createElement('nav');
    $host->setAttribute('class', 'wp-block-navigation ' . ($navigation['className'] ?? ''));
    $dom->documentElement->appendChild($host);
    $open = $dom->createElement('button');
    $open->setAttribute('class', 'wp-block-navigation__responsive-container-open');
    $host->appendChild($open);
    foreach (array(390.0, 768.0) as $width) {
        $sized = false;
        foreach ($result['assets'] ?? array() as $asset) {
            if ('css' !== ($asset['kind'] ?? '')) {
                continue;
            }
            (new CssStylesheetTransformer())->visitStyleRules((string) ($asset['content'] ?? ''), static function (string $selector, string $body, array $conditions) use ($open, $width, &$sized): void {
                $parsed = CssSelectorMatcher::parse($selector);
                if (!(CssSelectorMatcher::matches($open, $parsed, true)['matches'] ?? false)) {
                    return;
                }
                foreach ($conditions as $condition) {
                    $condition = (string) $condition;
                    if (preg_match('/min-width:\s*([\d.]+)px/', $condition, $m) && $width < (float) $m[1]) {
                        return;
                    }
                    if (preg_match('/max-width:\s*([\d.]+)px/', $condition, $m) && $width > (float) $m[1]) {
                        return;
                    }
                }
                if (preg_match('/min-width:\s*44px/', $body) && preg_match('/min-height:\s*44px/', $body)) {
                    $sized = true;
                }
            });
        }
        if (!$sized) {
            $failures[] = "$width: Core's open button must keep the source toggler's box inside the panel.";
        }
    }
}
foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
}
exit(array() === $failures ? 0 : 1);
