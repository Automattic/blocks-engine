<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Routes share one header, but each route links its own component sheets. A
// route whose page content uses a builder preset adds rules keyed off
// `@container style(--preset-button: filled)`, some of which also name the
// header burger's preset class (restating the burger's own resting paint).
// No ancestor of the burger declares that property, so on this route the
// query never holds for it. A route can also
// carry a conditional rule that matches the burger but states none of the
// properties its presentation reads. Neither changes how the burger renders,
// so the content-addressed native toggle marker must stay equal across
// routes, or the header cannot be shared.
$header = '<header class="site-header"><a class="brand" href="/">Acme</a>'
    . '<nav class="navbar" aria-label="Site"><ul class="items"><li><a href="/about/">About</a></li><li><a href="/work/">Work</a></li></ul></nav>'
    . '<div class="burger-wrap"><button type="button" class="burger" aria-label="Open menu" aria-haspopup="dialog"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor"/></svg></button></div></header>';
$base = '.site-header{display:flex;justify-content:space-between;align-items:center;padding:16px}'
    . '.items{display:flex;gap:24px;list-style:none;margin:0;padding:0}'
    . '.burger{display:none;width:32px;height:32px;padding:4px;border:0;background:transparent}'
    . '@media screen and (max-width:1023px){.navbar{display:none}.burger{display:block}}';
$page = static fn(string $css, string $main): string => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Acme</title><style>' . $css . '</style></head><body>' . $header . '<main>' . $main . '</main></body></html>';
$marker = static function (string $html): string {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    if (1 !== preg_match('/blocks-engine-native-navigation-toggle-[a-f0-9]{12}/', $markup, $match)) throw new RuntimeException('The header must project a native responsive navigation toggle: ' . substr($markup, 0, 2000));
    return $match[0];
};

$plain = $marker($page($base, '<h1>About</h1>'));
$presets = $marker($page(
    $base . '.cta-presets{--preset-button:filled}'
        . '@container style(--preset-button: filled){.burger-wrap .burger,.cta-presets .cta{background:transparent}}'
        . '@media (prefers-reduced-motion:reduce){.burger{transition:none}}',
    '<h1>Work</h1><div class="cta-presets"><a class="cta" href="/contact/">Contact</a></div>'
));
if ($plain !== $presets) throw new RuntimeException("Unreachable style-query and empty conditional rules must not change the toggle marker: {$plain} vs {$presets}");

// A style query an ancestor does satisfy still changes the burger.
$reached = $marker($page(
    $base . '.burger-wrap{--preset-button:filled}@container style(--preset-button: filled){.burger-wrap .burger{background:transparent}}',
    '<h1>Work</h1>'
));
if ($plain === $reached) throw new RuntimeException('A style query an ancestor satisfies must keep its presentation in the toggle marker.');

fwrite(STDOUT, "navigation-toggle-marker-unreachable-style-query: ok\n");
