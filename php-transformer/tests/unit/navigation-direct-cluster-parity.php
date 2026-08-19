<?php
declare(strict_types=1);

/**
 * Contract for direct div navigation clusters hoisted beside a brand.
 *
 * The source landmark remains a nav carrier while its direct div link cluster
 * becomes a nested core/navigation. Authored landmark selectors such as
 * `header nav` then also match that generated inner nav, duplicating the
 * landmark's margin, padding, and max-width. Core also ignores mixed per-link
 * colour support on core/navigation-link at render time.
 *
 * Mark only this direct-div shape. Restore the source div's default box on the
 * generated host, and replay each already-resolved link colour against core's
 * runtime li > anchor markup. List navigation keeps its existing contracts.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

/** @return array{markup: string, after: string} */
$transform = static function (string $clusterTag = 'div', string $clusterBox = ''): array {
    $html = '<style>'
        . 'header.site-header nav{max-width:1200px;margin:0 auto;padding:18px 32px;display:flex;align-items:baseline}'
        . '.brand{margin-right:auto}'
        . '.links{display:flex;gap:24px;' . $clusterBox . '}'
        . '.links a{color:#231f1d}'
        . '.links a[aria-current="page"],.links a.reserve{color:#6e3b32}'
        . '</style>'
        . '<header class="site-header"><nav aria-label="Primary">'
        . '<a class="brand" href="/"><span>Harbor</span><span>Old Town</span></a>'
        . '<' . $clusterTag . ' class="links">'
        . '<a href="/" aria-current="page">Home</a><a href="/menu/">Menu</a>'
        . '<a class="reserve" href="/reserve/">Reservations</a>'
        . '</' . $clusterTag . '></nav></header>';

    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $after = '';
    foreach ( (is_array($result['assets'] ?? null) ? $result['assets'] : array()) as $asset ) {
        if ( is_array($asset) && 'after-author' === (string) ($asset['stylesheet_placement'] ?? '') ) {
            $after .= (string) ($asset['content'] ?? '');
        }
    }

    return array(
        'markup' => (string) ($result['serialized_blocks'] ?? ''),
        'after' => $after,
    );
};

$direct = $transform();
$assert(
    str_contains($direct['markup'], 'blocks-engine-brand-navigation-carrier')
        && str_contains($direct['markup'], 'blocks-engine-direct-navigation'),
    'direct div cluster marks the carrier and generated navigation host',
    $direct['markup']
);
$assert(
    str_contains($direct['after'], 'blocks-engine-direct-navigation-reset-margin{margin:0}')
        && str_contains($direct['after'], 'blocks-engine-direct-navigation-reset-padding{padding:0}')
        && str_contains($direct['after'], 'blocks-engine-direct-navigation-reset-max-width{max-width:none}'),
    'direct div cluster restores source div box defaults after author CSS',
    $direct['after']
);
$assert(
    2 <= substr_count($direct['after'], 'color:#6e3b32')
        && str_contains($direct['after'], 'color:#231f1d'),
    'mixed authored link colours are replayed individually',
    $direct['after']
);
$assert(
    1 === preg_match('/\.wp-block-navigation-item\.blocks-engine-direct-navigation-link-color-[a-f0-9]{12}>\.wp-block-navigation-item__content\{color:#6e3b32\}/', $direct['after']),
    'colour bridge targets the runtime navigation item class and child anchor',
    $direct['after']
);
$assert(
    ! str_contains($direct['after'], '!important'),
    'direct cluster compatibility uses cascade specificity without important',
    $direct['after']
);

$ownedMargin = $transform('div', 'margin-left:auto;');
$assert(
    ! str_contains($ownedMargin['markup'], 'blocks-engine-direct-navigation-reset-margin')
        && str_contains($ownedMargin['markup'], 'blocks-engine-direct-navigation-reset-padding')
        && str_contains($ownedMargin['markup'], 'blocks-engine-direct-navigation-reset-max-width'),
    'cluster-owned margin suppresses only the margin reset',
    $ownedMargin['markup']
);

$list = $transform('ul');
$assert(
    ! str_contains($list['markup'], 'blocks-engine-direct-navigation')
        && ! str_contains($list['after'], 'blocks-engine-direct-navigation'),
    'list navigation does not enter the direct div compatibility path',
    $list['markup'] . $list['after']
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Navigation direct cluster parity contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Navigation direct cluster parity contract passed: {$passes} assertions\n";
exit(0);
