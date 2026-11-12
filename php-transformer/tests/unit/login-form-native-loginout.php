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
    'register value' => array( $username . $password . '<input type="submit" value="Register">', true, 'Register' ),
    'sign-up button' => array( $username . $password . '<button>Sign up</button>', true, 'Sign up' ),
    'registration button' => array( $username . $password . '<button>Registration</button>', true, 'Registration' ),
    'extra checkbox' => array( $username . $password . $remember . $remember . $submit, false ),
    'terms checkbox' => array( $username . $password . '<label><input type="checkbox" name="terms">Accept terms</label>' . $submit, false ),
    'no submit' => array( $username . $password, false ),
    'two submits' => array( $username . $password . $submit . $submit, false ),
    'secondary buttons' => array( $username . $password . $remember . $submit . '<span>Or</span><button type="button" id="guest-signin">Sign in as a Guest</button><button type="button"><span>Other access options</span></button>', true, null, array( 'Sign in as a Guest', 'Other access options' ), array( 'core/loginout', 'core/paragraph', 'core/buttons', 'core/buttons' ), array( 'Or' ) ),
    'nested secondary prose' => array( $username . $password . $submit . '<div><label>Credential label</label><fieldset><legend>Credential legend</legend><span>Or &amp; more</span><button type="button">Guest</button> Then <button type="button">Other</button></fieldset></div>', true, null, array( 'Guest', 'Other' ), array( 'core/loginout', 'core/paragraph', 'core/buttons', 'core/paragraph', 'core/buttons' ), array( 'Or &amp; more', 'Then' ) ),
    'hidden secondary prose' => array( $username . $password . $submit . '<span hidden>Hidden</span><div style="display: none"><span>Hidden descendant</span></div><span style="visibility: hidden !important">Invisible</span><span aria-hidden="true">Hidden accessible text</span><button type="button">Guest</button>', true, null, array( 'Guest' ) ),
    'secondary inline handler' => array( $username . $password . $submit . '<button type="button" onclick="guest()">Guest access</button>', false ),
);

$identity = '<input name="firstName"><input name="userName"><input type="email" name="email">';
$registration = $identity . $password . '<input type="hidden" name="token" value="source-secret"><button>Create account</button>';
$cases += array(
    'plain registration' => array( $registration, true, 'Create account' ),
    'registration confirmation' => array( $registration . '<input type="password" name="confirm_password">', true, 'Create account' ),
    'registration terms' => array( $registration . '<label><input type="checkbox" name="terms">Accept terms</label>', true, 'Create account' ),
    'registration extra password' => array( $registration . '<input type="password" name="other">', false ),
    'registration two confirmations' => array( $registration . '<input type="password" name="confirm"><input type="password" name="repeat">', false ),
    'registration non-identity text' => array( $registration . '<input name="favorite_color">', false ),
    'registration select' => array( $registration . '<select name="role"><option>Member</option></select>', false ),
    'registration textarea' => array( $registration . '<textarea name="bio"></textarea>', false ),
    'registration file' => array( $registration . '<input type="file" name="avatar">', false ),
    'registration checkbox' => array( $registration . '<input type="checkbox" name="newsletter">', false ),
    'registration two submits' => array( $registration . '<button>Sign up</button>', false ),
);

foreach ( $cases as $label => $case ) {
    [$controls, $converted] = $case;
    $registrationText = $case[2] ?? null;
    $secondaryLabels = $case[3] ?? array();
    $expectedChildren = $case[4] ?? null;
    $prose = $case[5] ?? array();
    $result = ( new HtmlTransformer() )->transform('<h2>Member access</h2><form name="loginform" id="loginform" action="/Auth/Register" method="post">' . $controls . '</form><p>After the form</p>')->toArray();
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
        $buttons = $named('core/button');
        $expected = array_merge(null === $registrationText ? array() : array( $registrationText ), $secondaryLabels);
        $assert($expected === array_map(static fn (array $button): ?string => $button['attrs']['text'] ?? null, $buttons), "{$label}: editable button text in source order");
        if ( null !== $registrationText ) {
            $assert('/wp-login.php?action=register' === ($buttons[0]['attrs']['url'] ?? null), "{$label}: WordPress registration URL");
        }
        if ( array() !== $expected ) {
            $children = $named('core/group')[0]['innerBlocks'] ?? array();
            $assert(($expectedChildren ?? array_merge(array( 'core/loginout' ), array_fill(0, count($expected), 'core/buttons'))) === array_column($children, 'blockName'), "{$label}: login, prose and buttons in source order");
            $paragraphs = array_values(array_filter($children, static fn (array $block): bool => 'core/paragraph' === $block['blockName']));
            $assert($prose === array_column(array_column($paragraphs, 'attrs'), 'content'), "{$label}: editable interleaved prose only");
        }
        foreach ( array( '/Auth/Register', 'source-secret', 'firstName', 'redirect_to', 'rememberme', 'user_pass', 'capture-native-control-state' ) as $dropped ) {
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
