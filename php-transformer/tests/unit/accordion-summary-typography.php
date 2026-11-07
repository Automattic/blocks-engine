<?php
declare(strict_types=1);

// Summary text inherits body typography, not theme heading-element styles (#4968).
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$page = (string) file_get_contents(dirname(__DIR__) . '/fixtures/accordion-summary-typography.html');
foreach (array('reduced page' => $page, 'inherited line height' => str_replace('font-size:16px}', 'font-size:16px;line-height:1.75}', $page)) as $label => $html) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $markup = (string) $result['serialized_blocks'];
    $css = '';
    foreach ($result['assets'] ?? array() as $asset) {
        if ('css' === ($asset['kind'] ?? '')) $css .= (string) ($asset['content'] ?? '');
    }
    $fail = static function (string $message) use ($label, $markup): never {
        fwrite(STDERR, "FAIL: {$label}: {$message}\n{$markup}\n");
        exit(1);
    };
    preg_match_all('/<!-- wp:accordion-heading (\{.*?\}) -->/', $markup, $matches);
    if (3 !== count($matches[1])) $fail('all summaries become accordion headings');
    foreach ($matches[1] as $json) {
        $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $type = $attrs['style']['typography'] ?? array();
        if ('Arial,sans-serif' !== str_replace(' ', '', $type['fontFamily'] ?? '')) $fail('heading carries the inherited body font family, not Georgia');
        if ('14px' !== ($type['fontSize'] ?? '')) $fail('heading carries the summary size');
        if ('600' !== ($type['fontWeight'] ?? '')) $fail('heading carries the summary weight');
        // Core lacks line-height support here; use the existing support fallback.
        if ('inherited line height' === $label) {
            $classes = preg_split('/\s+/', $attrs['className'] ?? '') ?: array();
            $carried = false;
            foreach ($classes as $class) {
                if (preg_match('/\.' . preg_quote($class, '/') . '\\{[^}]*line-height:1\.75/', $css)) $carried = true;
            }
            if (!$carried) $fail('heading carries inherited line height through the standard support fallback');
        }
    }
}
fwrite(STDOUT, "accordion summary typography tests: passed\n");
