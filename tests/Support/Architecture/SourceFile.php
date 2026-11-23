<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use PhpToken;

/**
 * What one PHP file declares and depends on, read with PHP's tokenizer (no reflection, no autoload).
 *
 * Dependencies are the names imported with `use` (single, grouped, `use function`, `use const`) and
 * every fully qualified name written in the code. A global facade alias (`use DB;`) counts as the
 * facade it stands for, and the helpers `app()`, `resolve()`, `request()` and `session()` count as
 * the container, the request and the session they reach. A trait `use` inside a class and a
 * closure's `use (...)` are not imports.
 */
final readonly class SourceFile
{
    /** Global helpers that reach into the framework, and the class each one stands for. */
    private const array FRAMEWORK_HELPERS = [
        'app' => 'Illuminate\Container\Container',
        'resolve' => 'Illuminate\Container\Container',
        'request' => 'Illuminate\Http\Request',
        'session' => 'Illuminate\Session\Store',
    ];

    /** Aliases Laravel registers for its facades; `use DB;` is `use Illuminate\Support\Facades\DB;`. */
    private const array FACADE_ALIASES = [
        'App', 'Auth', 'Blade', 'Broadcast', 'Bus', 'Cache', 'Config', 'Context', 'Cookie', 'Crypt', 'Date', 'DB', 'Event', 'File', 'Gate',
        'Hash', 'Http', 'Lang', 'Log', 'Mail', 'Notification', 'Password', 'Process', 'Queue', 'RateLimiter', 'Redirect', 'Request',
        'Response', 'Route', 'Schedule', 'Schema', 'Session', 'Storage', 'URL', 'Validator', 'View', 'Vite',
    ];

    /**
     * @param  list<array{name: string, line: int}>  $dependencies
     */
    private function __construct(
        public string $path,
        public string $namespace,
        public array $dependencies,
    ) {}

    /**
     * @param  string  $path  the path shown in a violation, relative to the project root
     * @param  string  $namespace  the namespace assumed when the file declares none
     */
    public static function fromCode(string $path, string $code, string $namespace = ''): self
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($code),
            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));

        $declared = null;
        $dependencies = [];
        $depth = 0;
        $classDepths = [];
        $classPending = false;
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            $previous = $tokens[$index - 1] ?? null;

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if ($classPending && $token->text === '{') {
                    $classDepths[] = $depth;
                    $classPending = false;
                }

                continue;
            }

            if ($token->text === '}') {
                if ($classDepths !== [] && end($classDepths) === $depth) {
                    array_pop($classDepths);
                }
                $depth--;

                continue;
            }

            // `Foo::class` is not a declaration.
            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && ! $previous?->is(T_DOUBLE_COLON)) {
                $classPending = true;

                continue;
            }

            if ($token->is(T_NAMESPACE) && ($tokens[$index + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED]) === true) {
                $declared = $tokens[$index + 1]->text;
                $index++;

                continue;
            }

            if ($token->is(T_USE) && $classDepths === [] && ! ($tokens[$index + 1] ?? null)?->is('(')) {
                $index = self::readImport($tokens, $index + 1, $dependencies);

                continue;
            }

            if ($token->is(T_NAME_FULLY_QUALIFIED)) {
                $name = ltrim($token->text, '\\');
                $helper = self::FRAMEWORK_HELPERS[$name] ?? null;
                if (($tokens[$index + 1] ?? null)?->text === '(' && $helper !== null) {
                    $dependencies[] = ['name' => sprintf('%s (%s())', $helper, $name), 'line' => $token->line];
                } else {
                    $dependencies[] = ['name' => $name, 'line' => $token->line];
                }

                continue;
            }

            if (
                $token->is(T_STRING)
                && isset(self::FRAMEWORK_HELPERS[$token->text])
                && ($tokens[$index + 1] ?? null)?->text === '('
                && ! $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW])
            ) {
                $dependencies[] = ['name' => sprintf('%s (%s())', self::FRAMEWORK_HELPERS[$token->text], $token->text), 'line' => $token->line];
            }
        }

        return new self($path, $declared ?? $namespace, $dependencies);
    }

    public static function fromFile(string $file, string $path, string $namespace = ''): self
    {
        $code = file_get_contents($file);

        return self::fromCode($path, $code === false ? '' : $code, $namespace);
    }

    /**
     * Reads an import after its `use` keyword; returns the index of the last token consumed.
     *
     * @param  list<PhpToken>  $tokens
     * @param  list<array{name: string, line: int}>  $dependencies
     */
    private static function readImport(array $tokens, int $index, array &$dependencies): int
    {
        $count = count($tokens);

        while ($index < $count && $tokens[$index]->text !== ';') {
            $token = $tokens[$index];

            if ($token->is([T_NAME_QUALIFIED, T_STRING, T_NAME_FULLY_QUALIFIED]) && ! ($tokens[$index - 1] ?? null)?->is(T_AS)) {
                $name = ltrim($token->text, '\\');
                $next = $tokens[$index + 1] ?? null;

                if ($next?->is(T_NS_SEPARATOR) === true && ($tokens[$index + 2] ?? null)?->text === '{') {
                    // Grouped import: use Foo\{A, B as C};
                    for ($index += 3; $index < $count && $tokens[$index]->text !== '}'; $index++) {
                        if ($tokens[$index]->is([T_STRING, T_NAME_QUALIFIED]) && ! $tokens[$index - 1]->is(T_AS)) {
                            $dependencies[] = ['name' => $name . '\\' . $tokens[$index]->text, 'line' => $tokens[$index]->line];
                        }
                    }
                } elseif ( ! $token->is(T_STRING) || ! in_array(strtolower($token->text), ['function', 'const'], true)) {
                    $facade = $token->is(T_STRING) && in_array($name, self::FACADE_ALIASES, true) ? sprintf('Illuminate\Support\Facades\%s (alias %s)', $name, $name) : $name;
                    $dependencies[] = ['name' => $facade, 'line' => $token->line];
                }
            }

            $index++;
        }

        return $index;
    }
}
