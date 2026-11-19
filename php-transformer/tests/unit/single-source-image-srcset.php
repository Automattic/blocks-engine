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

// Loading hints and a source-less picture preserve metadata but introduce no
// responsive candidates. Exercise the public transform's actual metadata shape.
foreach (array(
    '<img src="hinted.jpg" loading="lazy" alt="Portrait">',
    '<img src="hinted.jpg" fetchpriority="high" alt="Portrait">',
    '<picture><img src="hinted.jpg" loading="lazy" alt="Portrait"></picture>',
) as $index => $html) {
    $hinted = (new HtmlTransformer())->transform($html)->toArray()['blocks'][0];
    if (!isset($hinted['attrs']['metadata']['blocksEngineImageSelection'])) {
        $fail('the fixture must exercise selection metadata from the public transform');
    }
    $hintedUrl = 'https://example.test/wp-content/uploads/hinted-' . $index . '.jpg';
    $hintedMarkup = str_replace('src="hinted.jpg"', 'src="' . $hintedUrl . '"', (string) $hinted['innerHTML']);
    if ($hintedMarkup !== $render($hintedMarkup, $hinted)) {
        $fail('single-source loading and picture markup must remain unchanged');
    }
    if (false !== $srcset(true, '<img src="' . $hintedUrl . '"/>')) {
        $fail('loading hints and source-less pictures must not acquire attachment renditions');
    }
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
$picture = (new HtmlTransformer())->transform('<picture><source media="(max-width:600px)" srcset="small.jpg"><img src="large.jpg" alt="Portrait"></picture>')->toArray()['blocks'][0];
$pictureUrl = 'https://example.test/wp-content/uploads/large.jpg';
$render(str_replace('src="large.jpg"', 'src="' . $pictureUrl . '"', (string) $picture['innerHTML']), $picture);
if (true !== $srcset(true, '<img src="' . $pictureUrl . '"/>')) {
    $fail('authored picture candidates must remain owned by the selection renderer');
}
echo "Single-source image srcset passed\n";
