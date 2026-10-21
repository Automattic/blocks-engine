<?php
declare(strict_types=1);

/** Shared sizes must follow each matched control's own padding/border floor. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$fixture = (string) file_get_contents(dirname(__DIR__) . '/fixtures/button-mixed-padding-height.html');
$failures = 0;
$cases = 0;
foreach ( array( false, true ) as $direct ) {
    foreach ( array( 'height:2.25rem', 'min-height:2.25rem', 'max-height:2.25rem', 'height:var(--control-height,2.25rem)' ) as $size ) {
        $html = str_replace('height:2.25rem', $size, $fixture);
        if ( $direct ) {
            $html = preg_replace('/<a[^>]*>(<button[^>]*>.*?<\/button>)<\/a>/', '$1', $html);
        }
        // Also retain the declaration on non-controls matched by the utility.
        $html = str_replace('</body>', '<div class="h-9">Other box</div></body>', $html);
        $result = (new HtmlTransformer())->transform($html)->toArray();
        $css = implode('', array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
        $dom = new DOMDocument();
        @$dom->loadHTML($result['serialized_blocks']);
        $xpath = new DOMXPath($dom);
        foreach ( array( 'Get a quote' => false, 'Request a quote' => true, 'Call us' => true ) as $label => $linkOwned ) {
            $link = $xpath->query('//*[self::a or self::button][normalize-space(.)="' . $label . '"]')->item(0);
            $classes = $link?->parentNode?->attributes?->getNamedItem('class')?->nodeValue ?? '';
            preg_match('/\bblocks-engine-control-[\w-]+/', $classes, $marker);
            $found = false;
            foreach ( explode('}', $css) as $rule ) {
                $selector = strstr($rule, '{', true);
                if ( false !== $selector && isset($marker[0]) && str_contains($selector, ':where(.' . $marker[0] . ')') && str_contains($rule, '{' . $size) ) {
                    if ( str_contains($selector, '.wp-block-button__link') === $linkOwned ) {
                        $found = true;
                    }
                    if ( $linkOwned && ! str_contains($selector, '.wp-block-button__link') ) {
                        fwrite(STDERR, "FAIL: {$label} {$size} still sizes a padding-free wrapper\n");
                        ++$failures;
                    }
                }
            }
            if ( ! $found ) {
                fwrite(STDERR, "FAIL: {$label} {$size} did not reach its owning box (direct=" . (int) $direct . ")\n");
                ++$failures;
            }
        }
        if ( ! preg_match('/[^{}]*\.h-9[^{}]*\{' . preg_quote($size, '/') . '/', $css) ) {
            fwrite(STDERR, "FAIL: non-control lost {$size}\n");
            ++$failures;
        }
        ++$cases;
    }
}
if ( $failures > 0 ) {
    exit(1);
}
echo "Mixed button height ownership: {$cases} cases passed\n";
