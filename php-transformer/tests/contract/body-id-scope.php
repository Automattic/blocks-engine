<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// The theme restores the source body's id on the rendered page, so a rule
// scoped through it (`#site .hero`) applies there like one scoped through a
// body class. The conversion must read it the same way: the hero keeps its
// padding and colour as block supports, and the flex row keeps the
// CSS-owned layout markers the editor canvas relies on.
$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (!$condition) throw new RuntimeException($message . ('' === $detail ? '' : "\n" . $detail));
    echo "PASS: $message\n";
};
$document = static fn(string $scope): string => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Body scope</title><style>'
    . 'body{margin:0}' . $scope . ' .hero{color:#fff;background:#123;padding:40px}' . $scope . ' .cols{display:flex;gap:20px}'
    . '</style></head><body id="site" class="home"><section class="hero"><h1>Hello</h1></section>'
    . '<div class="cols"><div><p>One</p></div><div><p>Two</p></div></div></body></html>';
$blocks = static function (string $scope) use ($document): array {
    $markup = (new HtmlTransformer())->transform($document($scope))->toArray()['serialized_blocks'];
    preg_match('/<!-- wp:group (\{"className":"hero"[^>]*?) -->/', $markup, $hero);
    preg_match('/<!-- wp:group (\{"className":"cols[^>]*?) -->/', $markup, $cols);
    return array('hero' => json_decode($hero[1] ?? 'null', true), 'cols' => json_decode($cols[1] ?? 'null', true), 'markup' => $markup);
};

$byClass = $blocks('.home');
$byId = $blocks('#site');
$assert('40px' === ($byClass['hero']['style']['spacing']['padding']['top'] ?? null), 'A body-class scoped hero keeps its padding.', $byClass['markup']);
$assert($byClass['hero'] === $byId['hero'], 'A body-id scoped hero keeps the same block supports.', $byId['markup']);
$assert(str_contains((string) ($byId['cols']['className'] ?? ''), 'blocks-engine-css-owned-layout'), 'A body-id scoped flex row keeps its CSS-owned layout.', $byId['markup']);
$assert($byClass['cols'] === $byId['cols'], 'A body-id scoped flex row matches the body-class scoped one.', $byId['markup']);
