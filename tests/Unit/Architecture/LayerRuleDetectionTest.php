<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Architecture\LayerRules;
use Tests\Support\Architecture\SourceFile;
use Tests\Support\Architecture\SourceTree;
use Tests\Support\Architecture\Violation;

/**
 * Proves that each layer rule reports a breach, so that LayerDependencyTest passing over an empty or
 * compliant app/ means something. The samples are in-memory source code; nothing here is autoloaded or
 * analysed as application code. A new rule or kind of breach gets a sample here.
 */
final class LayerRuleDetectionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideFrameworkFreeDomainSamples(): iterable
    {
        yield 'a facade import' => [<<<'PHP'
            <?php
            namespace App\Domain\Offer;
            use Illuminate\Support\Facades\DB;
            final class OfferCalculator {}
            PHP, ['Illuminate\Support\Facades\DB']];

        yield 'an Eloquent class written fully qualified in the code' => [<<<'PHP'
            <?php
            namespace App\Domain\Offer;
            final class OfferCalculator { public function f(\Illuminate\Database\Eloquent\Model $model): void {} }
            PHP, ['Illuminate\Database\Eloquent\Model']];

        yield 'a grouped import of HTTP classes' => [<<<'PHP'
            <?php
            namespace App\Domain\Offer;
            use Illuminate\Http\{Request, Response as HttpResponse};
            PHP, ['Illuminate\Http\Request', 'Illuminate\Http\Response']];

        yield 'a global facade alias' => [<<<'PHP'
            <?php
            namespace App\Domain\Offer;
            use DB;
            PHP, ['Illuminate\Support\Facades\DB (alias DB)']];

        yield 'the container, the request and the session reached through helpers' => [<<<'PHP'
            <?php
            namespace App\Domain\Offer;
            final class OfferCalculator { public function f(): void { app(Rates::class); request()->input('rate'); \session('rate'); $this->request(); } }
            PHP, ['Illuminate\Container\Container (app())', 'Illuminate\Http\Request (request())', 'Illuminate\Session\Store (session())']];

        yield 'container and session contracts' => [<<<'PHP'
            <?php
            namespace App\Domain\Offer;
            use Illuminate\Contracts\Container\Container;
            use Illuminate\Contracts\Session\Session;
            PHP, ['Illuminate\Contracts\Container\Container', 'Illuminate\Contracts\Session\Session']];

        yield 'an infrastructure model' => [<<<'PHP'
            <?php
            namespace App\Domain\Economics;
            use App\Infrastructure\Models\Project;
            PHP, ['App\Infrastructure\Models\Project']];

        yield 'application contracts and events' => [<<<'PHP'
            <?php
            namespace App\Domain\Economics;
            use App\Application\Contract\Export\Exporter;
            use App\Application\Event\Economics\TotalChanged;
            PHP, ['App\Application\Contract\Export\Exporter', 'App\Application\Event\Economics\TotalChanged']];

        yield 'a namespace that only starts like Dto is not allowed' => [<<<'PHP'
            <?php
            namespace App\Domain\Economics;
            use App\Application\DtoCache\Warmer;
            PHP, ['App\Application\DtoCache\Warmer']];

        yield 'domain classes, application DTOs, collections, a trait use and a closure use are allowed' => [<<<'PHP'
            <?php
            namespace App\Domain\Offer;
            use App\Application\Dto\Total;
            use App\Domain\Shared\Money;
            use Illuminate\Support\Collection;
            final class OfferCalculator { use Calculates; public function f(): void { $g = function () use ($x) {}; } }
            PHP, []];

        yield 'CQRS handlers are outside this rule' => [<<<'PHP'
            <?php
            namespace App\Domain\CQRS\Query\Offer;
            use App\Infrastructure\Models\Offer;
            use Illuminate\Support\Facades\DB;
            PHP, []];

        yield 'providers are exempt: container, facades and infrastructure' => [<<<'PHP'
            <?php
            namespace App\Domain\Providers;
            use App\Infrastructure\Cms\JenkinsClient;
            use Illuminate\Support\Facades\Route;
            use Illuminate\Support\ServiceProvider;
            final class CmsServiceProvider extends ServiceProvider { public function boot(): void { app(JenkinsClient::class); } }
            PHP, []];

        yield 'a namespace that only starts like Providers is not exempt' => [<<<'PHP'
            <?php
            namespace App\Domain\ProvidersOfGoods;
            use Illuminate\Support\Facades\Route;
            PHP, ['Illuminate\Support\Facades\Route']];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideCqrsSamples(): iterable
    {
        yield 'a controller' => [<<<'PHP'
            <?php
            namespace App\Domain\CQRS\Command\Offer;
            use App\Infrastructure\Http\Controllers\OfferController;
            PHP, ['App\Infrastructure\Http\Controllers\OfferController']];

        yield 'a policy written fully qualified' => [<<<'PHP'
            <?php
            namespace App\Domain\CQRS\Query\Offer;
            final class GetOfferQueryHandler { public function f(): void { \App\Infrastructure\Policies\OfferPolicy::class; } }
            PHP, ['App\Infrastructure\Policies\OfferPolicy']];

        yield 'a grouped import of an infrastructure class' => [<<<'PHP'
            <?php
            namespace App\Domain\CQRS\Query\Offer;
            use App\Infrastructure\{Models\Offer, Cms\PublicMedia};
            PHP, ['App\Infrastructure\Cms\PublicMedia']];

        yield 'a namespace that only starts like Models' => [<<<'PHP'
            <?php
            namespace App\Domain\CQRS\Query\Offer;
            use App\Infrastructure\ModelsCache\Warmer;
            PHP, ['App\Infrastructure\ModelsCache\Warmer']];

        yield 'models, application, facades and the domain are allowed' => [<<<'PHP'
            <?php
            namespace App\Domain\CQRS\Command\Offer;
            use App\Application\Dto\Total;
            use App\Domain\Offer\OfferCalculator;
            use App\Infrastructure\Models\Offer;
            use Illuminate\Support\Facades\DB;
            PHP, []];

        yield 'the rest of the domain is outside this rule' => [<<<'PHP'
            <?php
            namespace App\Domain\Offer;
            use App\Infrastructure\Cms\JenkinsClient;
            PHP, []];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideApplicationSamples(): iterable
    {
        yield 'a model' => [<<<'PHP'
            <?php
            namespace App\Application\Dto;
            use App\Infrastructure\Models\Project;
            PHP, ['App\Infrastructure\Models\Project']];

        yield 'an exporter in a grouped import' => [<<<'PHP'
            <?php
            namespace App\Application\Contract\Export;
            use App\Infrastructure\{Export\XlsxExporter, Logging\Channel};
            PHP, ['App\Infrastructure\Export\XlsxExporter', 'App\Infrastructure\Logging\Channel']];

        yield 'a fully qualified class in the code' => [<<<'PHP'
            <?php
            namespace App\Application\Cms;
            final class Sample { public function f(): void { \App\Infrastructure\Cms\PublicMedia::name('a', 'b'); } }
            PHP, ['App\Infrastructure\Cms\PublicMedia']];

        yield 'the domain and the framework are allowed' => [<<<'PHP'
            <?php
            namespace App\Application\Dto;
            use App\Domain\Offer\OfferCalculator;
            use Illuminate\Support\Collection;
            use Illuminate\Support\Facades\Cache;
            PHP, []];
    }

    /**
     * @param  list<string>  $expectedSubjects
     */
    #[DataProvider('provideFrameworkFreeDomainSamples')]
    public function test_framework_free_domain_rule_reports_each_forbidden_dependency(string $code, array $expectedSubjects): void
    {
        $violations = LayerRules::frameworkFreeDomain([SourceFile::fromCode('app/Domain/Sample.php', $code, 'App\Domain')]);

        self::assertSame($expectedSubjects, self::subjects($violations));
    }

    /**
     * @param  list<string>  $expectedSubjects
     */
    #[DataProvider('provideCqrsSamples')]
    public function test_cqrs_rule_reports_infrastructure_other_than_models(string $code, array $expectedSubjects): void
    {
        $violations = LayerRules::cqrsReadsOnlyModelsFromInfrastructure([SourceFile::fromCode('app/Domain/CQRS/Sample.php', $code)]);

        self::assertSame($expectedSubjects, self::subjects($violations));
    }

    /**
     * @param  list<string>  $expectedSubjects
     */
    #[DataProvider('provideApplicationSamples')]
    public function test_application_rule_reports_every_infrastructure_dependency(string $code, array $expectedSubjects): void
    {
        $violations = LayerRules::applicationIndependentOfInfrastructure([SourceFile::fromCode('app/Application/Sample.php', $code)]);

        self::assertSame($expectedSubjects, self::subjects($violations));
    }

    public function test_a_file_without_a_namespace_takes_the_one_of_its_directory(): void
    {
        $violations = LayerRules::frameworkFreeDomain([SourceFile::fromCode('app/Domain/Sample.php', "<?php\nuse Illuminate\\Http\\Request;", 'App\Domain')]);

        self::assertSame(['Illuminate\Http\Request'], self::subjects($violations));
    }

    public function test_violation_names_the_file_line_dependency_and_rule(): void
    {
        $violations = LayerRules::applicationIndependentOfInfrastructure([SourceFile::fromCode('app/Application/Offer/Quote.php', <<<'PHP'
            <?php
            namespace App\Application\Offer;

            use App\Infrastructure\Models\Offer;
            PHP)]);

        self::assertSame(
            'app/Application/Offer/Quote.php:4 uses App\Infrastructure\Models\Offer - App\Application must not depend on App\Infrastructure',
            LayerRules::describe($violations),
        );
    }

    /**
     * The tree scan is what LayerDependencyTest relies on; an empty result would make every rule pass.
     * The AppServiceProvider is shipped by the template and lives in the exempt namespace.
     */
    public function test_tree_scan_reads_every_php_file_with_its_namespace_and_imports(): void
    {
        $files = SourceTree::scan('app', 'App');
        $provider = array_values(array_filter($files, static fn (SourceFile $file): bool => $file->path === 'app/Domain/Providers/AppServiceProvider.php'));

        self::assertCount(1, $provider);
        self::assertSame(LayerRules::PROVIDERS, $provider[0]->namespace);
        self::assertContains('Illuminate\Support\ServiceProvider', array_column($provider[0]->dependencies, 'name'));
    }

    /**
     * @param  list<Violation>  $violations
     * @return list<string>
     */
    private static function subjects(array $violations): array
    {
        return array_map(static fn (Violation $violation): string => $violation->subject, $violations);
    }
}
