<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The same footer on the home page and two nested routes uses one captured
// root-absolute sprite. Route depth must not change its replacement artwork.
$html = '<!doctype html><html><head><meta charset="utf-8"><title>Page</title><style>footer svg{width:32px;height:32px}</style></head><body>'
    . '<header><nav><a href="/">Home</a> <a href="/about/">About</a> <a href="/contact/">Contact</a></nav></header>'
    . '<main><h1>Page</h1><p>Page body.</p></main>'
    . '<footer><nav class="social"><a href="https://example.com/profile" aria-label="Profile"><div><svg viewBox="0 0 64 64"><use xlink:href="/svg/social.svg#ig"></use></svg></div></a></nav></footer>'
    . '</body></html>';
$files = array_fill_keys(array('index.html', 'about/index.html', 'contact/index.html'), $html);
$files['svg/social.svg'] = '<svg xmlns="http://www.w3.org/2000/svg"><symbol id="ig" viewBox="0 0 64 64"><circle cx="32" cy="32" r="20" fill="currentColor"/></symbol></svg>';
$fixtures = array();
foreach ( array('index.html', 'about/index.html', 'contact/index.html') as $route ) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => $route, 'files' => $files))->toArray();
    $css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'text/css' === ($asset['mime_type'] ?? '')), 'content'));
    preg_match_all('/<!--\s*wp:navigation-link\s+(\{.*?\})\s*\/?-->/s', $result['serialized_blocks'], $matches);
    $attrs = array();
    foreach ( $matches[1] as $json ) {
        $link = json_decode($json, true);
        if ( 'Profile' === ($link['label'] ?? '') ) $attrs = $link;
    }
    // Core navigation-link's dynamic render surface, with all compiled CSS.
    $candidate = '<footer><nav class="wp-block-navigation social"><ul class="wp-block-navigation__container"><li class="wp-block-navigation-item wp-block-navigation-link '
        . htmlspecialchars($attrs['className'] ?? '', ENT_QUOTES) . '"><a class="wp-block-navigation-item__content" href="https://example.com/profile"><span class="wp-block-navigation-item__label">Profile</span></a></li></ul></nav></footer>';
    $fixtures[$route] = array('candidate' => $candidate, 'css' => $css);
}
// Let the browser measure every route, including failing routes on the base.
if ( $output = getenv('ROOT_ABSOLUTE_SPRITE_FIXTURE') ) {
    file_put_contents($output, json_encode($fixtures, JSON_THROW_ON_ERROR));
} else {
    foreach ( $fixtures as $route => $fixture ) {
        $css = rawurldecode($fixture['css']);
        if ( ! str_contains($css, 'background-image:url("data:image/svg+xml,') || ! str_contains($css, '<circle cx="32" cy="32" r="20"') ) {
            throw new RuntimeException($route . ': missing resolved sprite background.');
        }
        if ( ! preg_match('/navigation-link-icon-[^{]+\{[^}]*font-size:0;line-height:0;color:transparent/', $css) ) {
            throw new RuntimeException($route . ': icon label is not hidden.');
        }
    }
    echo "Root-absolute sprite links contract passed.\n";
}
