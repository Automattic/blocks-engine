<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$css = '';
for ($i = 0; $i < 80; ++$i) {
    foreach (array('listing', 'detail') as $variant) {
        foreach (array('body.', '.') as $prefix) $css .= $prefix . 'tweak-' . $variant . ' .foo{--sample-' . $variant . '-' . $i . ':' . $i . ';padding-bottom:' . ($i + 1) . 'px}';
    }
}
// Native subjects exercise identical arms; lowered navigation subjects also
// produce dormant authored arms which genuinely need a route-set guard.
foreach (array('native', 'navigation') as $subject) {
    $previousBytes = null;
    foreach (array(20, 40) as $count) {
        $files = array('site.css' => $css);
        for ($i = 0; $i < $count; ++$i) {
            $content = 'navigation' === $subject
                ? '<nav><ul><li class="foo"><a href="/page-0.html">Page ' . $i . '</a></li></ul></nav>'
                : '<p class="foo">Page ' . $i . '</p>';
            $files['page-' . $i . '.html'] = '<html><head><title>Page ' . $i . '</title><link rel="stylesheet" href="/site.css"></head><body class="tweak-' . ($i % 2 ? 'detail' : 'listing') . '"><main>' . $content . '</main></body></html>';
        }
        $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'page-0.html', 'files' => $files))->toArray();
        $plan = $result['source_reports']['wordpress_site_plan'];
        $styles = array_filter($plan['assets'], static fn(array $a): bool => str_contains($a['content'] ?? '', '--sample-'));
        $compiled = implode('', array_column($styles, 'content'));
        $bytes = strlen($compiled);
        $assert($bytes <= 5 * strlen($css), "$subject/$count: bounded source-to-output ratio ($bytes bytes)");
        $assert(null === $previousBytes || $previousBytes === $bytes, "$subject: doubling routes must not grow stylesheet bytes");
        $previousBytes = $bytes;
        $assert(!str_contains($compiled, ':root:is(.be-r-'), 'No enumerated route guards');
        $rules = array();
        (new CssStylesheetTransformer())->visitStyleRules($compiled, static function (string $selector, string $body) use (&$rules): void {
            if (preg_match('/--sample-(listing|detail)-(0|39|79):/', $body, $sample)) $rules[$sample[1] . '-' . $sample[2]][] = $selector;
        });
        $writes = json_encode($plan['writes']);
        foreach ($plan['pages'] as $page) {
            if (!preg_match('/^page-(\d+)\.html$/', $page['source_path'], $match)) continue;
            $i = (int) $match[1];
            $dom = new DOMDocument();
            $dom->loadHTML('<html><body>' . ('native' === $subject ? '<p class="foo">Native probe</p>' : '') . '</body></html>');
            foreach (array('html' => 'root_attributes', 'body' => 'body_attributes') as $tag => $key) {
                foreach ($page['document_metadata'][$key] as $name => $value) $dom->getElementsByTagName($tag)->item(0)->setAttribute($name, $value);
            }
            // Render the public navigation contract's host/container/item.
            // Its route-specific classes are emitted on the host by Core.
            if ('navigation' === $subject) {
                preg_match('/wp:navigation (\{.*?\}) \/-->/', $page['canonical_block_markup'], $block);
                $attributes = json_decode($block[1], true);
                $nav = $dom->createElement('nav');
                $nav->setAttribute('class', 'wp-block-navigation ' . $attributes['className']);
                $ul = $dom->createElement('ul');
                $ul->setAttribute('class', 'wp-block-navigation__container');
                $li = $dom->createElement('li');
                $li->setAttribute('class', 'wp-block-navigation-item foo');
                $ul->appendChild($li);
                $nav->appendChild($ul);
                $dom->getElementsByTagName('body')->item(0)->appendChild($nav);
            }
            foreach (preg_split('/\s+/', $page['document_metadata']['root_attributes']['class']) as $class) {
                if (str_starts_with($class, 'be-rs-')) $assert(str_contains($writes, $class), 'Materialized runtime root registry retains set membership');
            }
            foreach (array('listing', 'detail') as $variant) {
                foreach (array(0, 39, 79) as $sample) {
                    foreach ($dom->getElementsByTagName('*') as $element) {
                        if (!str_contains($element->getAttribute('class'), 'foo')) continue;
                        $sourceMatches = CssSelectorMatcher::matches($element, CssSelectorMatcher::parse('body.tweak-' . $variant . ' .foo'))['matches'];
                        $effective = false;
                        foreach ($rules[$variant . '-' . $sample] as $selector) {
                            foreach (CssStylesheetTransformer::splitSelectorList($selector) as $arm) {
                                $parsed = CssSelectorMatcher::parse($arm);
                                $assert($parsed['supported'], 'Fixture selectors are evaluable: ' . $arm);
                                $effective = $effective || CssSelectorMatcher::matches($element, $parsed)['matches'];
                            }
                        }
                        $assert($effective === $sourceMatches, "$subject/$count route $i $variant/$sample: source and compiled matches agree");
                    }
                }
            }
        }
        echo "$subject/$count: source " . strlen($css) . ", compiled $bytes bytes\n";
    }
}
