<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A padded single-link wrapper needs actual background paint to become a button.
$cases = array(
    '0 0' => false,
    'none 0 0/auto repeat scroll' => false,
    'left top no-repeat rgba(0,0,0,0)' => false,
    'center/cover fixed padding-box content-box' => false,
    'none, none 0 0/auto transparent' => false,
    '0 0 #0000' => false,
    '0 0 #12345600' => false,
    '0 0 hsl(120 50% 50% / 0%)' => false,
    'inherit' => false,
    '#036' => true,
    '0 0 #036' => true,
    'none, none 0 0 rebeccapurple' => true,
    'left top rgba(0, 0, 0, .5)' => true,
    'url(a.png) 0 0 no-repeat' => true,
    'linear-gradient(90deg, red, blue) 0 0/cover' => true,
    'none, radial-gradient(red, blue) center/cover transparent' => true,
    'image-set(url(a.png) 1x, url(b.png) 2x) 0 0' => true,
    'var(--fill) 0 0' => true,
);
$failures = 0;
foreach ( $cases as $background => $paints ) {
    $html = '<!doctype html><html><head><style>div{margin:0;padding:0;border:0;background:0 0;position:relative}.t{padding:14px 10px;background:' . $background . '}</style></head><body><article><div class="t"><a href="/post">The Haunted Listing</a></div><p>Excerpt</p></article></body></html>';
    $result = (new ArtifactCompiler())->compile(array('files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $html))))->toArray();
    if ( str_contains($result['serialized_blocks'], 'wp:button') !== $paints ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: background:' . $background . ' should remain a ' . ($paints ? 'button' : 'link') . PHP_EOL);
    }
}
exit($failures > 0 ? 1 : 0);
