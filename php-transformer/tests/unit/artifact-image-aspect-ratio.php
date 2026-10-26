<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$rows = array(
    'inline intrinsic ratio' => array('', 'aspect-ratio:1073 / 734', array('width' => '1073px', 'height' => '734px')),
    'inline different ratio with cover' => array('', 'aspect-ratio:1 / 1;object-fit:cover', array('width' => '1073px', 'height' => '734px', 'scale' => 'cover')),
    'native crop promotion' => array('<style>img{aspect-ratio:1 / 1;object-fit:cover}</style>', '', array('aspectRatio' => '1/1', 'scale' => 'cover')),
    'ratio with a free axis' => array('', 'width:400px;height:auto;aspect-ratio:1 / 1', array('width' => '400px')),
);
foreach ( $rows as $name => list($head, $style, $expected) ) {
    $html = $head . '<main><section><p><img alt="chart" height="734" src="chart.png" width="1073" style="' . $style . '"></p><p>after</p></section></main>';
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'website/index.html',
        'files' => array('website/index.html' => $html),
    ))->toArray();
    $markup = $result['serialized_blocks'];
    if ( ! preg_match('/<!-- wp:image (.*?) -->/', $markup, $match) ) {
        throw new RuntimeException($name . ': expected a native image');
    }
    $attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    foreach ( $expected as $key => $value ) {
        if ( ($attrs[$key] ?? null) !== $value ) {
            throw new RuntimeException($name . ': lost native ' . $key);
        }
    }
    if ( isset($expected['aspectRatio']) && (isset($attrs['width']) || isset($attrs['height'])) ) {
        throw new RuntimeException($name . ': intrinsic dimensions must not override the native crop');
    }
    if ( isset($expected['scale']) && ! str_contains($markup, 'object-fit:cover') ) {
        throw new RuntimeException($name . ': lost painting image crop');
    }
    $document = new DOMDocument();
    @$document->loadHTML($markup);
    $figure = $document->getElementsByTagName('figure')->item(0);
    $image = $document->getElementsByTagName('img')->item(0);
    $leafRatio = false;
    foreach ( $result['source_reports']['wordpress_site_plan']['assets'] ?? array() as $asset ) {
        if ( 'css' !== ($asset['kind'] ?? '') ) continue;
        (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use ($name, $figure, $image, &$leafRatio): void {
            if ( ! preg_match('/(?:^|;)\s*aspect-ratio\s*:/', $body) ) return;
            foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                $parsed = CssSelectorMatcher::parse($selector);
                if ( CssSelectorMatcher::matches($figure, $parsed)['matches'] ) {
                    throw new RuntimeException($name . ': source image ratio must not size the figure: ' . $prelude);
                }
                $leafRatio = $leafRatio || CssSelectorMatcher::matches($image, $parsed)['matches'];
            }
        });
    }
    if ( 'ratio with a free axis' === $name && ! $leafRatio ) {
        throw new RuntimeException($name . ': ratio must survive on the painting image');
    }
}
echo "Artifact image aspect-ratio ownership passed\n";
