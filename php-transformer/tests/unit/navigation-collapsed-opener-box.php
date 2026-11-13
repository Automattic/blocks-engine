<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Wrapped artwork is not promoted as a passive SVG. Even in that fallback,
// the authored mobile control box must not grow to a synthetic 44px target.
$result = (new HtmlTransformer())->transform('<style>
.toggle{display:none} .links{display:flex}
@media(max-width:800px){.toggle{display:flex}.icon{--size:30px;width:var(--size);height:var(--size)}.links{display:none}}
</style><header><button class="toggle" aria-controls="menu" aria-expanded="false" aria-label="Menu"><div class="icon"><svg width="200" height="200" viewBox="0 0 200 200"><path d="M0 0h200v20H0z"/></svg></div></button><nav id="menu" class="links"><a href="/">Home</a><a href="/about/">About</a></nav></header>', array())->toArray();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
if (!preg_match('/\.blocks-engine-native-navigation-toggle-[a-f0-9]+>\.wp-block-navigation__responsive-container-open\{[^}]*width:30px!important;[^}]*height:30px!important/', $css)) {
    throw new RuntimeException('Native opener must retain its variable-backed collapsed box');
}
if (preg_match('/\.blocks-engine-native-navigation-toggle-[a-f0-9]+>\.wp-block-navigation__responsive-container-open\{[^}]*min-(?:width|height):44px/', $css)) {
    throw new RuntimeException('Fallback touch target must not enlarge an authored opener');
}
echo "navigation-collapsed-opener-box: ok\n";
