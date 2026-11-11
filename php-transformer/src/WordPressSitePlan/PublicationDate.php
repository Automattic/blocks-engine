<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * Publication dates as the site plan reads them: machine-readable timestamps
 * and the dates a reader sees on the page, both as canonical UTC instants.
 */
final class PublicationDate
{
    /** Whether a value is a canonical `Y-m-d\TH:i:s\Z` UTC instant that names a real date. */
    public static function isUtc(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value)) return false;
        try { return (new \DateTimeImmutable($value))->format('Y-m-d\\TH:i:s\\Z') === $value; } catch (\Exception) { return false; }
    }

    /**
     * A machine-readable date (`Y-m-d`, or ISO 8601 with a `Z` or numeric offset) as a UTC instant.
     * `$localTime` receives the validated input when it carries a non-zero offset.
     */
    public static function normalize(string $value, ?string &$localTime = null): ?string
    {
        $localTime = null;
        $format = null; $input = $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) $format = '!Y-m-d';
        // ISO 8601 allows the basic offset form (-0500); normalize it to -05:00.
        elseif (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d+)?(?:(Z)|([+-]\d{2}):?(\d{2}))$/', $value, $match)) { $input = $match[1] . ('Z' === $match[2] ? '+00:00' : $match[3] . ':' . $match[4]); $format = '!Y-m-d\\TH:i:sP'; }
        if (null === $format) return null;
        $timestamp = self::utcFromFormat($format, $input);
        // The same validated, seconds-precision input used for UTC conversion
        // retains its explicit offset; date-only and zero-offset values do not.
        if (null !== $timestamp && '!Y-m-d' !== $format && !in_array(substr($input, -6), array('+00:00', '-00:00'), true)) $localTime = $input;
        return $timestamp;
    }

    /** A date as a reader sees it on the page (numeric, dotted day-first, or a month name in en, fr, es, de, it, pt or nl) as a UTC instant. */
    public static function fromVisibleText(string $value): ?string
    {
        return self::visible($value)[0] ?? null;
    }

    /**
     * The PHP date format that reprints a visible date as written, so a
     * post-date block shows every post's date in its source form.
     */
    public static function visibleFormat(string $value): ?string
    {
        return self::visible($value)[1] ?? null;
    }

    /**
     * Recognizing a visible date and naming the form it is written in are one
     * decision: every form read here also reprints as read.
     *
     * @return array{0:string,1:?string}|null The UTC instant, and the format that reprints it.
     */
    private static function visible(string $value): ?array
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        if ('' === $value) return null;
        // Builder bylines commonly prefix a full date with its weekday.
        $date = preg_replace('/^(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s+/i', '', $value) ?? $value;
        $weekday = $date === $value ? '' : 'l, ';
        $value = $date;
        $iso = self::normalize($value);
        if (null !== $iso) return array($iso, preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $weekday . 'Y-m-d' : null);
        if (preg_match('/^(\d{1,2})([\/\-])(\d{1,2})([\/\-])(\d{4})$/', $value, $match)) {
            $first = (int) $match[1];
            $second = (int) $match[3];
            if ($first > 31 || $second > 31 || $first < 1 || $second < 1) return null;
            $dayFirst = $first > 12 && $second <= 12;
            $parsed = self::utcFromFormat($dayFirst ? '!j/n/Y' : '!n/j/Y', $first . '/' . $second . '/' . $match[5]);
            return null === $parsed ? null : array($parsed, $weekday . self::numericFormat($match[1], $match[3], $dayFirst, $match[2], $match[4]));
        }
        // A dotted numeric date is day-first wherever it is written (de, ru, pl, cs, fi, tr).
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $match)) {
            $parsed = self::utcFromFormat('!j.n.Y', (int) $match[1] . '.' . (int) $match[2] . '.' . $match[3]);
            return null === $parsed ? null : array($parsed, $weekday . self::numericFormat($match[1], $match[2], true, '.', '.'));
        }
        // A closed vocabulary, not locale/strtotime guessing. All aliases in a
        // row denote the same month across fr, es, de, it, pt and nl.
        $months = array(
            'Jan' => 'jan january janvier janv enero ene januar gennaio gen janeiro januari',
            'Feb' => 'feb febr february fevrier fevr febrero februar febbraio fevereiro fev februari',
            'Mar' => 'mar march mars marzo marz maerz mrz marco maart mrt',
            'Apr' => 'apr april avril avr abril abr aprile',
            'May' => 'may mai mayo maggio mag maio mei',
            'Jun' => 'jun june juin junio juni giugno giu junho',
            'Jul' => 'jul july juillet juil juill julio juli luglio lug julho',
            'Aug' => 'aug august aout agosto ago augustus',
            'Sep' => 'sep sept september septembre septiembre setiembre settembre set setembro',
            'Oct' => 'oct october octobre octubre oktober okt ottobre ott outubro out',
            'Nov' => 'nov november novembre noviembre novembro',
            'Dec' => 'dec december decembre diciembre dic dezember dez dicembre dezembro',
        );
        $folded = strtr(mb_strtolower($value, 'UTF-8'), array('é' => 'e', 'û' => 'u', 'ä' => 'a', 'ç' => 'c'));
        // Also accept decomposed accents, without relying on intl or a locale.
        $folded = preg_replace('/(?<=[a-z])\p{M}+/u', '', $folded) ?? $folded;
        $match = array();
        $format = null;
        $dayFirst = 1 === preg_match('/^(\d{1,2})(\. ?| )(de )?([a-z]{3,12})(\.?) (de )?(\d{4})$/', $folded, $match);
        if ($dayFirst || preg_match('/^([a-z]{3,12})(\.?) (\d{1,2}), (\d{4})$/', $folded, $match)) {
            $token = $match[$dayFirst ? 4 : 1];
            foreach ($months as $month => $aliases) {
                if (in_array($token, explode(' ', $aliases), true)) {
                    $value = $dayFirst ? $match[1] . ' ' . $month . ' ' . $match[7] : $month . ' ' . $match[3] . ', ' . $match[4];
                    // An abbreviated source month reprints abbreviated; WordPress
                    // names the month in the site language either way.
                    $name = '' !== $match[$dayFirst ? 5 : 2] || strlen($token) <= 3 ? 'M' : 'F';
                    $day = '0' === $match[$dayFirst ? 1 : 3][0] ? 'd' : 'j';
                    $format = $dayFirst
                        ? $day . $match[2] . ('' !== $match[3] ? '\\d\\e ' : '') . $name . ' ' . ('' !== $match[6] ? '\\d\\e ' : '') . 'Y'
                        : $name . ' ' . $day . ', Y';
                    break;
                }
            }
        }
        foreach (array('!F j, Y', '!M j, Y', '!j F Y', '!j M Y') as $candidate) {
            $parsed = self::utcFromFormat($candidate, $value);
            if (null !== $parsed) return array($parsed, null === $format ? null : $weekday . $format);
        }
        return null;
    }

    /** Numeric day and month tokens keep the source's zero padding and separators. */
    private static function numericFormat(string $first, string $second, bool $dayFirst, string $separator, string $yearSeparator): string
    {
        [$day, $month] = '0' === $first[0] || '0' === $second[0] ? array('d', 'm') : array('j', 'n');
        return ($dayFirst ? $day . $separator . $month : $month . $separator . $day) . $yearSeparator . 'Y';
    }

    private static function utcFromFormat(string $format, string $input): ?string
    {
        $date = \DateTimeImmutable::createFromFormat($format, $input);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) return null;
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }

    /** @param array<string,mixed> $attributes */
    public static function isVisibleElement(string $name, array $attributes): bool
    {
        if ('time' === $name) {
            return 'datePublished' === ($attributes['itemprop'] ?? null);
        }
        // Blogger heads each post's date as <h2 class="date-header">.
        if (!in_array($name, array('p', 'span', 'div', 'li', 'td', 'mark', 'h2', 'h3', 'h4', 'h5', 'h6'), true)) {
            return false;
        }
        $tokens = strtolower(trim(html_entity_decode((string) ($attributes['class'] ?? '') . ' ' . (string) ($attributes['id'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return 1 === preg_match('/\b(?:date|published|pubdate)\b/', $tokens);
    }
}
