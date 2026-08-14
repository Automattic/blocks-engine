<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};
$withoutDurations = static function (array $value) use (&$withoutDurations): array {
    foreach ( $value as $key => &$item ) {
        if ( 'transform_duration_ms' === $key ) {
            unset($value[$key]);
            continue;
        }
        if ( is_array($item) ) {
            $item = $withoutDurations($item);
        }
    }
    unset($item);

    return $value;
};

$css = ':root{--brand:#123456}.card{display:grid;color:var(--brand)}.card img{aspect-ratio:4/3;object-fit:cover}';
$options = array('static_css' => $css, 'skip_author_stylesheet_materialization' => true);
$pages = array(
    '<main class="card"><h1>First</h1><img src="first.jpg" alt="First"></main>',
    '<main class="card"><h1>Second</h1><img src="second.jpg" alt="Second"></main>',
);
$cache = new HtmlTransformerAnalysisCache();

foreach ( $pages as $html ) {
    $shared = (new HtmlTransformer(analysisCache: $cache))->transform($html, $options)->toArray();
    $isolated = (new HtmlTransformer())->transform($html, $options)->toArray();
    $assert(
        $withoutDurations($isolated) === $withoutDurations($shared),
        'Shared stylesheet analysis must preserve the isolated transform output.'
    );
}

$assert(1 === $cache->styleBuilds, 'Identical static and inline CSS must be analyzed once across fresh page transformers.');
$assert(1 === $cache->authorSelectorBuilds, 'Identical author selectors must be parsed once across fresh page transformers.');

fwrite(STDOUT, "HTML transformer shared analysis cache passed\n");
