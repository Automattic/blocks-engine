<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};
$flatten = static function (array $blocks) use (&$flatten): array {
    $flat = array();
    foreach ( $blocks as $block ) {
        $flat[] = $block;
        $flat = array_merge($flat, $flatten($block['innerBlocks'] ?? array()));
    }
    return $flat;
};

$username = '<p class="login-username"><label for="user_login">Username</label><input id="user_login" name="log" type="text" data-blocks-engine-capture-native-control-state="{}"></p>';
$password = '<p class="login-password"><label for="user_pass">Password</label><input id="user_pass" name="pwd" type="password" data-blocks-engine-capture-native-control-state="{}"></p>';
$submit = '<p class="login-submit"><input type="submit" value="Log In"><input type="hidden" name="redirect_to" value="/private/"></p>';
$remember = '<p class="login-remember"><label><input type="checkbox" name="rememberme">Remember me</label></p>';

$cases = array(
    'WordPress sign-in' => array( $username . $password . $remember . $submit, true ),
    'email sign-in without remember' => array( '<input type="email" name="email">' . $password . '<button>Sign in</button>', true ),
    'confirm password' => array( $username . $password . '<input type="password" name="confirm">' . $submit, false ),
    'new password confirmation' => array( $username . $password . '<input type="password" autocomplete="new-password">' . $submit, false ),
    'extra text' => array( $username . $password . '<input name="full_name">' . $submit, false ),
    'select' => array( $username . $password . '<select name="role"><option>Member</option></select>' . $submit, false ),
    'textarea' => array( $username . $password . '<textarea name="bio"></textarea>' . $submit, false ),
    'register value' => array( $username . $password . '<input type="submit" value="Register">', false ),
    'sign-up button' => array( $username . $password . '<button>Sign up</button>', false ),
    'registration button' => array( $username . $password . '<button>Registration</button>', false ),
    'extra checkbox' => array( $username . $password . $remember . $remember . $submit, false ),
    'terms checkbox' => array( $username . $password . '<label><input type="checkbox" name="terms">Accept terms</label>' . $submit, false ),
    'no submit' => array( $username . $password, false ),
    'two submits' => array( $username . $password . $submit . $submit, false ),
);

foreach ( $cases as $label => [$controls, $converted] ) {
    $result = ( new HtmlTransformer() )->transform('<h2>Member access</h2><form name="loginform" id="loginform" action="/wp-login.php" method="post">' . $controls . '</form><p>After the form</p>')->toArray();
    $blocks = $flatten($result['blocks'] ?? array());
    $named = static fn (string $name): array => array_values(array_filter($blocks, static fn (array $block): bool => $name === ($block['blockName'] ?? '')));
    $assert(1 === count($named('core/heading')), "{$label}: heading preserved");
    $serialized = $result['serialized_blocks'] ?? '';
    $assert(str_contains($serialized, 'After the form'), "{$label}: following sibling preserved");
    if ( $converted ) {
        $login = $named('core/loginout');
        $assert(1 === count($login), "{$label}: exactly one loginout block");
        $assert(true === ($login[0]['attrs']['displayLoginAsForm'] ?? null), "{$label}: displayLoginAsForm enabled");
        $assert(array() === $named('core/html'), "{$label}: no HTML fallback block");
        $assert(array() === ($result['fallbacks'] ?? array()), "{$label}: no fallback or loss diagnostic");
        $assert(str_contains($serialized, 'wp:loginout {"displayLoginAsForm":true}'), "{$label}: native block serialized");
        foreach ( array( '/wp-login.php', 'redirect_to', 'rememberme', 'user_pass', 'capture-native-control-state' ) as $dropped ) {
            $assert(! str_contains($serialized, $dropped), "{$label}: drops {$dropped}");
        }
        $validity = ( new BlockValidityValidator() )->validateBlocks($result['blocks']);
        $assert('pass' === ($validity['status'] ?? ''), "{$label}: Gutenberg-valid output");
    } else {
        $assert(array() === $named('core/loginout'), "{$label}: declines native conversion");
        $assert(array() !== ($result['fallbacks'] ?? array()), "{$label}: retains fallback handling");
    }
}

if ( $failures > 0 ) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}
echo "login-form-native-loginout: passed\n";
