<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A burger opens a captured dialog whose painted drawer wraps a carrier <nav>.
// The dialog is the native overlay: Core's opener binds to it, the drawer
// paint and the glyph's collapsed-state fill carry over, and the capture's
// hidden closer does not survive beside Core's own close button.
$html = (string) file_get_contents(__DIR__ . '/fixtures/captured-dialog-navigation-overlay.html');
$failures = array();
foreach (array('with runtime' => $html, 'without runtime' => preg_replace('/<script\b[^>]*>.*?<\/script>/s', '', $html)) as $case => $source) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $source))))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $css = str_replace('\\/', '/', (string) json_encode($result, JSON_UNESCAPED_SLASHES));
    preg_match_all('/<!-- wp:navigation (\{.*?\}) -->/', $markup, $matches);
    $navigations = array_map(static fn (string $json): array => (array) json_decode($json, true), $matches[1]);
    $modes = array_map(static fn (array $attrs): string => (string) ($attrs['overlayMenu'] ?? ''), $navigations);
    sort($modes);
    if (array('mobile', 'never') !== $modes) {
        $failures[] = $case . ': expected the desktop bar plus one native mobile overlay, got [' . implode(',', $modes) . '].';
        continue;
    }
    $overlay = current(array_filter($navigations, static fn (array $attrs): bool => 'mobile' === ($attrs['overlayMenu'] ?? '')));
    if ('#fdfdfd' !== ($overlay['customOverlayBackgroundColor'] ?? null)) {
        $failures[] = $case . ': the overlay must take the drawer paint, got ' . json_encode($overlay['customOverlayBackgroundColor'] ?? null) . '.';
    }
    if (!preg_match('/blocks-engine-native-navigation-toggle-([a-f0-9]+)/', (string) ($overlay['className'] ?? ''), $toggle)
        || !preg_match('/' . $toggle[0] . '>\.wp-block-navigation__responsive-container-open>svg\{fill:#ffffff!important/', $css)) {
        $failures[] = $case . ': the opener glyph must keep its collapsed-viewport fill.';
    }
    if (str_contains($markup, '<button') || str_contains($markup, 'capture-dialog')) {
        $failures[] = $case . ': no capture dialog control may survive beside the native overlay.';
    }
    foreach (array('One', 'Two', 'Three') as $label) {
        if (2 !== substr_count($markup, '"label":"' . $label . '"')) {
            $failures[] = $case . ': both navigations must keep ' . $label . '.';
        }
    }
}
foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
}
exit(array() === $failures ? 0 : 1);
