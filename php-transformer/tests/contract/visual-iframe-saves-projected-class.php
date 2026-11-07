<?php
declare(strict_types=1);

/**
 * A visual iframe saves the classes its block attributes carry.
 *
 * Device-scoped rules reach the iframe through a projection marker class the
 * block attributes record. The saved iframe must carry the same class, or the
 * rule matches nothing and the block's save output disagrees with its attributes.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$body = '<main><div class="slot"><iframe class="frame" src="https://forms.example.test/share" title="Signup" width="398" height="497"></iframe></div></main>';
$scope = static fn (string $device): string => ':where([data-blocks-engine-capture-device-document="' . $device . '"]) ';
$html = '<!doctype html><html><head><style data-blocks-engine-capture-device-style="desktop">' . $scope('desktop') . '.frame{position:absolute}</style></head><body>'
    . '<div data-blocks-engine-capture-device-document="desktop">' . $body . '</div>'
    . '<div data-blocks-engine-capture-device-document="mobile">' . $body . '</div></body></html>';

$plan = (new ArtifactCompiler())->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ))->toArray()['source_reports']['wordpress_site_plan'];
preg_match_all('/<!-- wp:\S+\/visual-iframe (\{.*?\}) -->(<iframe[^>]*>)/', (string) $plan['pages'][0]['canonical_block_markup'], $frames, PREG_SET_ORDER);
$css = implode('', array_map(static fn (array $asset): string => is_string($asset['content'] ?? null) ? $asset['content'] : '', $plan['assets']));

$marker = '';
$saved = 2 === count($frames);
foreach ( $frames as $frame ) {
    $className = (string) (json_decode($frame[1], true)['className'] ?? '');
    $saved = $saved && str_contains($frame[2], 'class="' . $className . '"');
    if ( 1 === preg_match('/blocks-engine-attribute-[a-f0-9]+-0/', $className, $match) ) {
        $marker = $match[0];
    }
}

foreach ( array(
    'every saved iframe carries its block className' => $saved,
    'the desktop rule reaches the iframe through its marker' => '' !== $marker && 1 === preg_match('/\.' . preg_quote($marker, '/') . '\)[^{}]*\{position:absolute\}/', $css),
) as $label => $ok ) {
    if ( ! $ok ) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "Visual iframe projected class contract passed\n";
