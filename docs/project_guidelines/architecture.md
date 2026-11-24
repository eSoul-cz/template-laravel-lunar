<!-- esoul-guidelines:begin architecture -->
# Project guidelines - Architecture

The application is a Laravel monolith: business operations are commands and queries (`esoul-cz/laravel-cqrs`) and there is one database. The user interface is the project's variant: Inertia with Vue pages, a Filament admin panel, or both. The versions are named only in [tools.md](tools.md#versions). The project's ADRs record every decision that deviates from this file.

| Part | Technology | Where | Details |
|---|---|---|---|
| User interface | The project's variant: Inertia and Vue pages and/or a Filament admin panel | `App\Infrastructure\Http`, plus the variant's own directories | [frontend.md](frontend.md) |
| Business operations | Commands and queries via `esoul-cz/laravel-cqrs` | `App\Domain\CQRS` | [cqrs.md](cqrs.md) |
| Business rules and calculations | Framework-free value objects, enums, domain services | `App\Domain\<Module>` | this file |
| Database | The engine the project's ADR chooses in production, SQLite `:memory:` in tests | `database/` | [testing.md](testing.md#database-in-tests) |
| Runtime | Docker images built by Jenkins, deployed by Komodo | `docker/`, `Jenkinsfile` | [deployment.md](deployment.md) |

<!-- from: package/lunar -->
Lunar is the shop's e-commerce core: products, variants, prices, carts, orders, customers, channels, taxes and the admin panel. The project extends it and does not replace it. A Lunar project also carries `package/filament` (its admin is a Filament panel) and, with an Inertia storefront, `package/laravel-inertia`; the rules here cover only what Lunar adds. The shop's integrations with payment gateways, carriers and ERP systems are in [integrations.md](integrations.md).

## Layers

### `App\Domain\<Module>` - the framework-free core

- Contains value objects (money, quantities, rates), enums, domain services and calculators, and the typed domain exceptions (`App\Domain\Exception`) that report a broken business rule.
- MUST NOT reference `Illuminate\Database`, an Eloquent model, a facade, the container, the request or the session. It takes plain values and returns plain values, so every rule and formula is unit-tested without a database.
- Value objects used by several modules live in `App\Domain\ValueObjects`; everything else lives in the module that owns it.
- The one exception is `App\Domain\Providers` (next section). A package layer MAY additionally allow the types of the domain model its package provides, and says so in its own architecture file.

### `App\Domain\Providers` - service providers

- Service providers live in `App\Domain\Providers` (`AppServiceProvider` and the provider of an admin panel, when the project has one) and are listed in `bootstrap/providers.php`.
- A provider binds contracts to implementations, so it uses the container, facades and `App\Infrastructure`. **It is the one place in `App\Domain` that may**, and the layer architecture test exempts exactly this namespace ([testing.md](testing.md#architecture-tests)). A provider holds wiring only, never a business rule.

### `App\Domain\CQRS` - commands, queries and their handlers

- Commands live in `App\Domain\CQRS\Command\<Module>`, queries in `App\Domain\CQRS\Query\<Module>`, each next to its handler ([cqrs.md](cqrs.md)).
- Handlers MAY read and write the Eloquent models in `App\Infrastructure\Models`. **They are the single place a business operation touches persistence**, and they own the transaction and the locks.
- Handlers delegate every calculation and business rule to the framework-free core ([cqrs.md](cqrs.md#writing-commands)).

### `App\Application`

- `App\Application\Dto\<Module>`: the DTOs queries return; what pages and exporters receive.
- `App\Application\Event\<Module>`: events a module raises for others, readonly, carrying ids.
- `App\Application\Contract`: interfaces the infrastructure implements (external clients, storage, exporters, the change history recorder). The container binds the implementation.
- Contains no business rule and no persistence, and never references a vendor SDK class.

### `App\Infrastructure`

- Eloquent models (`App\Infrastructure\Models`, including `User`), controllers (`App\Infrastructure\Http\Controllers`), middleware (`App\Infrastructure\Http\Middleware`), form requests (`App\Infrastructure\Http\Requests`), policies, casts, external clients (`App\Infrastructure\Integration\<System>`), exporters, console commands, jobs and listeners.
- Contains no business rule. Controllers, console commands, listeners and jobs dispatch commands and execute queries.
- **Controllers stay thin**: authorize through the policy, validate in a form request ([requests-and-validation.md](requests-and-validation.md)), dispatch one command or execute the queries the page needs, return the response. Nothing else.

### Dependency direction

- `App\Domain` MUST NOT depend on `App\Application` or `App\Infrastructure`, except `App\Domain\Providers` (above) and the CQRS handlers below. The framework-free core depends on nothing else in the application.
- CQRS handlers depend on the core, on `App\Application` (DTOs, events, contracts) and on the models in `App\Infrastructure\Models`, nothing else in `App\Infrastructure`. They never depend on controllers, requests or the session. Handlers talk to external systems through the contracts in `App\Application\Contract`; the container binds the implementation.
- `App\Application` MUST NOT depend on `App\Infrastructure`.
- `App\Infrastructure` depends on the commands, queries and DTOs. Models MAY use domain enums and value objects through casts.
- A repository interface MUST NOT be introduced unless a second implementation actually exists. A handler uses the Eloquent model directly.

The boundaries inside `App\Domain` (the core, `App\Domain\CQRS` which touches Eloquent, and `App\Domain\Providers`) run through the middle of a layer, so they are enforced by an architecture test that is a named exception in [testing.md](testing.md#architecture-tests).

### Fixing a layer-dependency violation

A violation reported by the layer test ([testing.md](testing.md#architecture-tests)) is fixed by moving code to the layer that may hold it, not by relaxing the rule. The class keeps its name and behaviour; only the namespace changes, and every caller and test moves with it in the same change.

- **A. Persistence or a framework call in the domain core.** The class moves into `App\Domain\CQRS` (for example next to the commands or queries it serves) as a collaborator of the handler that calls it. The pure rules stay in the core: a class is split when it mixes the two, and only the part that touches persistence or the framework moves.
- **B. A DTO the domain logic works with.** A core class that takes or returns a DTO from `App\Application\Dto` depends on the application layer. Such a DTO is a value object: it moves to `App\Domain\ValueObjects\<Module>` and keeps its shape. Queries MAY still return it; a DTO only a page or exporter receives stays in `App\Application\Dto`.
- **C. A handler that needs infrastructure other than a model.** Decide by the nature of the dependency:
  - pure framework glue (no persistence, no side effect) MAY stay in the core where the rules of the layer allow it, including the types a package layer allows;
  - a helper that persists moves to `App\Domain\CQRS`, as in A;
  - a side effect (mail, notification, cache or read-model refresh, an external call) goes behind an interface in `App\Application\Contract`; the handler depends on the contract and the infrastructure implements it ([Dependency direction](#dependency-direction)).
- A listener or other class that dispatches commands belongs to `App\Infrastructure`, not to the core.
- An exception to the layer rule is the last resort. It is recorded in the project part of this file (what it covers and why) and in the layer test, and a test failure on any other class is fixed by A, B or C.

<!-- from: package/lunar -->
### Lunar models in the domain core

Lunar is the shop's domain model, so this layer makes one explicit exception to the framework-free core ([`App\Domain\<Module>`](#appdomainmodule---the-framework-free-core)):

- Code in `App\Domain` MAY import `Lunar\*`: its models (`Lunar\Models\*`) and its value types (`Lunar\DataTypes\*`, `Lunar\Base\DataTransferObjects\*`) as parameter, return and property types. The core rule's ban on an Eloquent model does not cover them.
- Every other rule of the core and of the dependency direction stays. `Illuminate\*` is still forbidden in the core (database, facades, HTTP, the container, the session), and persistence (queries, saves, transactions, locks) stays in the CQRS handlers.
- The layer architecture test lists Illuminate namespaces and the application layers, not `Lunar\`, so it needs no change.

### Classes Lunar stores by name

Lunar saves the fully qualified class name of some classes in the database: the attribute field types (`lunar_attributes.type` and the JSON `attribute_data`), models in the morph map without an alias, and anything else registered by class name.

- Such a class MUST NOT be moved or renamed without a data migration that rewrites the stored names, deployed with the code. A refactor that fixes a layer violation ([Fixing a layer-dependency violation](#fixing-a-layer-dependency-violation)) leaves these classes where they are.
- Where the location of such a class breaks the layer rules, the class is a recorded exception: the project lists it in the project part of this file and in the layer test, with the column or registry that holds its name.
- A morph map alias is the way to stop depending on a class name for a new model; an existing stored name is changed only by the migration above.

## Modules

Module boundaries are namespaces inside the layers, not separate packages. Each module owns its data and exposes behaviour through its commands and queries; other modules ask, they do not reach in. The project lists its modules and what each owns in its architecture ADR (or its plan), not in `CONTEXT.md`. Each external system is one namespace, `Integration\<System>`.

- A module MUST NOT read another module's Eloquent model. Cross-module reads execute the owning module's query. Reaching into another module's model is `Major` in review even when it works.
- A module MUST NOT write another module's data. It dispatches that module's command.
- Code shared by several modules (`App\Domain\ValueObjects`, contracts) depends on no module.

## Persistence

- Migrations are forward-only. A deployed migration is never edited; a mistake is corrected by a new migration. A migration MUST be safe to run while the previous release still serves traffic: add first, remove in a later release.
- Every table scoped to a tenant, customer, sales channel or legal entity carries that scope explicitly. A query that can return another scope's data is a bug, not a filter to add later.
- A yes/no fact SHOULD be stored as a nullable timestamp of when it became true (`archived_at`, not `is_archived`). A plain boolean remains fine where the moment carries no meaning.
- A column with more than two values SHOULD be a native `ENUM` where the engine has one (MariaDB, MySQL), mapped to a PHP backed enum through a cast. PostgreSQL and the SQLite test database have no such type here: Laravel's `enum()` creates a `varchar` with a `CHECK` constraint there, which is accepted as the implementation, so "database-native" is not literally true on them. Values the client manages as data (types, tags, categories) are tables, not enums.
- Generated index and foreign key names MUST stay within 64 characters; give long ones an explicit short name. The suite checks this ([testing.md](testing.md#database-in-tests)).
- Money columns hold integer minor units (`BIGINT`); exact decimals are `DECIMAL` with the scale the source material uses. `FLOAT` and `DOUBLE` are never used for money or quantities.
- Translatable content is stored in JSON columns keyed by locale, one row per entity, never a second row per language.

## Money

The principles are in [domain.md](domain.md#money). On this stack:

- An amount is a project value object (`App\Domain\ValueObjects\Money`: integer minor units and a currency, arithmetic only within one currency), mapped to a `BIGINT` column by a cast.
- Exact decimals (rates, coefficients, percentages) are `BcMath\Number` or decimal strings computed with bcmath.
- **One rounding mode for the whole application, half away from zero by default** (`RoundingMode::HalfAwayFromZero`). A project that needs another mode (for example half-even for a tax rule) records it in its money ADR; one mode per application either way. It is applied at one place per calculation chain, inside the framework-free core, and is never chosen at a call site. PHP `round()` is never used on money.
- **A float never carries money, not even for display.** `float()` is never called on money input, and the formatter does not hand an amount to ICU as a float (`NumberFormatter::formatCurrency()` takes one): it builds the string from the integer or decimal-string parts.
- Numbers are formatted on the server by one formatter per kind (money, quantity, date, percentage); the frontend renders the string it receives.

<!-- from: package/lunar -->
- Lunar stores prices as integer minor units per currency. The project keeps `lunar.pricing.stored_inclusive_of_tax` `false`: prices are net, and the tax driver MUST refuse inclusive prices.
- Lunar's `CalculateLines` cart pipeline step converts a price through float before it rounds. The project replaces it in the cart pipeline with a step of its own that works in integer minor units with the project's rounding mode. Tests pin the replacement against Lunar's results on non-ambiguous amounts, the ones where float and integer rounding agree ([testing.md](testing.md#pinning-lunar-behaviour)).
- Lunar money math on the project's side never goes through float: project code does not read a Lunar price through `Price::unitDecimal()` or `decimal()` and every amount it writes to a Lunar column is an integer produced by the project's rounding.
- Lunar money columns are `UNSIGNED` on MariaDB and MySQL. A project MUST NOT write a negative amount to one. Signed adjustments are kept in project tables and Lunar receives the positive total. SQLite does not enforce `UNSIGNED`, so the write path MUST reject a negative result itself and test it.
- A project that sets its own exchange rates MUST switch off Lunar's price copy between currencies (`sync_prices` off, and no copy of the default prices into a new currency). Derived prices are project rows with a record of the rate used.
- Customer-group and quantity-tier prices are chosen by one project rule, installed as a pricing pipeline step. Lunar's own choice can pick a default-list tier over a cheaper group price; the rule and the group rows for every tier prevent it.

## Admin panels

<!-- from: package/filament -->
An admin panel built on Filament is a separate user interface: the frontend rules of the Inertia pages do not apply to it.

- Its panel provider is `App\Domain\Providers\Filament\AdminPanelProvider`, listed in `bootstrap/providers.php`. Like every provider it holds wiring only, never a business rule.
- Filament resources MAY be used for plain CRUD: maintaining data that carries no business rule beyond validation.
- A custom action that performs a business operation dispatches the corresponding command ([cqrs.md](cqrs.md)) instead of reimplementing it.

## Versions and upgrades

<!-- from: package/lunar -->
- The project is on Lunar 1.x (Lunar 2 is still in alpha) and pins the minor line of `lunarphp/lunar` in `composer.json`. A Lunar addon (for example table-rate shipping) stays on the same minor line. The project MUST NOT require Lunar's sub-packages, Filament or Livewire directly; they follow Lunar.
- A move to another Lunar major is a decision with its own ADR, never a dependency bump: it changes the admin stack (the Filament major) and can change model classes and extension APIs.
- The project MUST NOT edit `vendor/`. Lunar's configuration is published to `config/lunar/*.php` and is project-owned; a change to it is a reviewed code change.
- A faulty vendor migration MAY be replaced by an application file with the same name (Laravel keys migrations by name). The copy MUST have a test that fails when the vendor file changes ([testing.md](testing.md#named-exceptions)), and it MUST be deleted when upstream fixes the migration.
- An upgrade follows this checklist, in one reviewed change:
  1. Move the pins: Lunar and its addons together.
  2. Diff the published `config/lunar/*.php` against the new vendor defaults and merge the differences.
  3. Run the tests that pin Lunar behaviour ([testing.md](testing.md#pinning-lunar-behaviour)); a failing pin is a decision, not a test to delete.
  4. Compare each vendor migration copy and each pipeline step the project replaced with its vendor original.

## Extension points

<!-- from: package/lunar -->
Behaviour changes use the first option that fits, in this order:

1. Configuration in `config/lunar/*.php`.
2. A documented extension point: pipeline steps (added or replaced in the pipeline configuration), modifiers, actions, validators, `Payments::extend()`, `Taxes::extend()`, `Discounts::addType()`, `FieldTypeManifest::add()`, `ModelManifest::replace()`, `LunarPanel::extensions()`.
3. A project class that extends a Lunar model, registered with `ModelManifest::replace()`.

If none fits, the gap is recorded in the issue before a workaround is written.

- Pipeline steps and modifiers run inside Lunar's calculation. They MUST NOT write state. A step that must persist something calls a command.
- An overridden action MAY keep Lunar's contract while adding events (for example saving the model instead of a bulk query update) when a test pins the contract.
- A project extension of a Lunar entity is a 1:1 table with a subclass in `App\Infrastructure\Models`, registered in `App\Domain\Providers`. Relations are added with `resolveRelationUsing()`. Queries go through the project class, because a query started on the Lunar class skips the subclass's eager loading.
- Lunar's entities are not copied into parallel tables. A module adds its own table only for data Lunar does not model.

## Process-wide state

<!-- from: package/lunar -->
Lunar keeps some registries as container singletons for the life of a process; the shipping manifest of resolved options is one. Queue workers and long-running commands process many carts or orders in one process.

- Code that fills such a registry MUST clear it at the start of the run and MUST NOT read the previous run's content.
- A cache of calculation results MUST NOT be process-wide and MUST NOT be shared between carts or orders. Its key includes the state it was computed from (for example a cart fingerprint), and it lives for one operation.
- A project singleton MUST NOT hold data of one cart or order (the handler lifetime rule in [cqrs.md](cqrs.md#handler-lifetime) applies to every singleton).

## Calculation cost

<!-- from: package/lunar -->
- Lunar requests the same derived value more than once per operation (the cart calculation and the tax breakdown both need the shipping option). A project modifier that runs queries MUST evaluate once per cart state per operation and reuse the result.
- Rates, prices, zones, exclusions and surcharges MUST be loaded in batch per evaluation. The number of queries MUST NOT depend on the number of candidate options or rows; a query inside a loop over them is a `Major` review finding.
- The rule that decides which options a cart may use lives in one query. Modifiers call it and MUST NOT repeat its logic.

## Tax

<!-- from: package/lunar -->
- When a rate depends on more than Lunar's zones (delivery country, customer or VAT status, distance selling), the project provides a tax driver with `Taxes::extend()`. Lunar's system driver MUST NOT choose a rate in such a project.
- The shipping tax uses the same delivery country and customer context as the cart lines. The context is set once for the whole cart calculation, by a step at the start of the cart pipeline.

## Lunar admin panel

<!-- from: package/lunar -->
Lunar's admin is a Filament panel, so a Lunar project carries the `package/filament` layer ([Admin panels](#admin-panels)). It is a separate user interface to which the Inertia frontend rules do not apply.

- The panel is built by a dedicated provider, `App\Domain\Providers\Filament\LunarPanelProvider`, listed in `bootstrap/providers.php`. It takes the place of the `AdminPanelProvider` of the Filament layer. It configures the panel (`LunarPanel::panel(...)`, path, brand, middleware, pages, resources, extensions) and registers it. Like every provider it holds wiring only, never a business rule.
- Plain CRUD stays in Lunar's resources. Customisation is panel configuration, resource and page extensions, and project pages and actions in `App\Infrastructure\Admin`, registered by the provider.
- A custom admin action that changes state dispatches a command. It MUST NOT write the Lunar model directly.
- A Lunar action that writes state outside the project's command rules (status changes, direct refunds) is replaced or removed. The admin is not a bypass of the storefront's commands.
- The panel starts with Lunar's default theme; a brand logo and render hooks are allowed on it. A project MAY move to its own Filament theme. The theme rule of the Filament layer ([Tailwind CSS](frontend.md#tailwind-css)) then applies.
- Admin access is the `staff` guard plus the project's policies and panel middleware.
- Lunar ships no translations for some locales. The project provides them in `lang/vendor/`. A missing key falls back to the fallback locale; a page never mixes locales by environment.

## Storefront and search

<!-- from: package/lunar -->
- A Lunar model is an Eloquent model: the Inertia rule that no model is a page prop applies to it, and a query returns a DTO built from it ([frontend.md](frontend.md)). Route-model binding to a Lunar model in a controller signature is allowed.
- Search runs through Laravel Scout, which Lunar already requires. When search needs partitions or columns Lunar's indexers do not provide, the project adds a read model that is Scout-searchable. Lunar's indexers stay as they are where they cover the need.

> Local additions go below this managed block. Content outside the block is preserved when the guidelines are synced.
<!-- esoul-guidelines:end architecture -->
