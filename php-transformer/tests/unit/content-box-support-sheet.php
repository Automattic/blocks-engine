<?php
declare(strict_types=1);

/**
 * Content-box restatements are support CSS (Automattic/blocks-engine#3565).
 *
 * Core's group stylesheet resets groups to `border-box`, so a sized and padded
 * source box is restated as `box-sizing:content-box`. A host that converts
 * content inside an existing theme keeps the theme's own stylesheet as the
 * author CSS and installs only the support stylesheets, so the restatement is
 * emitted in the after-author support stylesheet, with the projected selector
 * inside the same at-rule conditions, and not in the rewritten author
 * stylesheet. An after-author rule comes after every author rule, so a source
 * rule that sets `box-sizing` under a narrower condition, and won over the
 * restated rule in the authored cascade, is restated after it.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? "\n    " . substr($detail, 0, 800) : '' ) . PHP_EOL);
};

/** Stylesheet text by placement; the after-author sheet is the engine-support one. */
$sheets = static function (array $assets): array {
    $by = array( 'author' => '', 'after-author' => '' );
    foreach ( $assets as $asset ) {
        $placement = (string) ( $asset['stylesheet_placement'] ?? '' );
        if ( 'after-author' === $placement && 'engine-support' !== ( $asset['source'] ?? '' ) ) {
            continue;
        }
        if ( 'author' === $placement || ( '' === $placement && 'css' === ( $asset['kind'] ?? '' ) && str_ends_with((string) ( $asset['path'] ?? '' ), '.css') ) ) {
            $by['author'] .= ( $asset['content'] ?? '' ) . "\n";
        } elseif ( isset($by[ $placement ]) ) {
            $by[ $placement ] .= ( $asset['content'] ?? '' ) . "\n";
        }
    }
    return array_map(static fn (string $css): string => (string) preg_replace('/\s+/', ' ', $css), $by);
};
$transform = static fn (string $html, string $css, array $options = array()): array => $sheets(
    ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ) + $options)->toArray()['assets'] ?? array()
);
$compile = static fn (string $html): array => $sheets(
    ( new ArtifactCompiler() )->compile(array( 'files' => array( array( 'path' => 'index.html', 'kind' => 'html', 'content' => $html ) ) ))->toArray()['assets'] ?? array()
);

$header = '<header class="site-header"><div class="site-header__inner"><a class="site-title" href="/">Paws Whiskers</a><nav class="site-header__nav"><ul class="site-header__menu"><li><a href="/">Home</a></li><li><a href="/shop/">Shop</a></li></ul></nav></div></header>';
$headerCss = '.site-header .site-header__inner{display:flex;justify-content:space-between;max-width:1240px;margin:0 auto;padding:1.1rem 2rem}'
    . '@media (max-width: 782px){.site-header__inner{padding:.9rem 1.25rem;flex-wrap:wrap}}'
    . '.site-header__menu{display:flex;gap:1rem;list-style:none;margin:0;padding:0}';
$box = '<main><div class="box"><p>Copy</p></div></main>';

// Each case: how the sheets are produced, then patterns (whitespace collapsed)
// that must or must not match the after-author and author sheets.
$cases = array(
    'pet-store header' => array(
        'sheets' => static fn (): array => $transform($header, $headerCss),
        'after' => array(
            '/(?:^|\}) ?\.site-header \.site-header__inner ?\{ ?box-sizing:content-box ?\}/',
            '/@media \(max-width: 782px\) ?\{ ?\.site-header__inner ?\{ ?box-sizing:content-box ?\} ?\}/',
        ),
        'not_after' => array( '/\.site-header__menu ?\{ ?box-sizing/' ),
        'author' => array( '/\.site-header \.site-header__inner ?\{display:flex;justify-content:space-between;max-width:1240px;padding:1\.1rem 2rem\}/' ),
        'not_author' => array( '/box-sizing/' ),
    ),
    'pet-store header with a host block gap' => array(
        'sheets' => static fn (): array => $transform($header, $headerCss, array( 'host_block_gap' => '1.5rem' )),
        'after' => array(
            '/(?:^|\}) ?\.site-header \.site-header__inner ?\{ ?box-sizing:content-box ?\}/',
            '/@media \(max-width: 782px\) ?\{ ?\.site-header__inner ?\{ ?box-sizing:content-box ?\} ?\}/',
        ),
        'not_author' => array( '/box-sizing/' ),
    ),
    'a later narrower border-box rule is restated after the restatement' => array(
        'sheets' => static fn (): array => $transform($header, $headerCss . '@media (max-width: 600px){.site-header .site-header__inner{box-sizing:border-box}}'),
        'after' => array( '/\.site-header \.site-header__inner ?\{ ?box-sizing:content-box ?\}.*@media \(max-width: 600px\) ?\{ ?\.site-header \.site-header__inner ?\{ ?box-sizing:border-box ?\} ?\}/' ),
        'author' => array( '/@media \(max-width: 600px\) ?\{ ?\.site-header \.site-header__inner ?\{ ?box-sizing:border-box ?\} ?\}/' ),
        'not_author' => array( '/box-sizing:content-box/' ),
    ),
    'a narrower border-box rule of lower specificity that lost everywhere is not restated' => array(
        'sheets' => static fn (): array => $transform($header, '.site-header .site-header__inner{max-width:1240px;padding:1.1rem 2rem}@media (max-width: 600px){.site-header__inner{box-sizing:border-box}}'),
        'after' => array( '/(?:^|\}) ?\.site-header \.site-header__inner ?\{ ?box-sizing:content-box ?\}/' ),
        'not_after' => array( '/border-box/' ),
        'author' => array( '/@media \(max-width: 600px\) ?\{ ?\.site-header__inner ?\{ ?box-sizing:border-box ?\} ?\}/' ),
    ),
    'a narrower border-box rule keeps beating the wider restatement it beat in the source' => array(
        'sheets' => static fn (): array => $transform($header, $headerCss . '@media (max-width: 600px){.site-header__inner{box-sizing:border-box}}'),
        'after' => array( '/@media \(max-width: 782px\) ?\{ ?\.site-header__inner ?\{ ?box-sizing:content-box ?\} ?\}.*@media \(max-width: 600px\) ?\{ ?\.site-header__inner ?\{ ?box-sizing:border-box ?\} ?\}/' ),
    ),
    'an earlier narrower border-box rule keeps its authored position ahead of the restatement it lost to' => array(
        'sheets' => static fn (): array => $transform($header, '@media (max-width: 600px){.site-header .site-header__inner{box-sizing:border-box}}' . $headerCss),
        'after' => array( '/@media \(max-width: 600px\) ?\{ ?\.site-header \.site-header__inner ?\{ ?box-sizing:border-box ?\} ?\}.*\.site-header \.site-header__inner ?\{ ?box-sizing:content-box ?\}/' ),
        'not_after' => array( '/\.site-header \.site-header__inner ?\{ ?box-sizing:content-box ?\}.*border-box/' ),
    ),
    'a rule split around its margins restates once, at the inner selector' => array(
        'sheets' => static fn (): array => $transform($box, '.box{width:600px;padding:20px;margin:0 auto}'),
        'after' => array( '/(?:^|\}) ?\.box ?\{ ?box-sizing:content-box ?\}/' ),
        'author' => array( '/\.box ?\{width:600px;padding:20px\}/' ),
        'not_author' => array( '/box-sizing/' ),
    ),
    'a comment ahead of the rule stays with the author rule' => array(
        'sheets' => static fn (): array => $transform($box, "/* panel */\n.box{width:600px;padding:20px}"),
        'after' => array( '/(?:^|\}) ?\.box ?\{ ?box-sizing:content-box ?\}/' ),
        'not_after' => array( '#/\*#' ),
        'not_author' => array( '/box-sizing/' ),
    ),
    'an authored universal border-box reset keeps every box border-box' => array(
        'sheets' => static fn (): array => $transform($box, '*,*::before,*::after{box-sizing:border-box}.box{width:600px;padding:20px}'),
        'not_after' => array( '/content-box/' ),
        'not_author' => array( '/content-box/' ),
    ),
    'a box that is not sized and padded is not restated' => array(
        'sheets' => static fn (): array => $transform($box, '.box{padding:20px;margin:0 auto}'),
        'not_after' => array( '/box-sizing/' ),
        'not_author' => array( '/box-sizing/' ),
    ),
    'a box that already carries core\'s group class renders border-box in the source and is not restated' => array(
        'sheets' => static fn (): array => $transform('<!-- wp:column --><div class="wp-block-column"><div class="wp-block-group box"><p>Coco Rope Tug</p></div></div><!-- /wp:column -->', '.box{height:100%;padding:1.5rem;border:1px solid #e6e0d6}'),
        'not_after' => array( '/content-box/' ),
        'not_author' => array( '/content-box/' ),
    ),
    'a core-shaped authored class is restated only through its importer class' => array(
        'sheets' => static fn (): array => $compile('<!doctype html><html><head><style>.wp-block-x{height:0;width:0;overflow:hidden;padding:1px}</style></head><body><div><p>Hi</p><div class="wp-block-x"><p>Hidden</p></div></div></body></html>'),
        'after' => array( '/\.blocks-engine-source-class-[a-f0-9]+-0 ?\{ ?box-sizing:content-box ?\}/' ),
        'not_after' => array( '/(?:^|\}) ?\.wp-block-x ?\{ ?box-sizing/' ),
    ),
    'a stylesheet asset keeps its media around the restatement' => array(
        'sheets' => static fn (): array => $compile('<!doctype html><html><head><style media="screen and (min-width: 600px)">.box{width:600px;padding:20px}</style></head><body>' . $box . '</body></html>'),
        'after' => array( '/@media screen and \(min-width: 600px\) ?\{ ?\.box ?\{ ?box-sizing:content-box ?\} ?\}/' ),
        'not_author' => array( '/box-sizing/' ),
    ),
);

foreach ( $cases as $name => $case ) {
    $by = ( $case['sheets'] )();
    foreach ( array( 'after' => 'after-author', 'author' => 'author' ) as $key => $sheet ) {
        foreach ( $case[ $key ] ?? array() as $pattern ) {
            $assert(1 === preg_match($pattern . 's', $by[ $sheet ]), "{$name}: the {$sheet} sheet matches {$pattern}", $by[ $sheet ]);
        }
        foreach ( $case[ 'not_' . $key ] ?? array() as $pattern ) {
            $assert(1 !== preg_match($pattern . 's', $by[ $sheet ]), "{$name}: the {$sheet} sheet does not match {$pattern}", $by[ $sheet ]);
        }
    }
}

if ( $failures > 0 ) {
    fwrite(STDERR, "content-box support sheet: {$failures} failed, {$passes} passed\n");
    exit(1);
}
echo "content-box support sheet: {$passes} passed\n";
