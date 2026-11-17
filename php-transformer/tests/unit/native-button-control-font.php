<?php
declare(strict_types=1);

$compile = static function (string $font): array {
    return json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/native-button-control-font-fixture.php') . ' ' . escapeshellarg($font)), true, 512, JSON_THROW_ON_ERROR);
};
$fixture = $compile('');
if ( ! str_contains($fixture['candidate'], 'wp:button') ) {
    throw new RuntimeException('Native button must remain an editable core/button.');
}
foreach ( array('font-family', 'font-size') as $property ) {
    if ( ! str_contains($fixture['css'], $property . ':revert') ) {
        throw new RuntimeException('Missing UA control font fallback: ' . $property);
    }
}
foreach ( array('font:inherit', 'font:20px Georgia', 'font-family:Verdana;font-size:20px') as $font ) {
    $authored = $compile($font);
    if ( str_contains($authored['css'], 'font-family:revert') || str_contains($authored['css'], 'font-size:revert') ) {
        throw new RuntimeException('Authored typography must not receive a UA fallback: ' . $font);
    }
}
fwrite(STDOUT, "Native button control font tests passed\n");
