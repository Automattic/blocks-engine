<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;
use DOMText;
use DOMComment;

/** Recognizes the core comment endpoint, not arbitrary contact forms. */
final class WordPressCommentForm
{
    public static function matches(DOMElement $element): bool
    {
        if ('form' !== strtolower($element->tagName)) return false;
        $path = parse_url($element->getAttribute('action'), PHP_URL_PATH);
        if (!is_string($path) || !preg_match('~(?:^|/)wp-comments-post\.php$~', $path)) return false;
        if ('commentform' !== $element->getAttribute('id') && !in_array('comment-form', preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array(), true)) return false;
        $fields = array();
        foreach ($element->getElementsByTagName('*') as $control) {
            if (in_array(strtolower($control->tagName), array('input', 'textarea'), true)) $fields[$control->getAttribute('name')] = true;
        }
        // Logged-in forms omit author/email/url. The comment and destination
        // fields are always present; none of their captured values are reused.
        return isset($fields['comment'], $fields['comment_post_ID']);
    }

    public static function isReplyHeading(DOMElement $element): bool
    {
        if ('reply-title' !== $element->getAttribute('id') || !preg_match('/^h[1-6]$/i', $element->tagName)) return false;
        $next = $element->nextSibling;
        while ($next instanceof DOMComment || ($next instanceof DOMText && '' === trim($next->textContent))) $next = $next->nextSibling;
        return $next instanceof DOMElement && self::matches($next);
    }
}
