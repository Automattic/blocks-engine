<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// A plain <img src> decodes that one file at every width. Once materialized as
// an attachment, core's content filter would offer the attachment's generated
// renditions as srcset, and a narrow viewport would decode resized bytes the
// source never served (#6263). The theme runtime keeps the single src for an
// image the importer lowered, leaves its block markup untouched, and leaves
// core srcset in place for images it did not lower.
$fail = static function (string $message): void {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
};

$result = (new HtmlTransformer())->transform('<img src="portrait.jpg" alt="Portrait">')->toArray();
$markup = (string) $result['serialized_blocks'];
if (str_contains($markup, 'blocksEngineImageSelection')) {
    $fail('a plain image carries no selection metadata: ' . $markup);
}
$bootstrap = static function (string $html): string {
    $compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'portrait.jpg' => 'jpeg')))->toArray();
    foreach ($compiled['source_reports']['wordpress_site_plan']['writes'] ?? array() as $write) if ('functions.php' === $write['target_path']) return (string) $write['payload']['data'];
    return '';
};
if (!str_contains($bootstrap('<main><img src="portrait.jpg" alt="Portrait"></main>'), ThemeBootstrap::SINGLE_SOURCE_IMAGE_RUNTIME)) {
    $fail('the theme bootstrap must ship the single-source image runtime for a lowered image');
}
if (str_contains($bootstrap('<main><p>Text</p></main>'), ThemeBootstrap::SINGLE_SOURCE_IMAGE_RUNTIME)) {
    $fail('a site without lowered images ships no single-source image runtime');
}

// Run the shipped runtime with minimal WordPress stand-ins.
class WP_HTML_Tag_Processor
{
    private ?DOMElement $tag = null;
    private DOMDocument $dom;
    private int $cursor = -1;
    public function __construct(string $html) { $this->dom = new DOMDocument(); @$this->dom->loadHTML('<body>' . $html . '</body>'); }
    public function next_tag(string $name): bool
    {
        $all = iterator_to_array($this->dom->getElementsByTagName('*'));
        for ($i = $this->cursor + 1; $i < count($all); ++$i) {
            if (strtolower($name) === strtolower($all[$i]->nodeName)) { $this->cursor = $i; $this->tag = $all[$i]; return true; }
        }
        return false;
    }
    public function has_class(string $class): bool { return in_array($class, preg_split('/\s+/', (string) $this->tag?->getAttribute('class')) ?: array(), true); }
    public function get_attribute(string $name): ?string { return $this->tag?->hasAttribute($name) ? $this->tag->getAttribute($name) : null; }
}
function add_filter(string $name, callable $callback, int $priority = 10, int $arguments = 1): void { $GLOBALS['singleSourceFilters'][$name] = $callback; }
eval(ThemeBootstrap::SINGLE_SOURCE_IMAGE_RUNTIME);
$render = $GLOBALS['singleSourceFilters']['render_block_core/image'] ?? null;
$srcset = $GLOBALS['singleSourceFilters']['wp_img_tag_add_srcset_and_sizes_attr'] ?? null;
if (!is_callable($render) || !is_callable($srcset)) {
    $fail('the runtime must filter both the image render and core srcset addition');
}

// The importer binds the block to the attachment it materialized.
$url = 'https://example.test/wp-content/uploads/portrait.jpg';
$block = $result['blocks'][0];
$rendered = str_replace('src="portrait.jpg"', 'src="' . $url . '" class="wp-image-7"', (string) $block['innerHTML']);
if ($rendered !== $render($rendered, $block)) {
    $fail('the runtime leaves the rendered image markup unchanged');
}
if (false !== $srcset(true, '<img src="' . $url . '" alt="Portrait" class="wp-image-7" width="1100" height="1100"/>', 'the_content', 7)) {
    $fail('core must not add attachment renditions as srcset to a lowered single-source image');
}

// Loading hints do not turn a src-only image into a responsive family. Exercise
// the public transform so the metadata shape is the one emitted in production.
foreach (array('fetchpriority="high"', 'loading="lazy"', 'loading="eager" fetchpriority="low"') as $hints) {
    $hinted = (new HtmlTransformer())->transform('<img src="hinted.jpg" alt="Portrait" ' . $hints . '>')->toArray();
    $hintedBlock = $hinted['blocks'][0];
    if (!isset($hintedBlock['attrs']['metadata']['blocksEngineImageSelection'])) {
        $fail('authored loading hints must be carried by the public transform');
    }
    $hintedUrl = 'https://example.test/wp-content/uploads/hinted-' . md5($hints) . '.jpg';
    $hintedMarkup = str_replace('src="hinted.jpg"', 'src="' . $hintedUrl . '"', (string) $hintedBlock['innerHTML']);
    $render($hintedMarkup, $hintedBlock);
    if (false !== $srcset(true, '<img src="' . $hintedUrl . '" class="wp-image-10"/>')) {
        $fail('core must not add attachment renditions to a single-source image carrying ' . $hints);
    }
}

// A picture without sources is still a single-source image. Its metadata
// retains the picture wrapper, not a responsive family of attachment sizes.
$picture = (new HtmlTransformer())->transform('<picture><img src="picture.jpg" fetchpriority="high" alt="Portrait"></picture>')->toArray();
$pictureBlock = $picture['blocks'][0];
$selection = $pictureBlock['attrs']['metadata']['blocksEngineImageSelection'] ?? array();
if (!isset($selection['picture'], $selection['sources']) || array() !== $selection['sources']) {
    $fail('a source-less picture must retain its empty source selection metadata');
}
$pictureUrl = 'https://example.test/wp-content/uploads/picture.jpg';
$render(str_replace('src="picture.jpg"', 'src="' . $pictureUrl . '"', (string) $pictureBlock['innerHTML']), $pictureBlock);
if (false !== $srcset(true, '<img src="' . $pictureUrl . '" class="wp-image-11"/>')) {
    $fail('a source-less picture must not acquire attachment renditions');
}

// An image the importer did not lower (a block added in the editor) keeps core srcset.
$other = 'https://example.test/wp-content/uploads/other.jpg';
$render('<figure class="wp-block-image"><img src="' . $other . '" class="wp-image-8"/></figure>', array('blockName' => 'core/image', 'attrs' => array()));
if (true !== $srcset(true, '<img src="' . $other . '" class="wp-image-8"/>', 'the_content', 8)) {
    $fail('an image the importer did not lower keeps core srcset');
}
// An authored srcset family is selected by its own renderer, not declined here.
$family = 'https://example.test/wp-content/uploads/family.jpg';
$render('<figure class="wp-block-image blocks-engine-image"><img src="' . $family . '" class="wp-image-9"/></figure>', array('blockName' => 'core/image', 'attrs' => array('metadata' => array('blocksEngineImageSelection' => array('src' => $family, 'srcset' => $family . ' 1x')))));
if (true !== $srcset(true, '<img src="' . $family . '" class="wp-image-9"/>', 'the_content', 9)) {
    $fail('an authored srcset family is left to its selection renderer');
}
$responsivePicture = (new HtmlTransformer())->transform('<picture><source media="(max-width: 600px)" srcset="small.jpg"><img src="large.jpg" alt="Portrait"></picture>')->toArray();
$responsiveBlock = $responsivePicture['blocks'][0];
$responsiveUrl = 'https://example.test/wp-content/uploads/large.jpg';
$render(str_replace('src="large.jpg"', 'src="' . $responsiveUrl . '"', (string) $responsiveBlock['innerHTML']), $responsiveBlock);
if (true !== $srcset(true, '<img src="' . $responsiveUrl . '"/>')) {
    $fail('a picture with authored sources is left to its selection renderer');
}
echo "Single-source image srcset passed\n";
