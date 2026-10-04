<?php
namespace SLS\MassNotify;

/** Literal, whole-phrase pronunciation substitutions; never executable markup. */
final class SpeechRules
{
    public static function normalize($value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
            throw new \DomainException('Pronunciation overrides must contain at most 50 phrases.');
        }
        $seen = [];
        foreach ($value as &$row) {
            if (!is_array($row) || count($row) !== 2 || !isset($row['phrase'], $row['spoken'])) {
                throw new \DomainException('Each pronunciation needs a phrase and its spoken replacement.');
            }
            foreach (['phrase'=>64, 'spoken'=>128] as $key=>$limit) {
                if (!is_string($row[$key]) || !preg_match('//u', $row[$key]) || trim($row[$key]) === ''
                    || mb_strlen($row[$key]) > $limit || preg_match('/[\p{C}<>=]/u', $row[$key])) {
                    throw new \DomainException('Pronunciation phrases must be plain text up to 64 characters; spoken replacements may have up to 128. Markup, control characters and = are not allowed.');
                }
                $row[$key] = preg_replace('/\s+/u', ' ', trim($row[$key]));
            }
            $key = mb_strtolower($row['phrase'], 'UTF-8');
            if (isset($seen[$key])) { throw new \DomainException('Each pronunciation phrase must be unique, ignoring capitalization.'); }
            $seen[$key] = true;
        }
        unset($row);
        return $value;
    }

    public static function fromText($text): array
    {
        if (!is_string($text) || strlen($text) > 40000) { throw new \DomainException('Pronunciation text is invalid or too large.'); }
        $rows=[];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (trim($line) === '') { continue; }
            $parts=explode('=', $line);
            if (count($parts) !== 2) { throw new \DomainException('Use one phrase = spoken replacement on each pronunciation line.'); }
            $rows[]=['phrase'=>trim($parts[0]), 'spoken'=>trim($parts[1])];
        }
        return self::normalize($rows);
    }

    public static function apply(string $message, array $rules): string
    {
        $rules = self::normalize($rules);
        if (!$rules) { return $message; }
        usort($rules, static fn($a,$b)=>mb_strlen($b['phrase']) <=> mb_strlen($a['phrase']));
        $replacements=[]; $patterns=[];
        foreach ($rules as $row) {
            $replacements[mb_strtolower($row['phrase'], 'UTF-8')]=$row['spoken'];
            $patterns[]=preg_quote($row['phrase'], '~');
        }
        $result=preg_replace_callback('~(?<![\p{L}\p{N}_])(?:'.implode('|',$patterns).')(?![\p{L}\p{N}_])~iu',
            static fn($match)=>$replacements[mb_strtolower($match[0], 'UTF-8')] ?? $match[0], $message);
        if (!is_string($result) || mb_strlen($result)>4000) {
            throw new \DomainException('Pronunciation replacements expand this message beyond 4,000 spoken characters. Shorten the replacements and preview again.');
        }
        return $result;
    }
}
