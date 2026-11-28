<!-- esoul-guidelines:begin testing -->
# Project guidelines - Testing

This file says which tests the project keeps and how they are judged. The runners, file names and commands of the stack are in the stack's tools guideline (`tools.md`). Good tests are the priority; CI time is the constraint, so the suite stays small, fast and useful.

<!-- from: framework/laravel -->
On this stack tests are PHPUnit classes in `tests/` under the `Tests` namespace.

<!-- from: package/lunar -->
Lunar's own behaviour is Lunar's test suite. The project tests what it configures, extends and calls.

## What belongs in the suite

The suite keeps only **useful tests**: tests that fail when a user, a caller, an administrator or an integrated system would notice a regression. A test earns its place when it pins down one of these:

- **observable behaviour**: return values, persisted state, dispatched events and notifications, HTTP status and payload, redirects, rendered page props, export rows;
- **contracts and seams**: what a command, query, route, request, policy, component or DTO promises to its consumers;
- **boundaries and invariants**: limits, empty and null input, uniqueness, immutability, state transitions, rounding, amount units, idempotency;
- **failure paths**: validation (which field, never the wording), authorization, exceptions a caller handles (their type and metadata; never the text of a user-facing message).

The following do **not** belong in the committed suite. Remove them when you find them, and turn them into a behavioural test when the behaviour behind them matters:

- **performance tests**: timings, benchmarks, query-count budgets, eager-loading assertions;
- **architecture tests**: reflection or directory scans, "every class is registered", return-type checks, `class_exists`, unless named below;
- **data-definition tests**: migrations, schema columns and defaults, indexes, model fillables, casts or relations in isolation, factories, seeders, enum case lists, configuration values;
- **tests that restate the implementation**: mock echoes and forwarding, getters returning constructor arguments, generated SQL text, incidental defaults, a bare "does not throw", or an existence or type assertion as the only assertion;
- **duplicates** of another test covering the same path with the same assertions; keep the one at the more appropriate level.

## Named exceptions

Architecture and structure checks are a stack or project choice, not a default. A check that guards a boundary review alone does not hold MAY stay in the suite when the stack guidelines or an accepted ADR name it. Anything else of these kinds still does not belong.

<!-- from: framework/laravel -->
- Three tests are named exceptions on this stack: the layer-dependency test and its detection test ([Architecture tests](#architecture-tests)) and the schema identifier length test ([Database in tests](#database-in-tests)). Any further one needs its own ADR.

<!-- from: package/lunar -->
- The Lunar layer names one more: the test that a copied vendor migration still matches the vendor file ([architecture.md](architecture.md#versions-and-upgrades)). A project that copies a vendor migration records the copy in its Lunar ADR.

## How much to test

How many tests a behaviour gets depends on the damage a regression would do, not on the code existing. Decide in this order:

1. **Unit tests first.** When unit tests cover the whole behaviour, a separate feature test SHOULD NOT be written for it.
2. **Fold, do not add.** A feature test is for what only the real framework or database shows: wiring of route, request, bus and handler, authorization, persistence, events across parts. Before adding one, extend the scenario of the feature test that already builds most of the same set-up, usually the endpoint test of the route that triggers the behaviour. A handler feature test plus an endpoint test of the same flow is a duplicate.
3. **Size what remains by tier.** Each route, console command or page (a **surface**) is in one tier:

| Tier | Flows | Feature tests of the surface |
|---|---|---|
| 1, critical | orders, payments, prices and tax, invoices and integrations of record, authentication and authorization, irreversible data changes | full scenarios: every success and failure path whose outcome a user, a caller or the record would notice |
| 2, everything else | every other surface | one success contract test (status or return value, payload, persisted state), its [authorization tests](#authorization-tests) and one validation-keys test (one request that breaks the rules, asserting the error keys) |

- The project lists its tier-1 flows in an ADR (or the decision register of its plan) and references that list from its project guidelines, below the managed block. A flow nobody listed is tier 2; the pull request that adds a flow in one of the categories above adds it to the list.
- **Rule variants are unit tests**, in both tiers: branches, boundaries, calculations, state transitions, validation rules. A rule that only a full flow can reach is a design problem: extract it into a unit that takes its inputs, and test that.
- **A layer above a tested layer does not repeat its scenarios.** A surface that wraps a tested command or query proves only its own contract: routing, input mapping, status, response shape, authorization.
- **The floor is per surface.** The success, authorization and validation-keys tests of tier 2 are the surface's feature tests; a command or query reached only through one surface is covered by that surface's tests, not by its own feature test. One reached through several surfaces, or through none (a job, a bus-only call), is a surface of its own.

<!-- from: package/lunar -->
- Order placement, payment, price selection, tax, shipping price, order totals and refunds are the default tier-1 Lunar flows. The project's Lunar ADR confirms or changes this list, and the tier-1 list the project keeps for the general rule refers to it.
- A test of a tier-1 Lunar flow is a full scenario of its surface, under the same cost rules as every feature test.

## More tests are not more safety

A test earns its place by its tier ([How much to test](#how-much-to-test)) or as the regression test of a bug fix. A review finding, an escaped mutant or a follow-up issue is a reason to look at the behaviour, not by itself a reason to add a test. Every test costs every push of every project, so a change that makes a test redundant deletes it in the same pull request, duplicates included.

## Temporary tests

Migration, backfill, schema, seeder, data-import, performance and exploratory tests are welcome **while developing**, when they are the fastest way to validate something. They are scaffolding:

- they MUST be deleted before the pull request is opened, at the latest before merge, so that the checks that follow measure what will be merged;
- the pull request mentions what they verified when the result matters to reviewers;
- a check that must hold permanently is expressed as a behavioural test of the feature that depends on it.

<!-- from: package/lunar -->
- A query-count or timing measurement of a Lunar calculation is a temporary test: it is used during development and deleted before the pull request is opened. There is no permanent query-count or timing test.

## Error and validation messages

Tests MUST NOT assert the text of a user-facing error message: a message shown to users, translated or subject to rewording (validation messages, flashed errors, error bodies and the exception messages that reach them), however the text is reached (an exception-message expectation, `getMessage()` compared with a literal, a validation or flash assertion with a value, a JSON `message` or error value, an assertion on rendered error text). Assert the machine-readable part:

| Failure | Assert |
|---|---|
| exception thrown by domain or application code | the exception class or error `type` and the metadata it exposes: field keys, error codes, reason, related ids |
| exception that carries field errors | the keys, and that each key has at least one message, not what it says (a key with an empty message list is no error to the user) |
| JSON request failing validation | the status (`422`) and the keys of the errors |
| web form failing validation | the redirect status and `Location`, and the keys of the flashed errors |
| error flashed from a caught exception | the redirect and that an error was flashed, without its value |

When the class alone does not tell two failure causes apart, the error is missing metadata. Add it (a field key, a reason enum, a dedicated class, a code) and assert that instead of telling the causes apart by wording.

Asserting the text of an *internal* error message is fine: an exception or a developer-facing validation or configuration error that no user reads, translates or rewords. Such a test pins the developer-facing contract of the failure.

Why: user-facing text is translated and edited, and some errors are shown to users. A test pinned to the wording fails although no behaviour changed, and stays green when the wrong rule fires with the same text. Callers depend on the type, the keys and the codes; those are the contract.

The rule covers user-facing error text only. Text users read in normal operation (labels, notification and e-mail copy, page props) is product content and stays testable where it is the behaviour under test. Text a test supplies itself and expects to be forwarded unchanged is test data, not wording: `assertSame($message, $failure->getMessage())` for an exception built with `$message` is fine, a retyped sentence is not. Existing tests that pin user-facing wording are not a precedent; replace the assertion whenever you change the test or the code it covers.

<!-- from: framework/laravel -->
- Domain exceptions are asserted by type and properties first (the exception class and the facts it carries).
- Form request or controller validation: `assertSessionHasErrors(['field'])` (web) or `assertJsonValidationErrors(['field'])` (JSON) with the field keys only, together with the status or redirect (`assertStatus(422)`, `assertRedirect()`).
- An exception that carries field errors: `array_keys($exception->errors())` and `assertNotEmpty($exception->errors()['field'])`. Laravel reports a field with an empty message list as no error at all, so the second assertion is what keeps the user from being sent back without an explanation.
- An error flashed from a caught exception: `assertRedirect()` and `assertSessionHas('error')` without a value.
- These are banned when they carry **user-facing** text (validation error text, flashed messages, translated strings, error bodies): `assertSessionHasErrors(['field' => '...'])`, `assertJsonValidationErrors(['field' => '...'])`, `assertJsonPath('errors.field.0', '...')`, `assertSessionHas('error', '...')`, `assertSee()` on an error text, and `getMessage()` or `expectExceptionMessage()` on an exception whose message reaches a user.
- `expectExceptionMessage()`, `expectExceptionMessageMatches()` and `getMessage()` are allowed for *internal*, developer-facing exceptions (a guard, a configuration or programming error that no user reads); see the general rule above.

## Every behaviour change carries a test

A pull request that changes behaviour MUST contain a test that fails without the change, or one line in its description: `No test: <reason>` (markup or copy only, configuration, a data task verified by its own checks, behaviour no seam can reach). A reviewer reports a behaviour change with neither. Documentation-only and dependency-only pull requests need no line.

## Regression tests

- A bug fix MUST come with a regression test that **fails without the fix**. Run it against the unfixed code once and say so in the pull request.
- The test is named after the behaviour, not the ticket: a reader who never saw the issue has to understand what is protected.
- The regression test sits at the layer where the bug was observable to its caller, not only in the unit that was patched.
- A regression test is never deleted as part of unrelated cleanup.

## Writing tests

- A **unit test** checks one behaviour. A **feature test** is a scenario: see [Keeping the integration run short](#keeping-the-integration-run-short). Every test is self-contained and does not depend on test order.
- Test names describe the behaviour, consistently with similar tests nearby.
- Prefer table-driven cases (data providers, `it.each`) for the variants of one behaviour in **unit tests**, with a descriptive name per case. In a feature test a provider case per pair of inputs is a matrix, which is one test ([Keeping the integration run short](#keeping-the-integration-run-short)).
- Tests are deterministic: fix the clock, fake the network and external systems, restore mocks. A test that reaches the network is a bug.
- Build data through factories or builders that produce a valid minimal record. What a test cares about is set explicitly in that test.
- Test data is invented. Real client names, addresses, amounts or credentials MUST NOT appear in tests or fixtures ([security-and-data.md](security-and-data.md)).
- A test of date or time logic fixes the clock and includes an instant at which the date in the business time zone differs from the date in UTC: just after local midnight for a zone east of UTC, just before it for a zone west of UTC. A project whose business time zone is UTC has no such instant. The date rules are in [domain.md](domain.md#time-and-dates).
- A test of rendered HTML (templates, e-mail) rejects raw executable tags.
- A test that renders untrusted or user input also asserts its encoded markup (`&lt;script&gt;`).

<!-- from: framework/laravel -->
- Test method names are `snake_case` and describe the behaviour (`test_open_orders_can_be_cancelled`); Pint enforces it (`php_unit_method_casing`).
- Use PHPUnit attributes (`#[DataProvider]`, `#[Group]`), not doc-comment annotations.
- Use `assertSame()` for strict values and object identity; compare object state with an appropriate equality assertion or explicit typed values.
- Data providers are `public static` camelCase methods in the same test class, return a generator with descriptive keys, and document the shape of what they yield:

```php
/** @return iterable<string, array{0: string, 1: int}> */
public static function provideScenarios(): iterable
{
    yield 'empty input' => ['', 0];
}
```

- Instance data a provider needs is passed as a closure; the closure's shape is documented on both the provider and the consuming test.

## Authorization tests

The rules are in [security-and-data.md](security-and-data.md#authorization). In tests:

- every protected surface has a test for the role that is **allowed** and for at least one role that is **refused**; a test that only proves access is granted is not sufficient;
- the refused case asserts an explicit status (`403`, `404` or a redirect), never "not 200";
- a value a role must not see is asserted **absent from the payload** (page props, JSON, export rows), not hidden in rendered markup;
- ownership and scope (tenant, customer, sales channel, legal entity) are tested with two users, each seeing only their own records;
- default deny is enforced by one test that fails on any route without an authorization decision, so a new route cannot ship unguarded.

## Test doubles

Doubles (mocks, stubs, fakes) isolate the code under test from external systems, and from the database only when the behaviour under test does not need it.

- Behaviour-specific fakes stay in the individual test. Suite-wide safety guards MAY live in the shared base test class.
- **Network guard.** The suite is guarded so that a connection to anything but localhost fails the test, and integration keys are empty in the test environment. A test that needs an external system fakes it; a request nobody faked is a bug, never a silent real call. The stack's testing guideline names the mechanism. The one exception is the `sandbox` group ([Test groups](#test-groups)), which is meant to reach a provider's test API: the guard is lifted for it alone, and its credentials come from the environment of the job that runs the group (a test without them skips itself).
- Do not install blanket exception or sleep fakes only to make the suite faster.
- Do not mock the collaborator whose result is the behaviour under test (a calculator in a test of its caller). A small real collaborator beats a mock that echoes the implementation.

<!-- from: framework/laravel -->
- Fake the Laravel facades the test touches (`Http::fake()`, `Queue::fake()`, `Mail::fake()`, `Event::fake()`) in the test itself; the base class keeps `Http::preventStrayRequests()` and, when the project has derived data, the fake of it below.
- **When the project has derived data, it is faked in the base class.** `Tests\TestCase::setUp()` fakes the recomputation of read models, search indexing, projections, feeds and sitemaps, so a feature test pays only for the source data it asserts on. A test of that derivation uses the opt-in trait `Tests\Concerns\WithDerivedData`, which restores the real recomputation for that test class; no other test calls it to build a precondition. The template ships neither the fake nor the trait: the first derived data of a project adds them. A project without derived data has nothing to fake.
- A test that writes files uses `Storage::fake()`, never a real disk.

<!-- from: package/lunar -->
- A test that replaces a Lunar driver (tax, payment, shipping) MUST say why in its docblock.
- Each project driver is exercised by the real driver in at least one test.

## Database in tests

- A test never uses the development or a production database, and never depends on the order of other tests or on data another test left behind.
- A test that needs the database starts from a known state the stack sets up for it; the stack guidelines say how.

<!-- from: framework/laravel -->
- Tests run on SQLite `:memory:` by default (`phpunit.xml` forces it, so values from the shell or the container's `.env` never leak into a run). `composer test` and Jenkins stay on it, and no database service is needed. MariaDB, MySQL or PostgreSQL for the whole suite is a project opt-in that the project documents in its own guidelines; when the suite runs on the production engine, the identifier length test below is unnecessary.
- Feature tests use `LazilyRefreshDatabase`, set once in the shared `Tests\TestCase`. It migrates once per worker process (for `:memory:` the migrated connection is cached) and wraps each test in a transaction that is rolled back, but only for a test that touches the database, so tests that never do pay nothing. `RefreshDatabase` does the same eagerly for every test. `DatabaseTransactions` is not used: it needs a database that is already migrated and cannot work with `:memory:`. Unit tests never touch the database.
- The reset is cheap. What makes a feature test cost is booting the application and building its data: keep the data minimal.
- Every Eloquent model has a factory that produces a valid minimal record, with states for meaningful variants. Tests build their data through factories, never raw inserts, and set what they care about explicitly. Factories, seeders, migrations and schema are not tested in isolation; their behaviour is proven through the commands, queries and endpoints that use them. Test data is invented.
- **Schema identifier length.** The production engine limits the length of table, column, index and foreign key names (the engine layer states the limit); SQLite accepts any length, so a too-long generated name passes the suite and fails or is cut in the production migration. One named test, `tests/Feature/Architecture/DatabaseIdentifierLengthTest.php`, runs the migrations and asserts that every identifier they create stays within the limit of the configured engine. It runs the migrations itself, so it extends Laravel's own `TestCase`, not `Tests\TestCase`, whose transaction would block it. A migration gives a long index or foreign key an explicit short name.
- **Production engine.** A project that keeps SQLite tests while production runs another engine MAY mark engine-dependent tests with `#[Group('db-engine')]` ([Test groups](#test-groups)). The project provides the switch that points that run at a throwaway database whose name ends in `_testing`, never the development database.

<!-- from: package/mariadb -->
- **MariaDB and MySQL identifier limit: 64 characters.** The identifier-length test checks every generated name against it.

<!-- from: package/lunar -->
- A test that needs Lunar reference data (default currency, channel, language, customer group, tax class, tax zone) creates it through Lunar's factories, or reads the rows the production seeder created when the test's base class seeds them once per process. A test MUST NOT create a second default record.
- Shared fixtures that build a catalog are used only by a behaviour that needs the whole catalog ([How much to test](#how-much-to-test)).
- Behaviour that depends on the production engine (unsigned columns, locking, collation, Lunar's SQL) is a `db-engine` test ([Test groups](#test-groups)).

## Keeping the integration run short

The cost of an integration (feature) test is starting the application and building the data the test needs, not resetting the database. Every eSoul project shares one Jenkins, so a slow run blocks every other project too.

- **Pick the cheapest layer that can see the behaviour.** Calculations, mapping, value validation and branching are unit tests, with table-driven cases for the variants. An integration test is for what needs the real database, framework or application: the contract of a route or command, authorization, persistence, events and wiring across parts.
- **A feature test is a scenario**: one realistic flow per test, asserting all its observable outcomes (status, persisted state, dispatched events, props). Unit tests stay one behaviour per test.
- **Variants of one rule are unit tests**, never more feature tests. Each failure class and the success of a route or command are proven once at the integration layer. A data provider multiplies the fixture, because every data set pays the set-up again.
- **A scenario creates only the data it asserts on.** Factories build minimal valid records; do not build a catalogue to test one product.
- **Preconditions are built, not played through.** Factories, states and builders create the state a scenario starts from (a placed order, a paid invoice, a published product); only the behaviour under test uses its real entry point. A precondition played through real flows makes every test pay for those flows and fail when they break.
- **A matrix is one test.** A check over a cross product (role × page, route × middleware, class × layer) loops over it, collects the violations and asserts that the list is empty, so one failure lists every violation. Never a data-provider case per pair: each case pays the set-up again.
- **Derived data stays off unless it is under test, when the project has derived data.** A project that has derived data (read models, search indexes, projections, feeds, sitemaps) has its shared base test class fake the recomputation of it; a test of that derivation opts back in (the stack's testing guideline says how). Every other test asserts the source data. A project without derived data has nothing to fake and adds no such class.
- **Concurrency is proven by design and review** (the locking rule of the stack's CQRS guideline), not by tests: on the test engine a multi-process test cannot show row locks, and it costs a process per worker. A multi-process test is written only for a named damaging invariant (a double-spent stock, a double-charged payment); it names the invariant in its docblock, carries `db-engine` and runs only in the scheduled job on the production engine ([Test groups](#test-groups)).
- **Add to the suite of the area.** A new integration test goes into the existing suite of its area. A new suite is only for an area no suite covers, and the pull request says why. Name a suite after the area, never after an issue.
- **A feature test that takes over 1 second** in a full local run is called out in review: make it cheaper or say in its docblock why it cannot be.

Suites that break these rules are merged or moved to a cheaper layer in their own issue, not in passing.

<!-- from: package/lunar -->
- A placed order, a paid order or a cart in a given state is a precondition: it is built by a factory state or a helper that writes the rows. Only the test whose behaviour is order placement or a cart calculation runs `PlaceOrderCommand` or the cart pipeline, and only a payment test runs a payment flow.
- A helper that calls Lunar's actions to build a precondition is allowed once per test class and MUST say so in its docblock.

## Suite time budget

- The CI test stage has a wall-time budget of **10 minutes**. A project MAY set its own and states it in its project guidelines, below the managed block.
- The test stage SHOULD publish JUnit results with per-test times, so the slowest tests and the growth per pull request are read from the build, not guessed.
- A test stage over budget is an issue that trims the suite (a cheaper layer, a matrix as one test, preconditions from factories, duplicates deleted), and it comes before new feature tests.
- A pull request that adds more than **30 seconds** of summed test time (the sum of the per-test times of a full run, before and after) says why in its description.

## Test groups

The default run (local and CI) excludes `sandbox` and `quarantine`; they run on a schedule or on demand (the stack's testing rules say how). A test joins a group only for the reason named here.

| Group | For | Runs |
|---|---|---|
| `sandbox` | contract tests against a provider's test API or a recorded cassette refresh; they skip themselves when credentials are missing | on demand and on schedule |
| `quarantine` | a flaky test whose cause is not fixed yet; a comment on its group attribute holds the URL of its issue | on schedule only, until fixed or deleted within that issue |

`db-engine` is a project opt-in. A project that runs on a different database engine in production than in its tests MAY mark tests whose outcome depends on the production engine (collations and case-insensitive uniqueness, `NULL` ordering, JSON functions, raw SQL, locking, strict mode) with it. They stay in the default run on the test engine and run again on the production engine in a scheduled job, which the project MUST set up, otherwise the group proves nothing. A `db-engine` test that shows nothing on the test engine (a multi-process concurrency test) skips itself there, so only the scheduled job runs it. A defect found on the production engine gets a regression test in `db-engine`, never a workaround that only satisfies the test engine.

<!-- from: framework/laravel -->
- A test joins a group with the PHPUnit attribute (`#[Group('sandbox')]`). `phpunit.xml` excludes `sandbox` and `quarantine` from the default run.
- Run a group on demand:

```bash
php artisan test --group=sandbox      # provider test APIs; skips without credentials
php artisan test --group=quarantine   # flaky tests waiting for their fix
php artisan test --group=db-engine    # only in a project that opted in
```

## Flaky tests

A test that fails and passes on the same code is a defect. Do not rerun the build until it is green and do not add sleeps or wider timeouts. Find the cause (clock, order dependence, shared state, parallel workers, network) and fix it; when that is not quick, quarantine the test with an issue as above.

## Running tests

- The whole suite runs with the stack's one command (named in the stack's tools guideline, `tools.md`), once, on the code that will be pushed. While editing, run narrowly: one file or one filter, then the test directories of the touched area.
- Run tests with the debugger and coverage driver off unless you need them; they slow a run down several times.
- Coverage shows which lines the tests execute, not whether they check anything.

<!-- from: framework/laravel -->
- `composer test` runs `php artisan test --parallel`, which gives each worker its own database token and is much faster than a serial run.
- `phpunit.xml` sets `memory_limit` for every test process, workers included, through `<ini>`. PHPUnit applies `<ini>` after `php -d`, so `-d memory_limit` has no effect on a test run; change the value in `phpunit.xml`.
- Opcache is on for CLI test runs: `opcache.enable_cli=1` in the `php.ini` of every environment that runs tests (development image, CI testing image). It is a system setting that `phpunit.xml`'s `<ini>` cannot set, and it cuts the boot of every test by about 15%. **Target.** The shared CI testing image still ships with it off.
- Narrow runs: `php artisan test --parallel tests/Feature/<Area>` for an area, `php artisan test --filter=<TestName>` for one behaviour. Prefix a run with `XDEBUG_MODE=off` unless you need the debugger or coverage.
- `composer coverage` needs a coverage driver (PCOV or Xdebug) and writes its HTML report to `test-coverage/`, which is not committed.
- The commands CI runs are listed in [tools.md](tools.md#read-only-verification).

## Testing workflow

1. Write or adjust the behavioural tests for the change, test-first where practical, and run them narrowly.
2. Delete temporary tests and anything else that does not belong in the suite. Do this first, so the next steps measure the tests that will be merged.
3. Run the whole suite and the stack's static analysis (the stack's tools guideline, `tools.md`) once, on the code that will be pushed; formatters run before every commit instead ([workflow.md](workflow.md#commits)). Skip what the change cannot affect: a documentation-only change runs no tests.
4. Optional: where the stack provides mutation testing, run it when the change has logic with boundaries or branching, and use the results as the stack's mutation-testing section says. It is a diagnostic, not a gate, and nothing from it goes into the pull request.
5. List the checks you ran, with their results, in the pull request, or the `No test:` line; add the reason when the pull request adds more than 30 seconds of test time ([Suite time budget](#suite-time-budget)).
6. Push review fixes together, after they pass locally: every push is a full CI build. A red build is reproduced locally before its fix is pushed.

Do steps 2 to 5 before opening a pull request and before reporting a slice as finished.

## Test layout

<!-- from: framework/laravel -->
The suite has two directories: `tests/Unit/` and `tests/Feature/`. Feature tests of HTTP endpoints go in `tests/Feature/Http/`, those of command and query handlers in `tests/Feature/CQRS/`. Any finer structure (per module, per area, support helpers) is the project's choice.

## Unit tests

<!-- from: framework/laravel -->
- A unit test lives in `tests/Unit/` and SHOULD extend `PHPUnit\Framework\TestCase` and SHOULD NOT touch the database or the network; it then runs in milliseconds and needs no application.
- Code that needs the container, the configuration or the database is covered by a feature test.
- A unit test checks one behaviour; variants of a rule are data-provider cases here, not more feature tests ([Keeping the integration run short](#keeping-the-integration-run-short)).

## Feature tests

<!-- from: framework/laravel -->
- A feature test lives in `tests/Feature/`, extends `Tests\TestCase` and goes through the real entry point: an HTTP route, a bus (`CommandBusInterface`, `QueryBusInterface`) or an Artisan command.
- `Tests\TestCase` is the shared base: it boots Laravel, refuses HTTP requests nobody faked (`Http::preventStrayRequests()`) and uses `LazilyRefreshDatabase` ([Database in tests](#database-in-tests)). A feature test does not add a database trait of its own.
- A feature test is a scenario: one realistic flow, all its observable outcomes asserted, only the data it asserts on created.
- Every feature test pays for booting the application, so service providers stay lean: a provider that only registers container bindings is deferred (`DeferrableProvider` with `provides()`), and work that not every request needs (reading large files, building lists) runs on first use, not in `register()` or `boot()`.

## CQRS testing

<!-- from: framework/laravel -->
- Handler tests live in `tests/Feature/CQRS`. A handler reached only through one endpoint is covered by that endpoint's tests, not by its own; a handler reached through several surfaces or none (a job, a console command, a bus-only call) gets feature tests by [How much to test](#how-much-to-test), asserting the expected behaviour, the changes in the database and the returned data.
- A handler is called in one of two ways:
  - instantiated directly, for a handler without complex dependencies;
  - through the buses taken from the container, so that dependencies resolve the way production code resolves them. This is the preferred form:

```php
$commandBus = $this->app->get(CommandBusInterface::class);
$queryBus = $this->app->get(QueryBusInterface::class);
```

- A fake handler can replace a real one for the test by registering it on the bus: `$commandBus->registerHandler(SomeCommand::class, FakeSomeCommandHandler::class);`
- How handlers are written is in [cqrs.md](cqrs.md#testing).

## Controller testing

<!-- from: framework/laravel -->
- Controller tests live in `tests/Feature/Http`. A complex controller MAY be split into several test classes by functionality.
- How many scenarios an endpoint gets follows [How much to test](#how-much-to-test), and they include the flow of the command or query it alone dispatches. The authentication and authorization applied to it are covered by its [authorization tests](#authorization-tests).
- Every test asserts the status explicitly (`assertOk()`, `assertStatus()`, `assertForbidden()`), before anything else: with debug off an error page is an Inertia response too.
- Authenticate with `actingAs()` when the route requires it.
- GET endpoints: the status and, for Inertia responses, the component and the props that carry behaviour (`assertInertia()`).
- POST, PUT, PATCH and DELETE endpoints: the status and the changes in the database or in the response data.
- Validation: the field keys (`assertSessionHasErrors(['field'])`, `assertJsonValidationErrors(['field'])`), never the message. One request that breaks the rules covers the keys of an endpoint; the variants of a custom rule are unit tests of the rule.
- JSON responses: the structure (`assertJsonStructure()`), so the payload is guaranteed to hold the expected data.

## Architecture tests

<!-- from: framework/laravel -->
The stack requires one pair of architecture tests, and they are the named exceptions to the rule against architecture tests in [Named exceptions](#named-exceptions):

- `tests/Unit/Architecture/LayerDependencyTest.php` reads every PHP file under `app/` (with the tokenizer, not reflection) and asserts that the list of violations is empty:

| Rule | Checked in | Forbidden |
|---|---|---|
| the domain core is framework-free | `App\Domain`, except `App\Domain\CQRS` and `App\Domain\Providers` | `Illuminate\Database`, `Illuminate\Support\Facades`, `Illuminate\Http`, `App\Application` except `App\Application\Dto`, `App\Infrastructure`, the container and the session (including the `app()`, `resolve()`, `request()` and `session()` helpers) |
| handlers read only models from infrastructure | `App\Domain\CQRS` | anything in `App\Infrastructure` outside `App\Infrastructure\Models` |
| the application layer is independent of infrastructure | `App\Application` | anything in `App\Infrastructure` |

`App\Domain\Providers` is exempt from every rule: it is the one place in `App\Domain` that may use the container, facades and `App\Infrastructure` ([architecture.md](architecture.md#appdomainproviders---service-providers)).

- `tests/Unit/Architecture/LayerRuleDetectionTest.php` feeds each rule in-memory samples (one per kind of breach and one compliant sample) and asserts the exact violations. It is what makes a green `LayerDependencyTest` mean something. A new rule or kind of breach gets a sample there.
- A failure lists every violation as `path:line uses <dependency> - <rule>`. Fix the code, not the rule. A rule changes only together with [architecture.md](architecture.md#dependency-direction) and, where it is a decision, its ADR.
- A project MAY add rules for its own module boundaries to the same test; each such rule is recorded in the project's architecture ADR.

## Mutation testing

<!-- from: package/mutation-testing -->
Mutation testing checks that tests are useful, not only that they pass. Infection makes small deliberate changes ("mutants") to application code (flips a condition, shifts a boundary, removes a call or an array item) and reruns the tests that cover the changed line. A mutant a test fails on is *killed*; one every covering test survives *escaped*, which means no test pins that behaviour down. Configuration lives in `infection.json5`; the report directory is set there.

It is a **diagnostic: local and optional**. Run it when it helps, on changed logic with boundaries or branching (calculations, limits, state transitions, validation rules), not on glue code such as wiring, forwarding controllers or DTOs. It is never a CI gate and never fails on a score; the project sets no threshold. Delete temporary tests first, so the run judges only the tests that stay.

`composer mutation` mutates the application source lines changed against the base branch ([tools.md](tools.md#mutation-testing-local-optional)); keep the local base branch up to date. Every run first collects coverage, then runs the mutants without a coverage driver, executing only the tests that cover each mutated line. Arguments after `--` go to Infection (for example `--show-mutations`). Infection never edits the application source. A change that touches tests only is checked by mutating the file under test in full (`--filter=<source file>`). The commands each stack defines are in [Mutation testing per stack](#mutation-testing-per-stack).

### Reading the output

The console prints one character per mutant:

| Mark | Meaning | Action |
|---|---|---|
| `.` | killed: a test failed | none |
| `M` | escaped: all covering tests passed | judge ([Using the results](#using-the-results)) |
| `U` | not covered: no test runs the line | reported only with `--with-uncovered`; whether the code needs a test follows [How much to test](#how-much-to-test) |
| `T` | timed out | usually an infinite loop caused by the mutation; counts as killed |
| `S` | skipped: the covering tests together take longer than `timeout` | treat as untested; the usual cause is a widely used class covered by many slow feature tests, so add a focused unit test |
| `E` / `X` | the mutated code crashed / was not valid PHP | both count as killed; no action |
| `I` | ignored by a skip rule ([Message-only mutants](#message-only-mutants)) | none; not run and not scored |

The summary reports Mutation Code Coverage (mutants on lines any test executes), the Mutation Score Indicator (killed out of all) and the **Covered Code MSI** (killed out of covered), the one that measures test usefulness. It is a trend indicator, not a target. `infection.log` lists every mutant by status with its diff and covering tests; `infection.html` shows the same per file.

### Investigating an escaped mutant

1. Read the diff and ask which behaviour a caller or user would notice if the mutated code shipped.
2. Find the tests that cover the line (listed under the mutant in `infection.log`) and decide whether one of them should assert that behaviour; strengthening it is preferred to a new test.
3. Rerun the coverage step, then only that mutant with Infection's `--coverage=<coverage directory> --skip-initial-tests --only-covering-test-cases --filter=<file> --id=<hash>`, with the debugger off (`XDEBUG_MODE=off`) and enough memory (`php -d memory_limit=4G`). The id stays valid while the source file is unchanged.

| Mutator | Typical escape | Usually |
|---|---|---|
| `ArrayItem` / `ArrayItemRemoval` in DTOs and controllers | a key removed from `toArray()` or from page props | response contract not asserted: assert the prop or payload value |
| `MethodCallRemoval` | a query constraint, `save()`, dispatch or `merge()` removed | side effect not asserted: assert the persisted state, event or normalised input |
| `IncrementInteger` / `DecrementInteger`, `LessThan` family | an off-by-one on a limit or clamp | missing boundary test |
| `NullSafePropertyCall`, `Coalesce` | null handling changed | missing null or absent input scenario |
| `Concat`, `ConcatOperandRemoval`, `Ternary`, `Coalesce` on message text | wording changed | acceptable ([Message-only mutants](#message-only-mutants)) |

### Message-only mutants

Tests do not assert user-facing wording ([testing rules](#error-and-validation-messages)), so a mutant that changes only wording can never be killed and is not worth killing. The skip configuration lives in the project's `infection.json5`, not in a test: it skips only the cases recognisable without ambiguity, each by the narrowest rule that identifies it, with a comment explaining it. Every mutator stays enabled everywhere else.

- A ternary between two multi-word sentence literals and `parent::__construct($message);` in exceptions are skipped by `ignoreSourceCodeByRegex` and reported as `I`.
- Methods that only supply wording (a validation request's message methods) get no mutants: `global-ignore` on the method name. Custom messages never decide whether a rule fires. The names are in [Mutation testing per stack](#mutation-testing-per-stack).

Still generated and judged by [Using the results](#using-the-results): request validation rules (removed rules, rule strings built with `.`, boundaries); the conditions that decide whether an error is raised, the field keys, the exception class and `throw`; enum labels and other UI labels (product content); and message text built inside logic (a concatenation or interpolation passed to an error collector or an exception). Ignore such a mutant when only wording changes, act on it when a key, a class or a condition changes. One that empties a message list looks like wording but is not: a field with no message is no error at all, so the user is sent back without an explanation. A test kills it without asserting any text (a session-error assertion on the field key, or a non-empty list on the exception).

What the rules cannot do, so that nobody extends them blindly:

- `--mutators=<list>` on the command line switches the per-mutator skip rules off; the wording mutants reappear, so judge them as above.
- `ignoreSourceCodeByRegex` matches one removed line of the mutant diff at a time, in every file. Write it for one whole statement, check that no logic line has the same shape, and add a rule only when the shape is unambiguous; otherwise ignore the mutant when you read the results. A new shape is not covered until it is added. `@infection-ignore-all` puts a test-tool annotation into production code; prefer ignoring the mutant.
- After changing a rule, run Infection with `--show-mutations` on a few files: the logic mutants of validation rules, conditions and boundaries must still appear.

### Using the results

There is no gate: no escaped mutant has to be killed, and the pull request reports none.

- **Act** only on a mutant that shows a missing assertion a user or a caller would notice (an outcome, a boundary, an error type, a field key, a code, the condition that raises an error, a state transition).
- **Strengthen an existing test** by preference: extend its assertion. Never add a feature fixture, a scenario or a data-provider case per mutant, and never a test only to reach a line or raise a score.
- **Ignore** the rest without listing it: equivalent mutants (the mutated code behaves identically, for example swapped arguments of `max()`), wording mutants (never kill one by asserting the message) and mutants in glue code. Do not contort code or tests to kill a mutant.
- A mutant killed only by a test that asserts wiring, copies or incidental defaults means that test is not useful; rework it into a behavioural test, or delete it when it duplicates another.

Operating notes:

- A run that reports 100 % within seconds is broken. The usual cause is a PHPUnit configuration passed through `--test-framework-options`, which PHPUnit rejects, so every mutant counts as killed.
- A timed-out mutant counts as killed by default, so a busy machine can hide an escape behind a timeout; `--with-timeouts` counts it as escaped.
- A test suite that uses the database must not run its workers against one shared database during the coverage and the Infection steps, otherwise their collisions count as killed mutants ([Mutation testing per stack](#mutation-testing-per-stack)).

### Mutation testing per stack

#### On Laravel

- Mutated code is `app/`. The Composer scripts are `composer mutation` (changed lines), `composer mutation -- --git-diff-base=<parent-branch>` (a stacked branch) and `composer mutation:full -- --filter=app/Path/File.php` (every mutant in one file). Reports go to `build/infection/`.
- To rerun one escaped mutant: `XDEBUG_MODE=off php -d memory_limit=4G vendor/bin/infection --coverage=build/infection/coverage --skip-initial-tests --only-covering-test-cases --filter=<file> --id=<hash>`.
- `ArrayItemRemoval` in form requests (a rule such as `nullable`, `max:255` or `in:` removed) usually means a missing validation edge: extend the validation-keys test, or the unit test of the rule, with the blank, too long or disallowed value and assert the error key.
- Message methods of form requests (`messages()` and the `*Messages()` helpers) get no mutants: `global-ignore` on the method name.
- Still judged by [Using the results](#using-the-results): everything in `rules()` (removed rules, rule strings built with `.`, boundaries).
- Empty-message mutants are killed with `assertSessionHasErrors(['field'])`.
- Run by hand, a Laravel suite that uses the database needs `LARAVEL_PARALLEL_TESTING=1` on both the coverage and the Infection step, otherwise the workers share one test database.
- After changing a skip rule, check it with `composer mutation:full -- --filter=<file> --show-mutations`.

#### On LSR

- Mutated code is `src/`. The tests run with `vendor/bin/phpunit` (prefix `XDEBUG_MODE=off`); `composer mutation` runs Infection ([tools.md](tools.md#mutation-testing-local-optional)).

## Pinning Lunar behaviour

<!-- from: package/lunar -->
- Each Lunar behaviour the project relies on, and that the documentation does not make obvious, gets one pin test in `tests/Feature/Lunar`. A pin fails when a Lunar upgrade changes the behaviour. Its name describes the behaviour, not the version.
- A pin is deleted when project code covers the same behaviour, so the suite keeps no duplicate.
- A pipeline step, modifier or driver the project replaced is pinned against the Lunar original on non-ambiguous inputs, so an upgrade that changes the original's other behaviour fails the pin. The integer replacement of `CalculateLines` is pinned this way: its line totals equal Lunar's on amounts where float and integer rounding agree, and a separate unit test covers the amounts where they differ.

## Admin tests

<!-- from: package/lunar -->
- Admin tests live in `tests/Feature/Admin`. They authenticate as a Lunar `Staff` on the `staff` guard and use Livewire or Filament testing helpers against the project's own class.
- Lunar's built-in resources are not re-tested.

> Local additions go below this managed block. Content outside the block is preserved when the guidelines are synced.
<!-- esoul-guidelines:end testing -->
