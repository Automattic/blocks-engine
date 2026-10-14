<?php
declare(strict_types=1);

// Every standalone SVG document the transformer writes (a materialized image
// file or a data URI) must declare the namespaces it uses. Inline SVG gets
// them from the HTML parser, so source markup usually omits them; on its own,
// an undeclared `xlink:` prefix is an XML error and the browser shows a broken
// image instead of the icon.

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $label) use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        throw new RuntimeException($label);
    }
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();

/** The materialized image file that draws the icon (the one carrying the <use>). */
$iconAsset = static function (array $result): string {
    foreach ( $result['assets'] ?? array() as $asset ) {
        $content = (string) ( $asset['content'] ?? '' );
        if ( 'inline-svg' === ( $asset['source'] ?? '' ) && str_contains($content, '<use') ) {
            return $content;
        }
    }
    return '';
};

/** Decoded SVG data URIs anywhere in the result (block attributes, generated styles). */
$svgDataUris = static function (array $result): array {
    $json = (string) json_encode($result, JSON_UNESCAPED_SLASHES);
    preg_match_all('~data:image/svg\+xml,([^"\'\\\\)\s]+)~', $json, $matches);
    return array_values(array_unique(array_map('rawurldecode', $matches[1])));
};

/** XML namespace errors make browsers reject the whole SVG image. */
$xmlErrors = static function (string $svg): array {
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $document = new DOMDocument();
    $document->loadXML($svg, LIBXML_NONET);
    $errors = array_map(static fn (LibXMLError $error): string => trim($error->message), libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $errors;
};

$sprite = '<div style="display:none"><svg><defs><symbol id="icon-search" viewBox="0 0 24 24"><path d="M2 2h20v20H2z"></path></symbol></defs></svg></div>';

// 1. Materialized image file: a sprite icon referenced with xlink:href.
$xlinkIcon = $iconAsset($transform('<main><a href="/search"><svg height="32" width="32" role="presentation"><use xlink:href="#icon-search"></use></svg><span>Search</span></a>' . $sprite . '</main>'));
$assert(str_contains($xlinkIcon, '<symbol id="icon-search"'), 'the referenced symbol travels with the icon image: ' . $xlinkIcon);
$assert(1 === substr_count($xlinkIcon, 'xmlns:xlink="http://www.w3.org/1999/xlink"'), 'the icon image declares the xlink namespace once: ' . $xlinkIcon);
$assert(array() === $xmlErrors($xlinkIcon), 'the icon image is well-formed namespaced XML: ' . implode('; ', $xmlErrors($xlinkIcon)));

// 2. A source that already declares the namespace keeps exactly one declaration.
$declaredIcon = $iconAsset($transform('<main><a href="/search"><svg xmlns:xlink="http://www.w3.org/1999/xlink" height="32" width="32"><use xlink:href="#icon-search"></use></svg><span>Search</span></a>' . $sprite . '</main>'));
$assert(1 === substr_count($declaredIcon, 'xmlns:xlink='), 'a declared xlink namespace is not repeated: ' . $declaredIcon);
$assert(array() === $xmlErrors($declaredIcon), 'a declared-namespace icon is well-formed namespaced XML');

// 3. SVG 2 href needs no xlink namespace: nothing is added.
$hrefIcon = $iconAsset($transform('<main><a href="/search"><svg height="32" width="32"><use href="#icon-search"></use></svg><span>Search</span></a>' . $sprite . '</main>'));
$assert(str_contains($hrefIcon, '<use href="#icon-search"') && ! str_contains($hrefIcon, 'xmlns:xlink'), 'an href-only icon gets no xlink declaration: ' . $hrefIcon);
$assert(array() === $xmlErrors($hrefIcon), 'an href-only icon is well-formed namespaced XML');

// A self-contained icon whose shape is reused through xlink:href in the same SVG.
$localXlinkIcon = '<svg width="16" height="16" viewBox="0 0 16 16"><defs><path id="glyph" d="M1 1h14v14H1z"></path></defs><use xlink:href="#glyph"></use></svg>';

// 4. Navigation link icon, emitted as a background data URI.
$navUris = array_values(array_filter(
    $svgDataUris($transform('<header><nav><ul><li><a href="/a">' . $localXlinkIcon . 'Home</a></li><li><a href="/b">About</a></li></ul></nav></header><main><p>Body</p></main>')),
    static fn (string $svg): bool => str_contains($svg, 'xlink:href')
));
$assert(array() !== $navUris, 'the navigation icon is emitted as an SVG data URI');
foreach ( $navUris as $svg ) {
    $assert(str_contains($svg, 'xmlns="http://www.w3.org/2000/svg"') && 1 === substr_count($svg, 'xmlns:xlink="http://www.w3.org/1999/xlink"'), 'the navigation icon data URI declares its namespaces: ' . $svg);
    $assert(array() === $xmlErrors($svg), 'the navigation icon data URI is well-formed namespaced XML: ' . implode('; ', $xmlErrors($svg)));
}

// 5. Search trigger icon, emitted as a background data URI.
$searchUris = array_values(array_filter(
    $svgDataUris($transform('<header><div role="button" class="open-search" aria-label="Open search bar" tabindex="0">' . $localXlinkIcon . '</div></header><div class="search-panel"><div class="search-input"><input type="search" aria-label="Search this site"><div aria-hidden="true">Search this site</div></div></div>')),
    static fn (string $svg): bool => str_contains($svg, 'xlink:href')
));
$assert(array() !== $searchUris, 'the search trigger icon is emitted as an SVG data URI');
foreach ( $searchUris as $svg ) {
    $assert(1 === substr_count($svg, 'xmlns:xlink="http://www.w3.org/1999/xlink"'), 'the search trigger data URI declares the xlink namespace once: ' . $svg);
    $assert(array() === $xmlErrors($svg), 'the search trigger data URI is well-formed namespaced XML: ' . implode('; ', $xmlErrors($svg)));
}

fwrite(STDOUT, sprintf("standalone-svg-xlink-namespace: %d assertions passed\n", $assertions));
