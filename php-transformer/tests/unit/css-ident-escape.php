<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssIdent;

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    ++$count;
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$cases = array(
    'card' => 'card',
    'card__title--large' => 'card__title--large',
    '_private' => '_private',
    '-' => '-',
    '--token' => '--token',
    '-x9' => '-x9',
    '9col' => '\\39 col',
    '-9col' => '-\\39 col',
    'sm:grid' => 'sm\\:grid',
    'w-1/2' => 'w-1\\/2',
    "card\n" => 'card\\a ',
    'é' => "\\\xC3\\\xA9",
    "a\0b" => "a\u{FFFD}b",
);
foreach ( $cases as $ident => $expected ) {
    $assert($expected === CssIdent::escape((string) $ident), sprintf('escape(%s)', json_encode((string) $ident)));
}
$escapedClass = '/' . CssIdent::classSelectorRegex('sm:grid') . '(?![\w-])/';
$assert(1 === preg_match($escapedClass, '.sm\\:grid') && 1 === preg_match($escapedClass, '.sm:grid'), 'class selector regex matches both spellings of an escaped class');
$assert('\\.card' === CssIdent::classSelectorRegex('card'), 'class selector regex for a plain class');

// The fast path must agree with the CSSOM per-character serialization.
$reference = static function (string $ident): string {
    $escaped = '';
    $length = strlen($ident);
    for ( $offset = 0; $offset < $length; $offset++ ) {
        $ord = ord($ident[ $offset ]);
        $leadingDigit = ( 0 === $offset && $ord >= 0x30 && $ord <= 0x39 ) || ( 1 === $offset && $ord >= 0x30 && $ord <= 0x39 && '-' === $ident[0] );
        if ( 0 === $ord ) { $escaped .= "\u{FFFD}"; continue; }
        if ( $leadingDigit || ( $ord >= 0x01 && $ord <= 0x1f ) || 0x7f === $ord ) { $escaped .= '\\' . dechex($ord) . ' '; continue; }
        $escaped .= 1 === preg_match('/[A-Za-z0-9_-]/', $ident[ $offset ]) ? $ident[ $offset ] : '\\' . $ident[ $offset ];
    }
    return $escaped;
};
mt_srand(2897);
$alphabet = "abzAZ09_-:/.\n\x7f\0 \xC3\xA9";
$mismatches = 0;
for ( $i = 0; $i < 5000; $i++ ) {
    $ident = '';
    for ( $j = mt_rand(1, 8); $j > 0; $j-- ) {
        $ident .= $alphabet[ mt_rand(0, strlen($alphabet) - 1) ];
    }
    $mismatches += $reference($ident) === CssIdent::escape($ident) ? 0 : 1;
}
$assert(0 === $mismatches, "escape agrees with the per-character serialization ({$mismatches} mismatches)");

echo "CSS identifier escape tests passed ({$count} assertions)\n";
