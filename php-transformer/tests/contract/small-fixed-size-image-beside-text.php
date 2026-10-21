<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * Contract coverage for issue #3228.
 *
 * A small image whose used size is fixed -- by numeric width/height
 * attributes, by attributes written as `42px` (read with the HTML rules for
 * parsing dimension values), or by author CSS on wrappers the image fills at
 * 100% -- beside text is an icon/avatar lockup, not a media-and-text layout.
 * It stays a sized core/image instead of an unsized core/media-text whose
 * image then renders at the uploaded file's natural size.
 */

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (! $condition) {
        throw new RuntimeException($message . ('' === $detail ? '' : "\n" . substr($detail, 0, 3000)));
    }
};

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
$assert(is_string($png), 'The 1x1 PNG fixture decodes.');

$fixtures = array(
    'numeric width/height attributes' => array(
        'css' => '',
        'html' => '<div style="display:flex"><div style="margin-right:12px"><img src="media/a.png" width="42" height="42" alt="App"></div><div><p>Call</p></div></div>',
        'size' => '42px',
    ),
    'px width/height attribute values' => array(
        'css' => '',
        'html' => '<div class="bar" style="display:flex"><div style="margin-right:12px"><img src="media/a.png" width="42px" height="42px" alt="App"></div><div><p>Call</p></div></div>',
        'size' => '42px',
    ),
    'author CSS on sizing wrappers' => array(
        'css' => '.av{display:inline-block;width:36px;height:36px}.av img{display:block;width:100%;height:100%;border-radius:50%}.row{display:flex;padding:24px}',
        'html' => '<div class="row"><div class="av"><div class="core"><img src="media/a.png" alt="P u"></div></div><div><p>Great app.</p><p>Reviewer name</p></div></div>',
        'size' => '36px',
    ),
);

foreach ($fixtures as $name => $fixture) {
    $html = '<!doctype html><html><head><title>t</title><style>' . $fixture['css'] . '</style></head><body><main>'
        . $fixture['html'] . '</main></body></html>';
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array( 'index.html' => $html, 'media/a.png' => $png ),
    ))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');

    $assert('' !== $markup, $name . ': the page compiles to serialized blocks.');
    $assert(! str_contains($markup, 'wp:media-text'), $name . ': a small fixed-size image beside text is not lowered to core/media-text.', $markup);

    $assert(1 === preg_match('/<!-- wp:image (\{.*?\}) -->(.*?)<!-- \/wp:image -->/s', $markup, $match), $name . ': the image is emitted as a core/image block.', $markup);
    $attrs = json_decode($match[1], true);
    $assert(is_array($attrs), $name . ': the core/image block attributes decode.', $match[1]);
    $size = $fixture['size'];
    $sizedByAttributes = $size === ($attrs['width'] ?? null) && $size === ($attrs['height'] ?? null);
    $sizedInline = 1 === preg_match('/<img\b[^>]*\bstyle="[^"]*\bwidth:\s*' . preg_quote($size, '/') . '[^"]*"/', $match[2])
        && 1 === preg_match('/<img\b[^>]*\bstyle="[^"]*\bheight:\s*' . preg_quote($size, '/') . '[^"]*"/', $match[2]);
    $assert($sizedByAttributes || $sizedInline, $name . ': the emitted image keeps its ' . $size . ' x ' . $size . ' source size.', $markup);
}

echo "small fixed-size image beside text ok\n";
