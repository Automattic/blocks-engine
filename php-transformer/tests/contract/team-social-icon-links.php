<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Issue #6378 (startbootstrap-agency team section): a row of round
// `.btn.btn-social` icon links under a team photo, separated by source
// whitespace. On the imported site the links were square, packed 4px too
// tight, and the author photo rule (`.team-member img`) drew a 224px bordered
// bar through every icon once the importer moved its SVG into uploads.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$css = ':root{--bs-border-radius:.375rem}'
    . '.btn{--bs-btn-padding-x:.75rem;--bs-btn-padding-y:.375rem;--bs-btn-border-radius:var(--bs-border-radius);font-size:1rem;display:inline-block;padding:var(--bs-btn-padding-y) var(--bs-btn-padding-x);border:1px solid transparent;border-radius:var(--bs-btn-border-radius);text-decoration:none}'
    . '.btn-dark{color:#fff;background-color:#212529;border-color:#212529}.btn-dark:hover{background-color:#424649}'
    . '.btn-social{height:2.5rem;width:2.5rem;display:inline-flex;align-items:center;justify-content:center;padding:0;border-radius:100%}'
    . '.team-member{text-align:center}.team-member img{width:14rem;height:14rem;border:.5rem solid rgba(0,0,0,.1)}.svg-inline--fa{height:1em}';
$icon = static fn (string $d): string => '<svg class="svg-inline--fa" aria-hidden="true" viewBox="0 0 512 512"><path fill="currentColor" d="' . $d . '"></path></svg>';
$member = static fn (string $name): string => '<div class="team-member"><img src="media/' . $name . '.jpg" alt="' . $name . '"><h4>' . $name . '</h4><p>Lead</p>' . "\n"
    . '<a class="btn btn-dark btn-social" href="#!" aria-label="' . $name . ' Twitter">' . $icon('M0 0h512v512z') . '</a>' . "\n"
    . '<a class="btn btn-dark btn-social" href="#!" aria-label="' . $name . ' Facebook">' . $icon('M0 0h256v512z') . '</a>' . "\n"
    . '<a class="btn btn-dark btn-social" href="#!" aria-label="' . $name . ' LinkedIn">' . $icon('M0 0h512v256z') . '</a>' . "\n"
    . '</div>';
$html = '<!doctype html><html><head><title>Team</title><style>' . $css . '</style></head><body><main><section><h2>Team</h2>'
    . $member('Ana') . $member('Ben') . $member('Cai') . '</section></main></body></html>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html, 'about.html' => $html, 'team.html' => $html),
))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$theme = array();
$css = '';
$markup = '';
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) {
        $theme = json_decode((string) $write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    } elseif (str_ends_with($write['target_path'], '.css')) {
        $css .= (string) $write['payload']['data'];
    } elseif (str_starts_with($write['target_path'], 'parts/')) {
        // The section is shared by every route, so it lands in a part.
        $markup .= (string) $write['payload']['data'];
    }
}

// The round look is the site's dominant button look, but the theme element
// style is weaker than the projected `.btn` rule. Each link keeps its own
// radius and padding, as `.btn-social` beat `.btn` in the source.
$assert('100%' === ($theme['styles']['elements']['button']['border']['radius'] ?? null), 'fixture promotes the round look to the button element');
$assert(1 === preg_match('/blocks-engine-control-[^{}]*\{[^}]*border-radius:var\(--bs-btn-border-radius\)/', $css), 'fixture projects the square .btn radius onto the controls');
$assert(9 === preg_match_all('/<!-- wp:button (\{.*?\}) -->/', $markup, $buttons), 'every icon link is a native button');
foreach ($buttons[1] as $json) {
    $style = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['style'] ?? array();
    $assert('100%' === ($style['border']['radius'] ?? null), 'icon link keeps its round radius over the projected .btn rule');
    $assert(array('top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0') === ($style['spacing']['padding'] ?? null), 'icon link keeps its zero padding over the projected .btn rule');
}

// Inline icon links that the source separated by whitespace keep that space,
// even though the photo, name and role before them are block-level.
$assert(6 === substr_count($markup, '<!-- /wp:buttons --> <!-- wp:buttons'), 'adjacent icon links keep the source space between them');
$assert(!str_contains($markup, '</p><!-- /wp:paragraph --> <!--'), 'no space is invented after a block-level sibling');

// The importer sideloads materialized SVGs into its media library, keeping
// only the content-addressed file name. The guard that keeps author `img`
// rules off those icons must still match there.
$assert(str_contains($css, '.team-member img:not(:where([src*="/inline-svg-"]))'), 'author img rule excludes materialized SVGs by their relocatable file name');
$assert(!str_contains($css, '[src*="assets/materialized-svg/'), 'no src guard depends on the pre-import asset directory');

echo "Team social icon links contract passed\n";
