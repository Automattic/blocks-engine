<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Two independent stylesheets must not replay responsive defaults at each
// authored position. Exercise the actual emitted selectors against the markup.
foreach ( array( true, false ) as $authoredCap ) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => '<html><head><link rel="stylesheet" href="a.css"><link rel="stylesheet" href="b.css"></head><body><header><div class="logo"><a href="/"><img src="logo.png" width="1861" height="283" alt="Logo"></a></div></header><main><img src="plain.png" alt="Plain"><p>Hi</p></main></body></html>',
            'a.css' => $authoredCap ? '.logo a img{max-width:165px;object-fit:contain;display:inline-block}' : '',
            'b.css' => '.logo a img{opacity:.9;transition:opacity .3s;z-index:1}',
        ),
    ))->toArray();
    $assets = array_column($result['assets'], null, 'path');
    foreach ( array( 'a.css', 'b.css' ) as $path ) {
        preg_match_all('/[^{}]+> a:not\([^)]+\) > img\{([^}]+)\}/', $assets[$path]['content'] ?? '', $bridges);
        if ( 'b.css' === $path && array() === $bridges[1] ) {
            throw new RuntimeException('Missing linked-image companion');
        }
        foreach ( $bridges[1] as $bridge ) {
            if ( str_contains($bridge, 'max-width:100%') ) {
                throw new RuntimeException('Companion replays an unauthored max-width');
            }
        }
    }
    $dom = new DOMDocument();
    @$dom->loadHTML($result['serialized_blocks']);
    foreach ( $dom->getElementsByTagName('img') as $image ) {
        $winner = '';
        $weight = -1;
        $baseMatches = false;
        foreach ( $result['assets'] as $asset ) {
            if ( 'css' !== ($asset['kind'] ?? '') ) continue;
            (new CssStylesheetTransformer())->transformStyleRules($asset['content'], static function (string $selectors, string $body) use ($image, &$winner, &$weight, &$baseMatches): string {
                if ( preg_match('/(?:^|;)max-width:([^;]+)/', $body, $cap) ) {
                    foreach ( CssStylesheetTransformer::splitSelectorList($selectors) ?? array() as $selector ) {
                        $parsed = CssSelectorMatcher::parse($selector);
                        if ( ! CssSelectorMatcher::matches($image, $parsed)['matches'] ) continue;
                        $specificity = CssSelectorMatcher::specificity($parsed);
                        if ( 0 === $specificity && '100%' === $cap[1] ) $baseMatches = true;
                        if ( $specificity >= $weight ) {
                            $winner = $cap[1];
                            $weight = $specificity;
                        }
                    }
                }
                return $selectors . '{' . $body . '}';
            });
        }
        $expected = $authoredCap && 'logo.png' === $image->getAttribute('src') ? '165px' : '100%';
        if ( ! $baseMatches || $expected !== $winner ) {
            throw new RuntimeException('Expected zero-specificity fallback and effective max-width ' . $expected . ', got ' . $winner);
        }
    }
}
echo "Linked image bridge default passed\n";
