<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\Architecture\LayerRules;
use Tests\Support\Architecture\SourceFile;
use Tests\Support\Architecture\SourceTree;

/**
 * The layer rules of the framework/laravel guideline, checked over every PHP file in app/. They guard
 * the dependency direction from the first commit, before any class exists: a violation is fixed in the
 * code, never by loosening a rule. LayerRuleDetectionTest proves that each rule reports a breach, so a
 * pass here is not vacuous.
 */
final class LayerDependencyTest extends TestCase
{
    /** @var list<SourceFile>|null */
    private static ?array $application = null;

    public function test_domain_outside_cqrs_and_providers_is_framework_free(): void
    {
        $violations = LayerRules::frameworkFreeDomain(self::application());

        self::assertSame([], $violations, "The framework-free domain imports what it must not:\n" . LayerRules::describe($violations));
    }

    public function test_cqrs_handlers_use_only_eloquent_models_from_infrastructure(): void
    {
        $violations = LayerRules::cqrsReadsOnlyModelsFromInfrastructure(self::application());

        self::assertSame([], $violations, "CQRS handlers import infrastructure other than models:\n" . LayerRules::describe($violations));
    }

    public function test_application_layer_does_not_depend_on_infrastructure(): void
    {
        $violations = LayerRules::applicationIndependentOfInfrastructure(self::application());

        self::assertSame([], $violations, "The application layer imports infrastructure:\n" . LayerRules::describe($violations));
    }

    /**
     * The scan of app/, read once for every rule of the class.
     *
     * @return list<SourceFile>
     */
    private static function application(): array
    {
        return self::$application ??= SourceTree::scan('app', 'App');
    }
}
