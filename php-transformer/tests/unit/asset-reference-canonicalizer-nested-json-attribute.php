<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A block keeps a JSON config, holding markup, as a string attribute, and its
// save() mirrors that config into an HTML data attribute. The editor validates
// the block only when both copies localize the same image identically.
$encoders = array(
    'block attributes' => static fn(array $value): string => BlockAttributes::encode($value),
    'json_encode' => static fn(array $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    'json_encode escaped slashes' => static fn(array $value): string => json_encode($value, JSON_THROW_ON_ERROR),
);
$markup = '<button type="button"><img src="/media/view-1.webp" srcset="/media/view-1.webp 1x" alt="">'
    . '<a href="/media/view-1.webp">View</a><video poster="/media/view-1.webp" style="background:url(/media/view-1.webp)"></video></button>';
$shapes = array(
    'choices' => array('choices' => array(array('html' => $markup))),
    'captured panel' => array('resting' => array('html' => $markup)),
);
$canonicalizer = new AssetReferenceCanonicalizer(array(array('source_path' => 'media/view-1.webp', 'token' => 'view')), '');
$token = WordPressSitePlan::TOKEN_PREFIX . 'view}}';
foreach ($encoders as $encoding => $encode) {
    foreach ($shapes as $shape => $model) {
        $label = $encoding . ' / ' . $shape;
        $config = $encode($model);
        $block = '<!-- wp:example/choice-group ' . BlockAttributes::encode(array('config' => $config)) . ' -->'
            . '<div class="choices" data-config="' . htmlspecialchars($config, ENT_QUOTES) . '"><img src="/media/view-1.webp" alt=""></div>'
            . '<!-- /wp:example/choice-group -->';
        $canonical = $canonicalizer->content($block, 'index.html');

        preg_match('/^<!-- wp:example\/choice-group (\{.*?\}) -->/', $canonical, $opener);
        preg_match('/data-config="([^"]*)"/', $canonical, $mirror);
        $attribute = json_decode($opener[1] ?? '', true)['config'] ?? null;
        if (!is_string($attribute) || !str_contains($attribute, $token) || str_contains(str_replace('\\/', '/', $attribute), '/media/view-1.webp')) {
            throw new RuntimeException($label . ': The config attribute must localize the image it serializes, got: ' . var_export($attribute, true));
        }
        if ($attribute !== html_entity_decode($mirror[1] ?? '', ENT_QUOTES)) {
            throw new RuntimeException($label . ': The config attribute and its saved HTML mirror must stay identical.');
        }
        if ($canonicalizer->content($canonical, 'index.html') !== $canonical) {
            throw new RuntimeException($label . ': Canonicalization must be idempotent.');
        }
    }
}

// Only whole resource attribute names inside the JSON markup are targets (#5547).
foreach ($encoders as $encoding => $encode) {
    $config = $encode(array('resting' => array('html' => '<img data-src="/media/view-1.webp" data-href="/media/view-1.webp" data-poster="/media/view-1.webp" data-srcset="/media/view-1.webp 1x" data-style="background:url(/media/view-1.webp)">')));
    $block = '<!-- wp:example/panel ' . BlockAttributes::encode(array('config' => $config)) . ' -->'
        . '<div data-config="' . htmlspecialchars($config, ENT_QUOTES) . '" data-inert="&quot;&#34;&amp;&#38;"></div>'
        . '<!-- /wp:example/panel -->';
    if ($canonicalizer->content($block, 'index.html') !== $block) {
        throw new RuntimeException($encoding . ': Untargeted attribute values must remain byte-identical.');
    }
}
fwrite(STDOUT, "Asset reference canonicalizer nested JSON attribute contract passed\n");
