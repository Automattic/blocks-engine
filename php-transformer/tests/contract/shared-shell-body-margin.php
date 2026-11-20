<?php
/**
 * A shared header is compiled once, in a synthetic document that keeps only
 * the stylesheets its member routes have in common, and its support CSS joins
 * every member route. When each route resets the body margin in a stylesheet
 * of its own, that synthetic body resolves no margin, but it is no member's
 * body: restoring the user-agent 8px from it would override every route's
 * authored `margin:0`. Each route's own compile still restores the margin for
 * a body its author left unset.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ($condition) {
        echo "PASS: $message\n";
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL: $message\n" . ('' !== $detail ? $detail . "\n" : ''));
};

// Desktop and phone documents each carry a header and footer, so the shared
// landmarks are inline shells; each route's stylesheet names a route class,
// so no stylesheet is common to the members.
$compile = static function (string $bodyCss): array {
    $files = array();
    foreach (array('index' => 'Home', 'about' => 'About', 'team' => 'Team') as $slug => $title) {
        $html = '<!doctype html><html><head><style>' . $bodyCss . '.desktop-document{display:block}.mobile-document{display:none}'
            . '@media(max-width:600px){.desktop-document{display:none}.mobile-document{display:block}}.' . $slug . '-grid{display:grid}</style></head><body>';
        foreach (array('desktop', 'mobile') as $variant) {
            $html .= '<div class="' . $variant . '-document"><header class="' . $variant . '-header"><p>' . $variant . ' publication</p></header>'
                . '<main class="' . $slug . '-grid"><h1>' . $title . '</h1></main><footer class="' . $variant . '-footer"><p>Legal copy</p></footer></div>';
        }
        $files[$slug . '.html'] = $html . '</body></html>';
    }
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
};
$restores = static fn (array $plan): array => array_values(array_map(
    static fn (array $asset): string => (string) $asset['target_path'],
    array_filter($plan['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null) && str_contains((string) ($asset['content'] ?? ''), 'margin-top:8px'))
));

$plan = $compile('html,body{margin:0;padding:0}');
$parts = array_filter($plan['template_parts'] ?? array(), static fn (array $part): bool => 'inline_shared_shell' === ($part['placement']['kind'] ?? null));
$assert(4 === count($parts), 'the header and footer variants compile as shared inline shells');
$assert(array() === $restores($plan), 'no shared shell restores the body margin over its members\' authored reset', implode(', ', $restores($plan)));

$assert(array() !== $restores($compile('')), 'routes whose author leaves the body margin unset still restore it');

exit($failures > 0 ? 1 : 0);
