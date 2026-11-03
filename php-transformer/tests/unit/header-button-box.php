<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/header-button-box-fixture.php';
$fixture = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$assert(str_contains($fixture['markup'], '"contentParts"'), 'Wrapped icon uses the native authored button, not a core/button wrapper');
$assert(str_contains($fixture['markup'], '<button') && str_contains($fixture['markup'], 'class="toggle"'), 'Author class stays on the rendered button');
$assert(str_contains($fixture['markup'], 'wp:navigation '), 'Search remains a native navigation projection');
$assert(str_contains($fixture['css'], 'background-color:ButtonFace!important'), 'Navigation opener carries native button background');
$assert(str_contains($fixture['css'], 'border-radius:4px!important'), 'Navigation opener carries author shorthand radius');
$assert(str_contains($fixture['css'], 'padding:1px 6px!important'), 'Navigation opener carries native padding');
echo "Header button box ownership passed\n";
