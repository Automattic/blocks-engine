<?php
declare(strict_types=1);

/** Deduplication retains only differing, document-language schema evidence (#3743). */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\SchemaProductEvidence;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanView;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$page = static function (string $lang, string $name, string $description, string $url, string $type = 'Product'): string {
    $schema = array('@type' => $type, 'sku' => 'tote-1', 'name' => $name, 'description' => $description, 'url' => $url, 'offers' => array('price' => '20'));
    return '<html' . ('' === $lang ? '' : ' lang="' . $lang . '"') . '><head><title>Catalog</title><link rel="alternate" hreflang="x-default" href="/products/tote/"><link rel="alternate" hreflang="fr" href="/fr/products/tote/"><script type="application/ld+json">' . json_encode($schema) . '</script></head><body><main><h1>' . $name . '</h1></main></body></html>';
};
$files = array(
    'products/tote/index.html' => $page('en', 'Black Tote', 'A black bag.', '/products/tote/'),
    'fr/products/tote/index.html' => $page('fr', 'Sac noir', 'Un sac noir.', '/fr/products/tote/', 'ProductGroup'),
    'index.html' => '<html><head><title>Catalog</title></head><body><main><div style="display:grid"><article><h2>Black Tote</h2><img src="/tote.jpg"><p>$20.00</p><a href="/products/tote/">Details</a><button>Add to Cart</button></article></div></main></body></html>',
);
$expected = array('fr' => array('name' => 'Sac noir', 'description' => 'Un sac noir.', 'source_path' => 'fr/products/tote/index.html', 'url' => '/fr/products/tote/'));
foreach (array($files, array_reverse($files, true)) as $input) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $input));
    $plan = $result->toArray()['source_reports']['wordpress_site_plan'] ?? array();
    WordPressSitePlan::assertValid($plan);
    $entities = array();
    foreach ($plan['runtime_declarations'] as $declaration) {
        if ('products' === ($declaration['type'] ?? null)) $entities = $declaration['payload']['entities'];
    }
    $assert(1 === count($entities), 'EN and FR schema pages dedupe into one product.');
    $assert('Black Tote' === $entities[0]['name'] && $expected == ($entities[0]['translations'] ?? null), 'Canonical identity and translated evidence survive the card merge.');
    $assert(!empty($entities[0]['bindings']), 'The canonical row retains card bindings.');
    $view = new WordPressSitePlanView();
    $full = $view->fromResult($result);
    $assert($full === WordPressSitePlanView::materialize($view->compact($full)), 'Compact views preserve translations losslessly.');
}
$read = static function (array $files): array {
    $rows = array();
    foreach ($files as $path => $content) $rows[] = array('path' => $path, 'content' => $content);
    return SchemaProductEvidence::rows($rows, static fn (string $value): string => strtolower($value), static fn ($value): string => (string) $value)['tote'];
};
// Negative: language links or a translated-looking path are not document-language evidence.
foreach (array('', 'en', 'invalid_language') as $language) {
    $row = $read(array('products/tote/index.html' => $files['products/tote/index.html'], 'fr/products/tote/index.html' => $page($language, 'Sac noir', 'Un sac noir.', '/fr/products/tote/')));
    $assert(!isset($row['translations']), 'Missing, invalid, or canonical document language emits no translation.');
}
$row = $read(array('products/tote/index.html' => $files['products/tote/index.html'], 'fr/products/tote/index.html' => str_replace('<script type="application/ld+json">', '<script type="text/plain">', $files['fr/products/tote/index.html'])));
$assert(!isset($row['translations']), 'A page without its own schema product contributes no translated evidence.');
$row = $read(array('products/tote/index.html' => $files['products/tote/index.html'], 'fr/products/tote/index.html' => $page('fr', 'Black Tote', 'A black bag.', '/fr/products/tote/')));
$assert(array('source_path' => 'fr/products/tote/index.html', 'url' => '/fr/products/tote/') === $row['translations']['fr'], 'Identical names and descriptions are omitted, not copied.');
$bounded = array('products/tote/index.html' => $files['products/tote/index.html']);
foreach (array('fr', 'de', 'es', 'it', 'nl', 'pt', 'sv', 'da', 'fi') as $language) {
    $bounded[$language . '/products/tote/index.html'] = $page($language, str_repeat('é', 200), str_repeat('é', 3000), '/' . $language . '/' . str_repeat('x', 2200));
}
$row = $read($bounded);
$assert(8 === count($row['translations']), 'At most eight translated languages are retained.');
foreach ($row['translations'] as $translation) {
    $assert(256 === strlen($translation['name']) && 4096 === strlen($translation['description']) && 2048 === strlen($translation['url']), 'Translation fields are byte-bounded.');
    $assert(mb_check_encoding($translation['name'], 'UTF-8') && mb_check_encoding($translation['description'], 'UTF-8'), 'Bounds preserve UTF-8.');
}
$longPath = 'fr/' . str_repeat('x', 300) . '/index.html';
$row = $read(array('products/tote/index.html' => $files['products/tote/index.html'], $longPath => $files['fr/products/tote/index.html']));
$assert(256 === strlen($row['translations']['fr']['source_path']), 'Translated source paths are byte-bounded.');
echo "product-translated-evidence: ok\n";
