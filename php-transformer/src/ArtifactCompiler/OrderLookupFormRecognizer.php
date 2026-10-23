<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

/**
 * One generic predicate for order-lookup forms, so a store's "where is my
 * order" form is declared for the shop capability instead of reaching a form
 * provider that can never answer it (a shop provider owns order tracking).
 *
 * The form qualifies when it has exactly two user-entered controls — an order
 * identifier and the email used at checkout — plus a submit control. Detection
 * reads structural/semantic signals on the published control metadata: input
 * types, the `autocomplete` attribute, and order/email vocabulary in a
 * control's name, id, label or placeholder. Never a site-specific class name,
 * provider name, or fixture string.
 *
 * Hidden inputs do not count toward the two fields (honeypots never reach the
 * published control list either), and a form carrying any other user field
 * (name, message, topic, …) stays a generic form.
 */
final class OrderLookupFormRecognizer
{
    /**
     * Recognize the published controls of one form as an order lookup.
     *
     * @param array<int, mixed> $controls The finding's published control metadata.
     * @return array{fields: array{order: array<string, string>, email: array<string, string>}, submit_label: string}|null
     *     The order and email fields with their label and placeholder, and the
     *     submit control's label, or null when the form is not an order lookup.
     */
    public static function recognize(array $controls): ?array
    {
        $order = null;
        $email = null;
        $submitLabel = '';
        foreach ( array_values(array_filter($controls, 'is_array')) as $control ) {
            $type = strtolower((string) ($control['type'] ?? ''));
            if ( in_array($type, array( 'submit', 'button', 'image', 'reset' ), true) ) {
                if ( 'submit' === $type && '' === $submitLabel ) {
                    $submitLabel = self::text($control, 'text');
                }
                continue;
            }
            if ( ! in_array(strtolower((string) ($control['tag'] ?? '')), array( 'input', 'select', 'textarea' ), true) || 'hidden' === $type ) {
                continue;
            }
            // A user-entered control that is neither the email nor the order
            // identifier (name, message, topic, …), or a second one of either,
            // means the form asks for more than an order lookup, so it stays a
            // generic form.
            if ( self::isEmailControl($control) ) {
                if ( null !== $email ) {
                    return null;
                }
                $email = $control;
                continue;
            }
            if ( self::isOrderControl($control) ) {
                if ( null !== $order ) {
                    return null;
                }
                $order = $control;
                continue;
            }
            return null;
        }

        if ( null === $order || null === $email ) {
            return null;
        }

        $field = static fn (array $control): array => array_filter(array(
            'label'       => self::text($control, 'label'),
            'placeholder' => self::text($control, 'placeholder'),
        ), static fn (string $value): bool => '' !== $value);

        return array(
            'fields' => array(
                'order' => $field($order),
                'email' => $field($email),
            ),
            'submit_label' => $submitLabel,
        );
    }

    /** Whether the control is the buyer's email: `type=email` or `autocomplete=email`. */
    private static function isEmailControl(array $control): bool
    {
        if ( 'email' === strtolower((string) ($control['type'] ?? '')) ) {
            return true;
        }

        return 1 === preg_match('/(?<![a-z0-9])email(?![a-z0-9])/i', (string) ($control['autocomplete'] ?? ''));
    }

    /**
     * Whether the control is an order identifier: a text or number input whose
     * name, id, label or placeholder reads as an order number or ID ("order",
     * "order #", "order number", "order id").
     */
    private static function isOrderControl(array $control): bool
    {
        if ( ! in_array(strtolower((string) ($control['type'] ?? '')), array( 'text', 'number' ), true) ) {
            return false;
        }

        $parts = array();
        foreach ( array( 'name', 'id', 'label', 'placeholder' ) as $key ) {
            $value = self::text($control, $key);
            if ( '' !== $value ) {
                $parts[] = $value;
            }
        }
        if ( array() === $parts ) {
            return false;
        }

        $haystack = strtolower(implode(' ', $parts));
        if ( 1 === preg_match('/(?<![a-z0-9])order(?![a-z0-9])/', $haystack) ) {
            return true;
        }

        // A camelCase or joined name ("orderNumber", "orderid") has no token
        // boundary around "order", so match the joined vocabulary too.
        $joined = strtolower(preg_replace('/[^a-z0-9]+/', '', $haystack) ?? '');

        return in_array($joined, array( 'order', 'orderno', 'ordernumber', 'orderid' ), true);
    }

    /** The control's trimmed string value for one metadata key, or ''. */
    private static function text(array $control, string $key): string
    {
        $value = $control[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
