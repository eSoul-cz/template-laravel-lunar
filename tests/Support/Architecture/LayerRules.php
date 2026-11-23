<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

/**
 * The layer rules of the framework/laravel guideline (architecture.md, testing.md#architecture-tests).
 * Every rule returns its violations; an empty list means the rule holds.
 */
final class LayerRules
{
    public const string DOMAIN = 'App\Domain';

    public const string CQRS = 'App\Domain\CQRS';

    /** The one namespace of the domain that may use the container, facades and the framework. */
    public const string PROVIDERS = 'App\Domain\Providers';

    public const string APPLICATION = 'App\Application';

    public const string INFRASTRUCTURE = 'App\Infrastructure';

    public const string MODELS = 'App\Infrastructure\Models';

    /** What the framework-free core must not touch. */
    private const array FRAMEWORK = [
        'Illuminate\Database',
        'Illuminate\Support\Facades',
        'Illuminate\Http',
        'Illuminate\Container',
        'Illuminate\Contracts\Container',
        'Illuminate\Session',
        'Illuminate\Contracts\Session',
    ];

    /**
     * App\Domain outside CQRS and Providers: no framework, no application, no infrastructure.
     *
     * @param  list<SourceFile>  $files
     * @return list<Violation>
     */
    public static function frameworkFreeDomain(array $files): array
    {
        $rule = 'App\Domain outside App\Domain\CQRS and App\Domain\Providers must be framework-free and depend on neither App\Application nor App\Infrastructure';

        return self::violations(
            array_filter(
                $files,
                static fn (SourceFile $file): bool => self::within($file->namespace, self::DOMAIN)
                    && ! self::within($file->namespace, self::CQRS)
                    && ! self::within($file->namespace, self::PROVIDERS),
            ),
            [...self::FRAMEWORK, self::APPLICATION, self::INFRASTRUCTURE],
            [],
            $rule,
        );
    }

    /**
     * App\Domain\CQRS (the handlers): Eloquent models yes, other infrastructure no.
     *
     * @param  list<SourceFile>  $files
     * @return list<Violation>
     */
    public static function cqrsReadsOnlyModelsFromInfrastructure(array $files): array
    {
        return self::violations(
            array_filter($files, static fn (SourceFile $file): bool => self::within($file->namespace, self::CQRS)),
            [self::INFRASTRUCTURE],
            [self::MODELS],
            'App\Domain\CQRS may use App\Infrastructure\Models and nothing else in App\Infrastructure',
        );
    }

    /**
     * App\Application: independent of infrastructure.
     *
     * @param  list<SourceFile>  $files
     * @return list<Violation>
     */
    public static function applicationIndependentOfInfrastructure(array $files): array
    {
        return self::violations(
            array_filter($files, static fn (SourceFile $file): bool => self::within($file->namespace, self::APPLICATION)),
            [self::INFRASTRUCTURE],
            [],
            'App\Application must not depend on App\Infrastructure',
        );
    }

    /** @param  list<Violation>  $violations */
    public static function describe(array $violations): string
    {
        return implode("\n", array_map(static fn (Violation $violation): string => (string) $violation, $violations));
    }

    /**
     * @param  iterable<SourceFile>  $files
     * @param  list<string>  $forbidden  namespaces (or classes) the files must not depend on
     * @param  list<string>  $allowed  namespaces inside a forbidden one that stay allowed
     * @return list<Violation>
     */
    private static function violations(iterable $files, array $forbidden, array $allowed, string $rule): array
    {
        $violations = [];

        foreach ($files as $file) {
            foreach ($file->dependencies as $dependency) {
                $name = $dependency['name'];

                if (self::matches($name, $forbidden) && ! self::matches($name, $allowed)) {
                    $violations[] = new Violation($file->path, $dependency['line'], $name, $rule);
                }
            }
        }

        return $violations;
    }

    /** @param  list<string>  $namespaces */
    private static function matches(string $name, array $namespaces): bool
    {
        foreach ($namespaces as $namespace) {
            if (self::within($name, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /** `$name` is `$namespace` or below it; `App\Domain\ProvidersX` is not within `App\Domain\Providers`. */
    private static function within(string $name, string $namespace): bool
    {
        return $name === $namespace || str_starts_with($name, $namespace . '\\') || str_starts_with($name, $namespace . ' ');
    }
}
