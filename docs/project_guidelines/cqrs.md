<!-- esoul-guidelines:begin cqrs -->
# Project guidelines - CQRS

CQRS (Command Query Responsibility Segregation) separates the operations that change state (commands) from the operations that read it (queries). The layers around it are in [architecture.md](architecture.md).

- **Commands are the only way business state changes.** A handler owns one business operation, its transaction and, where the operation notifies an external system, its outbox write (the project's outbox ADR).
- **Queries are the read side.** They hand callers a complete, ready-to-use result as DTOs, so controllers, exporters, console commands and other handlers never query models or reshape data themselves. Many small, specialised queries are preferred over one query with many modes, and queries compose other queries.
- A screen and its export read the **same query**. A data import dispatches the **same commands** as the user interface; it never inserts into tables directly.
- When the project has a reference module, new modules follow its shape. When this file and the reference module disagree, report it instead of copying either.

## The package

The project uses the shared packages [`esoul-cz/laravel-cqrs`](https://github.com/eSoul-cz/laravel-cqrs) (Laravel integration) and `esoul-cz/cqrs` (buses, contracts, attributes). Every Laravel template ships them, installed from Packagist (`packages.esoul.cz` mirrors them). Do not add an application-local copy of the buses or contracts.

- `Esoul\Cqrs\Contracts\CommandInterface` / `QueryInterface`: a command or a query.
- `Esoul\Cqrs\Contracts\CommandHandlerInterface` / `QueryHandlerInterface`: their handlers.
- `Esoul\Cqrs\Contracts\CommandBusInterface` (`dispatch()`) / `QueryBusInterface` (`execute()`).
- `Esoul\Cqrs\Attributes\HandlesCommand` / `HandlesQuery`: bind a handler to its command or query.
- `Esoul\LaravelCqrs\Facades\CommandBus` / `QueryBus`: facades over the container-bound buses.

Both buses and all handlers are container singletons, instantiated through the container with constructor injection. Configuration lives in `config/cqrs.php` (published with `php artisan vendor:publish --tag=cqrs-config`).

## Handler lifetime

**One handler instance serves every dispatch in a process**, including every job a long-running queue worker runs. The worker drops scoped services between jobs, but a singleton keeps what its constructor received.

- A handler and the services it holds MUST NOT keep state between calls: declare handlers `final readonly`. State of one call (a lookup cache, counters) is a local variable or an object created in `handle()`.
- A scoped service MUST NOT be a constructor parameter of a handler or of anything a handler holds. Inject `Illuminate\Contracts\Container\Container` and `make()` the service when it is used.

## Discovery

Handlers are discovered on boot from `cqrs.discovery.paths` (default `app/Domain/CQRS`). The directories `app/Domain/CQRS/Command` and `app/Domain/CQRS/Query` MUST exist, otherwise the buses cannot be resolved. Discovery results are cached in `storage/framework/cache/discovery`; clear it when a handler is not picked up.

- Commands and their handlers live in `App\Domain\CQRS\Command\<Module>`, queries and theirs in `App\Domain\CQRS\Query\<Module>`.
- Every class implements the interface of its type, and every handler declares `#[HandlesCommand(SomeCommand::class)]` or `#[HandlesQuery(SomeQuery::class)]`.
- A command handler MAY handle several closely related commands: one attribute per command and an explicit `instanceof` branch per class, ending in a thrown `RuntimeException` for an unsupported class. It MUST NOT group unrelated logic.
- `HandlesQuery` is not repeatable: **a query has exactly one handler**.
- A command or query without a registered handler throws `RuntimeException`. That is a wiring defect to fix, not a condition to catch.

```php
#[HandlesCommand(CloseOrderCommand::class)]
final readonly class CloseOrderCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    /** @param CloseOrderCommand $command */
    public function handle(CommandInterface $command): bool
    {
        return $this->db->transaction(function () use ($command): bool {
            // lock, check the business rule, write, record history, write outbox where it applies
            return true;
        });
    }
}
```

## Creating commands and queries

- Generate them with `php artisan make:cqrs-command <Group>/<Action>` and `php artisan make:cqrs-query <Group>/<Action>`, always with `--handler` and `--return=<type>`. Handlers alone can be generated with `make:cqrs-command-handler` and `make:cqrs-query-handler`.
- Replace the generated `TODO` markers and `LogicException` before committing, and keep `declare(strict_types=1);` (Pint enforces it).
- The return type is never `void`; use `bool` or `null` when there is no meaningful value. The type is declared once, as the generic parameter (`@implements QueryInterface<ProductDetail>`), and propagated to the handler.
- `<Group>` is a module noun, `<Action>` a verb (`Order/PlaceOrder`, `Catalog/GetProductDetail`), both in the glossary's terms. The `Command` or `Query` suffix is appended when omitted.

```bash
php artisan make:cqrs-command Order/PlaceOrder --handler --return=Order
php artisan make:cqrs-query Catalog/GetProductDetail --handler --return=ProductDetail
```

## Writing commands

- A command represents a business action, not plain CRUD that a model handles on its own.
- The command class is a readonly DTO with constructor property promotion and no behaviour. The handler holds the logic and does no reads for callers.
- **A handler orchestrates; it does not calculate.** It loads the rows, builds domain values, calls the framework-free calculator or rule in `App\Domain\<Module>`, and persists or returns the result.
- **The handler owns the transaction.** A handler that changes more than one row, or reads a row it is about to change, runs inside one database transaction (injected `ConnectionInterface::transaction()`). A nested `transaction()` joins the caller's, so the whole operation commits or rolls back together.
- **Concurrent writes are locked.** Rows that concurrent requests may change are updated atomically (`UPDATE ... SET x = x + 1`) or read with `lockForUpdate()` inside the transaction. Never read, compute in PHP and write back unlocked. Review checks this; a multi-process concurrency test exists only for a named damaging invariant, in the `db-engine` group ([testing.md](testing.md#keeping-the-integration-run-short)).
- **External writes go through a contract.** A handler reaches an external system only through a contract in `App\Application\Contract`. Where the project's outbox ADR applies (writes that must not be lost or duplicated: payments, invoices, ERP), the write is an outbox message in the same transaction instead.
- **Changes are traceable.** An operation that must be recorded writes its history entry in the same transaction, with the acting user the command carries.
- Scope that differs per tenant, sales channel or legal entity is an explicit command property, resolved once by the caller. Handlers never read the request, the session or global state.
- HTTP input is validated by form requests before the command exists ([requests-and-validation.md](requests-and-validation.md)). The handler enforces the business rules and reports a violation with a typed exception in `App\Domain\Exception` that carries the facts as properties. A value that can never be valid raises `InvalidArgumentException`. Callers react to the type and properties, never to the message. Tests MAY also check the internal message ([testing.md](testing.md#error-and-validation-messages)).
- A handler MAY dispatch other commands and execute queries through buses injected in its constructor, not through facades. It reaches another module's data only that way, never through that module's models ([architecture.md](architecture.md#modules)).

## Writing queries

- A query is a read: it never changes state and contains no business rule that modifies anything.
- The query class is a DTO without behaviour. It is not readonly; its properties use asymmetric visibility (`private(set)`) and are set through fluent setters, not constructor promotion.

```php
/** @implements QueryInterface<list<ProductSummary>> */
final class ListProductsQuery implements QueryInterface
{
    private(set) ?string $search = null;

    public function search(string $value): self
    {
        $this->search = $value;

        return $this;
    }
}
```

- Required inputs are kept to a minimum, so a query stays usable without filters. The handler, not the query, validates required inputs and throws `InvalidArgumentException` when one is missing.
- **The acting user.** A query whose result depends on who asks takes the acting user as an input and returns only what that user may see. Callers that serve a user's request MUST pass it. Omitting it is only for trusted internal callers (console commands, jobs, other handlers).
- A query MUST NOT return an Eloquent model or an associative array. It returns a DTO from `App\Application\Dto\<Module>`, a list of DTOs or a scalar.
- The handler returns a complete result: mapping to DTOs, re-indexing, filtering and formatting belong in the handler, not in the caller. Controllers and services apply only minimal presentation-specific transformation.
- A handler MAY execute other queries through an injected `QueryBusInterface` instead of repeating their logic. Large result sets use `lazy()` or `cursor()`, not `get()`.

## Calling commands and queries

- All commands and queries are called through the buses. Controllers MAY use the facades; handlers, services and jobs inject `CommandBusInterface` and `QueryBusInterface`.
- A console command, listener or job that changes state dispatches a command. A state change outside a command is `Major` in review.

```php
final readonly class SomeService
{
    public function __construct(private CommandBusInterface $commandBus) {}

    public function run(): void
    {
        $this->commandBus->dispatch(new SomeCommand(/* ... */));
    }
}
```

## Testing

Handler tests live in `tests/Feature/CQRS/<Module>` and run through the buses from the container. A handler reached only through one endpoint is covered by that endpoint's tests; any other handler follows [How much to test](testing.md#how-much-to-test). The acting-user narrowing of a query is covered by its ownership and scope tests ([testing.md](testing.md#authorization-tests)). See [testing.md](testing.md).

> Local additions go below this managed block. Content outside the block is preserved when the guidelines are synced.
<!-- esoul-guidelines:end cqrs -->
