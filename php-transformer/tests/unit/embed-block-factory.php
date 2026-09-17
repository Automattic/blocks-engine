<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;

$failures = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures): void {
    if ( $expected === $actual ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true) . PHP_EOL);
};
$assertTrue = static function (bool $condition, string $message) use (&$failures): void {
    if ( $condition ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

/**
 * WordPress core's WP_Embed::autoembed() only autoembeds a URL when it sits
 * alone on its own line — see wp-includes/class-wp-embed.php. If core/embed's
 * saved markup packs the URL onto the same line as surrounding markup, this
 * regex never matches and the URL renders as inert text instead of an
 * embed, regardless of how correct the block's stored attrs are.
 */
const WP_EMBED_AUTOEMBED_PATTERN = '|^(\s*)(https?://[^\s<>"]+)(\s*)$|im';

$factory = new BlockFactory();

$block = $factory->create('core/embed', array(
    'url'              => 'https://open.spotify.com/artist/46aKqTxrSund2Ccj4oPRsq',
    'type'             => 'rich',
    'providerNameSlug' => 'spotify',
));

$innerHtml = $block['innerHTML'];

// Shape assertion: the URL is on its own line inside the wrapper div, matching
// core's save.js (`{`\n${url}\n`}`) and this repo's TS engine (embed.ts:49).
$assertSame(
    "\n<figure class=\"wp-block-embed is-type-rich is-provider-spotify wp-block-embed-spotify\">"
    . '<div class="wp-block-embed__wrapper">'
    . "\nhttps://open.spotify.com/artist/46aKqTxrSund2Ccj4oPRsq\n"
    . '</div></figure>' . "\n",
    $innerHtml,
    'core/embed save markup matches core\'s canonical newline-wrapped URL shape.'
);

// Regression: assert the *behavior* WP_Embed::autoembed() depends on, not just
// the literal string — the saved markup must contain a line that is nothing
// but the URL (optionally surrounded by whitespace), which is exactly what
// the autoembed regex requires to fire.
$assertTrue(
    1 === preg_match(WP_EMBED_AUTOEMBED_PATTERN, $innerHtml, $matches) && 'https://open.spotify.com/artist/46aKqTxrSund2Ccj4oPRsq' === $matches[2],
    'WP_Embed::autoembed() regex matches the URL as standing alone on its own line.'
);

// Same regression across providers, proving the fix is generic and not
// Spotify-specific: any oEmbed provider's URL must survive the same shape.
$providers = array(
    array( 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'type' => 'video', 'providerNameSlug' => 'youtube' ),
    array( 'url' => 'https://vimeo.com/76979871', 'type' => 'video', 'providerNameSlug' => 'vimeo' ),
    array( 'url' => 'https://soundcloud.com/example/track', 'type' => 'rich', 'providerNameSlug' => 'soundcloud' ),
    array( 'url' => 'https://www.dailymotion.com/video/x7tgad0', 'type' => 'video', 'providerNameSlug' => 'dailymotion' ),
);

foreach ( $providers as $providerAttrs ) {
    $providerBlock = $factory->create('core/embed', $providerAttrs);
    $providerHtml  = $providerBlock['innerHTML'];

    $assertTrue(
        1 === preg_match(WP_EMBED_AUTOEMBED_PATTERN, $providerHtml, $providerMatches) && $providerAttrs['url'] === $providerMatches[2],
        'WP_Embed::autoembed() regex matches the ' . $providerAttrs['providerNameSlug'] . ' URL as standing alone on its own line.'
    );
}

// A URL packed onto the same line as surrounding markup — the pre-fix shape —
// must NOT satisfy the autoembed regex. This is the failure mode the fix
// resolves: attrs can be perfectly correct while the saved markup still
// renders as bare text because WP_Embed::autoembed() never fires.
$brokenShape = '<div class="wp-block-embed__wrapper">https://open.spotify.com/artist/46aKqTxrSund2Ccj4oPRsq</div>';
$assertSame(
    0,
    preg_match(WP_EMBED_AUTOEMBED_PATTERN, $brokenShape),
    'The pre-fix single-line shape does not satisfy WP_Embed::autoembed(), proving the newline placement is load-bearing.'
);

if ( 0 === $failures ) {
    echo "embed block factory ok\n";
}

exit(0 === $failures ? 0 : 1);
