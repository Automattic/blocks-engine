<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

$image = '<img src="/x.webp" alt="I">';
$href = 'https://i.com/x';
// Issue #4433's bisected rows, plus the source shape that exposed it.
$cases = array(
    'bare link' => array('', $image, '', ''),
    'target' => array(' target="_blank"', $image, '_blank', ''),
    'data attribute' => array(' data-foo=""', $image, '', ''),
    'classes' => array(' class="a b c"', $image, '', ''),
    'zero tabindex' => array(' tabindex="0"', $image, '', ''),
    'Squarespace' => array(
        ' class="sqs-block-image-link" tabindex="0" target="_blank" rel="noopener noreferrer"',
        '<img src="/x.webp" srcset="/x-small.webp 300w, /x.webp 1000w" sizes="50vw" width="1000" height="1000" alt="I"><div class="fluidImageOverlay"></div>',
        '_blank',
        'noopener noreferrer',
    ),
);
foreach ($cases as $label => [$attributes, $content, $target, $rel]) {
    $html = '<main><a href="' . $href . '"' . $attributes . '>' . $content . '</a></main>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $markup = (string) ((new WordPressSitePlan())->fromCompilerResult($result)['pages'][0]['canonical_block_markup'] ?? '');
    $assert(! str_contains($markup, 'responsive-media'), $label . ': no responsive-media block');
    preg_match_all('/<!-- wp:image (\{.*?\}) -->\s*(.*?)<!-- \/wp:image -->/s', $markup, $images, PREG_SET_ORDER);
    $assert(1 === count($images), $label . ': exactly one core/image');
    $attrs = json_decode($images[0][1], true, 512, JSON_THROW_ON_ERROR);
    $assert('custom' === ($attrs['linkDestination'] ?? null), $label . ': custom image link');
    $dom = new DOMDocument();
    @$dom->loadHTML($images[0][2]);
    $link = $dom->getElementsByTagName('a')->item(0);
    $assert($link instanceof DOMElement && $href === $link->getAttribute('href'), $label . ': image keeps href');
    $assert($target === $link->getAttribute('target'), $label . ': image keeps target');
    $assert($rel === $link->getAttribute('rel'), $label . ': image keeps rel');
}

echo "Linked image tabindex tests passed\n";
