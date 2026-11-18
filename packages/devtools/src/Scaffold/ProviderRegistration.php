<?php

declare(strict_types=1);

namespace Packman\DevTools\Scaffold;

use Packman\Attributes\Api;

/** Registry-aware helper formatting PSR-4 composer/provider registrations cleanly without duplicate entries. */
#[Api(since: '1.0')]
final class ProviderRegistration
{
    public static function registerInComposerJson(string $composerJsonContent, Place $place): string
    {
        $decoded = json_decode($composerJsonContent, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            return $composerJsonContent;
        }

        if (! is_array($decoded['autoload'] ?? null)) {
            $decoded['autoload'] = [];
        }

        if (! is_array($decoded['autoload']['psr-4'] ?? null)) {
            $decoded['autoload']['psr-4'] = [];
        }

        $namespaceKey = rtrim($place->namespace, '\\').'\\';
        $decoded['autoload']['psr-4'][$namespaceKey] = $place->relativeDirectory.'/src/';
        ksort($decoded['autoload']['psr-4'], SORT_STRING);

        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * Inserts `$provider::class` into a nested literal PHP array (`return [...]`) along `$keyPath`,
     * preserving comments and indentation. Returns `$source` unchanged when already listed, or `null`
     * when the array path is not recognized.
     *
     * @param  non-empty-list<string>  $keyPath
     */
    public static function registerInPhpArray(string $source, array $keyPath, string $provider): ?string
    {
        $tokens = [];
        $offset = 0;

        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = ['text' => $text, 'offset' => $offset];
            }

            $offset += strlen($text);
        }

        $start = null;
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if ($depth === 0 && $token['text'] === 'return' && ($tokens[$index + 1]['text'] ?? null) === '[') {
                $start = $index + 1;

                break;
            }

            if (in_array($token['text'], ['[', '(', '{'], true)) {
                $depth++;
            } elseif (in_array($token['text'], [']', ')', '}'], true)) {
                $depth--;
            }
        }

        if ($start === null) {
            return null;
        }

        $current = [$start, 0, 0];
        foreach ($keyPath as $segment) {
            $found = self::arrayProperty($tokens, $current[0], $segment);

            if ($found === null) {
                return null;
            }

            $current = $found;
        }

        [$open, $close, $key] = $current;
        $entries = implode('', array_column(array_slice($tokens, $open + 1, $close - $open - 1), 'text'));
        $cleanProvider = ltrim($provider, '\\');

        if (preg_match('/(?<![\w\\\\])\\\\?'.preg_quote($cleanProvider, '/').'::class\b/', $entries) === 1) {
            return $source;
        }

        return self::insertProvider($source, $cleanProvider, $tokens[$open]['offset'] + 1, $tokens[$key]['offset']);
    }

    /**
     * @param  list<array{text: string, offset: int}>  $tokens
     * @return array{int, int, int}|null
     */
    private static function arrayProperty(array $tokens, int $start, string $key): ?array
    {
        $depth = 1;
        $found = null;
        $counter = count($tokens);

        for ($index = $start + 1; $index < $counter; $index++) {
            $text = $tokens[$index]['text'];

            if ($depth === 1 && in_array($text, ["'".$key."'", '"'.$key.'"'], true)
                && ($tokens[$index + 1]['text'] ?? null) === '=>'
                && ($tokens[$index + 2]['text'] ?? null) === '[') {
                $found = [$index + 2, $index];
            }

            if (in_array($text, ['[', '(', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [']', ')', '}'], true)) {
                $depth--;

                if ($found !== null && $depth === 1) {
                    return [$found[0], $index, $found[1]];
                }

                if ($depth === 0) {
                    return null;
                }
            }
        }

        return null;
    }

    private static function insertProvider(string $source, string $provider, int $open, int $key): string
    {
        $line = $provider.'::class,';

        if (preg_match('/\G\s*\]/', $source, $closed, 0, $open) === 1) {
            $indent = self::indentOf($source, $key);

            return substr($source, 0, $open)."\n".$indent.'    '.$line."\n".$indent.']'.substr($source, $open + strlen($closed[0]));
        }

        $indent = preg_match('/\G[ \t]*\R([ \t]*)\S/', $source, $next, 0, $open) === 1
            ? $next[1]
            : self::indentOf($source, $key).'    ';

        return substr($source, 0, $open)."\n".$indent.$line.substr($source, $open);
    }

    private static function indentOf(string $source, int $offset): string
    {
        $start = strrpos(substr($source, 0, $offset), "\n");
        $lineStart = $start === false ? 0 : $start + 1;
        $line = substr($source, $lineStart, $offset - $lineStart);
        $whitespaceLen = strlen($line) - strlen(ltrim($line));

        return $whitespaceLen > 0 ? substr($line, 0, $whitespaceLen) : '';
    }
}
