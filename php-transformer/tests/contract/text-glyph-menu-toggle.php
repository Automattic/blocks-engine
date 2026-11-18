<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\NavigationOpenerRuntime;

// Issue #6401: the reduced page, including the source's class-toggle script.
$html = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Reduced</title><style>
*{box-sizing:border-box}body{margin:0;font-family:sans-serif;background:#111}
.nav{height:74px;display:flex;align-items:center;justify-content:space-between;padding:0 5%;color:#f5f1eb}
.brand{color:#f5f1eb;font-size:25px;text-decoration:none}
.links{display:flex;gap:32px;align-items:center}
.links a{color:#f5f1eb;font-size:11px;text-transform:uppercase}
.menu{display:none;border:0;background:transparent;color:white;font-size:24px}
@media(max-width:850px){.nav{height:65px}.menu{display:block}.links{display:none;position:absolute;top:65px;left:0;right:0;background:#24201e;padding:25px 7%;flex-direction:column;align-items:flex-start}.links.show{display:flex}}
</style></head><body>
<header class="nav"><a href="#top" class="brand">brand.</a><button class="menu" aria-label="Open navigation">☰</button><nav class="links"><a href="#a">One</a><a href="#b">Two</a><a href="#c">Three</a></nav></header>
<main><h1 style="color:#fff">Page</h1></main>
<script>document.querySelector('.menu').onclick=()=>document.querySelector('.links').classList.toggle('show')</script>
</body></html>
HTML;
$compile = static fn (string $source): array => (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(array('path' => 'index.html', 'content' => $source)),
))->toArray();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$result = $compile($html);
$markup = $result['serialized_blocks'];
preg_match_all('/<!-- wp:navigation (\{[^<]*?\}) (?:\/)?-->/', $markup, $matches);
$attrs = array_map(static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $matches[1]);
$glyph = array_values(array_filter($attrs, static fn (array $attr): bool => '☰' === ($attr['metadata']['blocksEngineNavigationOpener']['text'] ?? '')));
$assert(1 === count($glyph), 'The source glyph must reach exactly one editable native navigation opener.');
$assert('mobile' === ($glyph[0]['overlayMenu'] ?? ''), 'Core retains the responsive overlay behavior.');
$assert(false !== ($glyph[0]['hasIcon'] ?? true), 'A glyph is artwork, not Core’s Menu text label.');
$assert(!str_contains($markup, '<button'), 'The inert source control is superseded.');
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '') && !str_contains($asset['path'], 'editor-static')), 'content'));
$assert(str_contains($css, '@media(max-width:850px)'), 'Source collapse boundary is retained.');
$assert(str_contains($css, '@media(min-width:600px) and (max-width:850px)'), 'Core’s 600px switch is extended only within the source range.');
$assert((bool) preg_match('/responsive-container-open\{[^}]*font-size:24px!important/', $css), 'The glyph keeps the source font size.');
$assert((bool) preg_match('/responsive-container-open\{[^}]*color:(?:white|#fff(?:fff)?)!important/', $css), 'The glyph keeps the source white paint.');
$assert((bool) preg_match('/responsive-container-open\{[^}]*background:transparent!important/', $css), 'The opener does not inherit the overlay background.');
$assert((bool) preg_match('/responsive-container-open\{min-width:0!important;min-height:0!important/', $css), 'Intrinsic text sizing replaces the synthetic 44px touch floor.');
$assert(str_contains($css, 'font:revert!important;letter-spacing:normal!important'), 'Native button metrics replace inherited navigation typography before source font replay.');
// Check the host, not just the transparent leaf button: the source panel's
// class survives on this visible box even while the source panel was hidden.
$dom = new DOMDocument();
$host = $dom->createElement('nav');
$host->setAttribute('class', 'wp-block-navigation ' . $glyph[0]['className']);
$dom->appendChild($host);
foreach (array(390, 768, 1440) as $width) {
    $reset = false;
    (new \Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer())->visitStyleRules($css,
        static function (string $selector, string $body, array $conditions) use ($host, $width, &$reset): void {
            foreach ($conditions as $condition) {
                if (preg_match('/max-width:\s*([\d.]+)px/', $condition, $m) && $width > (float) $m[1]) return;
                if (preg_match('/min-width:\s*([\d.]+)px/', $condition, $m) && $width < (float) $m[1]) return;
            }
            $parsed = \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::parse($selector);
            if (!(\Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::matches($host, $parsed, true)['matches'] ?? false)) return;
            if (str_contains($body, 'background:transparent!important')
                && str_contains($body, 'box-shadow:none!important')
                && str_contains($body, 'outline:0!important')) $reset = true;
        }
    );
    $assert(($width < 850) === $reset, "$width: only the collapsed navigation root must lose panel box paint.");
}
$assert('#24201e' === ($glyph[0]['customOverlayBackgroundColor'] ?? ''), 'The open overlay retains the source panel background.');
$assert((bool) preg_match('/responsive-container\.is-menu-open[^{}]*\{[^}]*background:#24201e!important/', $css), 'The source panel paint is applied to the open overlay, not its visible root.');
foreach (array('≡', "☰\u{FE0F}") as $alternate) {
    $variant = $compile(str_replace('>☰</button>', '>' . $alternate . '</button>', $html));
    $assert(str_contains($variant['serialized_blocks'], '"text":"' . $alternate . '"'), 'Equivalent menu glyphs and variation selectors retain their content.');
}

// Exercise the emitted render filter against Core-shaped markup. The native
// event attributes and the independent close button must survive untouched.
$GLOBALS['text_glyph_filter'] = null;
function add_filter(string $hook, callable $callback, int $priority, int $arguments): void {
    $GLOBALS['text_glyph_filter'] = $callback;
}
function esc_html(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
eval(NavigationOpenerRuntime::source());
$core = '<nav><button class="wp-block-navigation__responsive-container-open" data-wp-on--click="actions.openMenu" aria-haspopup="dialog"><svg>default</svg></button><button class="wp-block-navigation__responsive-container-close"><svg>close</svg></button></nav>';
$render = $GLOBALS['text_glyph_filter'];
$block = array('attrs' => array('metadata' => array('blocksEngineNavigationOpener' => array('text' => '☰'))));
$rendered = $render($core, $block);
$assert(str_contains($rendered, 'aria-haspopup="dialog">☰</button>'), 'Runtime replaces only the opener’s content.');
$assert(str_contains($rendered, 'data-wp-on--click="actions.openMenu"'), 'Core’s open handler survives.');
$assert(str_contains($rendered, '<svg>close</svg>'), 'Core’s close control survives.');
$assert($core === $render($core, array('attrs' => array())), 'Desktop/unrelated navigation without metadata is unchanged.');
$block['attrs']['metadata']['blocksEngineNavigationOpener']['text'] = '<script>bad</script>';
$assert($core === $render($core, $block), 'Unproven glyph metadata cannot inject markup.');
echo "text-glyph-menu-toggle: ok\n";
