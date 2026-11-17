<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Neutral reproduction from #6329: a custom element whose only semantics are a
// named landmark role is a labelled section, not a widget.
$html = (string) file_get_contents(__DIR__ . '/fixtures/landmark-custom-element-gallery.html');

$fail = static function (string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$flatten = static function (array $blocks) use (&$flatten): array {
    $all = array();
    foreach ( $blocks as $block ) {
        $all[] = $block;
        array_push($all, ...$flatten($block['innerBlocks'] ?? array()));
    }
    return $all;
};
$landmarkGroups = static function (array $blocks) use ($flatten): array {
    return array_values(array_filter($flatten($blocks), static fn (array $block): bool => 'core/group' === ($block['blockName'] ?? '')
        && 'Product gallery' === ($block['attrs']['ariaLabel'] ?? null)));
};

$result = (new HtmlTransformer())->transform($html)->toArray();
if ( array() !== $result['fallbacks'] ) {
    $fail('a named region custom element must lower without fallbacks, got ' . json_encode($result['fallbacks']));
}
$outer = $result['blocks'][0] ?? array();
if ( 'core/group' !== ($outer['blockName'] ?? '')
    || 'section' !== ($outer['attrs']['tagName'] ?? null)
    || 'Product gallery' !== ($outer['attrs']['ariaLabel'] ?? null)
    || 'MediaGallery-template--28181908357182__main' !== ($outer['attrs']['anchor'] ?? null)
) {
    $fail('the outer block must be the host core/group with tagName section and ariaLabel "Product gallery", got ' . json_encode($outer['attrs'] ?? null));
}
$images = array_filter($flatten($result['blocks']), static fn (array $block): bool => 'core/image' === ($block['blockName'] ?? '')
    && str_contains((string) ($block['attrs']['url'] ?? ''), '/media/download_2ef666fd-c0fa-4964-9e92-13bea434684a-11.webp'));
if ( array() === $images ) {
    $fail('the gallery image must stay an editable core/image');
}
$markup = (string) json_encode($result['blocks'], JSON_UNESCAPED_SLASHES);
if ( str_contains($markup, 'core/html') || ! str_contains((string) ($outer['innerHTML'] ?? ''), '<section') || ! str_contains((string) ($outer['innerHTML'] ?? ''), 'aria-label="Product gallery"') ) {
    $fail('the landmark must be saved as a labelled section group');
}

// Other landmark roles keep their matching tag; aria-labelledby supplies the name.
foreach ( array( 'complementary' => 'aside', 'navigation' => 'nav' ) as $role => $tag ) {
    $source = str_replace('role="region" aria-label="Product gallery"', 'role="' . $role . '" aria-labelledby="GalleryHeading"', $html);
    $source = str_replace('<main>', '<main><h2 id="GalleryHeading">Product gallery</h2>', $source);
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $groups = $landmarkGroups($result['blocks']);
    if ( array() !== $result['fallbacks'] || 1 !== count($groups) || $tag !== ($groups[0]['attrs']['tagName'] ?? null) ) {
        $fail("a named {$role} custom element must lower to a labelled {$tag} group");
    }
}

// Widget roles keep today's behaviour: never lowered as a labelled landmark.
foreach ( array( 'tablist', 'dialog', 'slider' ) as $role ) {
    $result = (new HtmlTransformer())->transform(str_replace('role="region"', 'role="' . $role . '"', $html))->toArray();
    if ( array() !== $landmarkGroups($result['blocks']) ) {
        $fail("widget role {$role} must not lower to a labelled landmark group");
    }
    if ( 'dialog' !== $role && str_contains((string) json_encode($result['blocks']), 'core/image') ) {
        $fail("widget role {$role} must stay on the preservation path");
    }
}

// Runtime evidence on a landmark host still keeps it on the fallback path.
foreach ( array(
    'event handler' => str_replace('role="region"', 'role="region" onclick="openGallery()"', $html),
    'runtime definition' => str_replace('</body>', '<script>customElements.define("media-gallery", class extends HTMLElement { connectedCallback() { this.dataset.ready = "true"; } });</script></body>', $html),
    'interactivity directive' => str_replace('role="region"', 'role="region" data-wp-interactive="gallery"', $html),
    'unnamed region' => str_replace(' aria-label="Product gallery"', '', $html),
) as $name => $source ) {
    $result = (new HtmlTransformer())->transform($source)->toArray();
    if ( array() === $result['fallbacks'] || array() !== $landmarkGroups($result['blocks']) ) {
        $fail("landmark host with {$name} must still fall back");
    }
}

echo "Landmark custom element group tests passed\n";
