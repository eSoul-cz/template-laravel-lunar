<!-- esoul-guidelines:begin requests-and-validation -->
# Project guidelines - Requests and validation

Incoming HTTP input is validated with Laravel form requests. The controller only handles a request that has already been validated and authorized.

- All validation MUST happen in the form request class.
- Form requests MUST live in the `App\Infrastructure\Http\Requests` namespace and directory.

## Where validation lives

- A form request answers one question: **is this input acceptable to receive** - shape, type, presence, format, referential existence.
- A domain rule answers a different one: **is this operation valid** - whether a record may move to a state, whether a value may change on a date. Domain rules live in the framework-free core, are enforced by the command handler and are authoritative ([cqrs.md](cqrs.md)).
- A domain rule MAY be mirrored as a validation rule so the user gets a field-level message. The mirror is a convenience; it MUST NOT become the only place the rule exists.
- **Authorization is not validation.** `authorize()` delegates to the policy for the action ([security-and-data.md](security-and-data.md#authorization)). It MUST NOT re-implement a role check.

## Validation rules

- Rules are defined in `rules()`.
- A custom rule MAY be a closure in `rules()`, or a class implementing `Illuminate\Contracts\Validation\ValidationRule` when several requests reuse it.
- Complex cross-field validation SHOULD go in `withValidator()`.
- Every custom rule is tested, including its edge cases.
- Use `Rule::exists()`, `Rule::unique()` and `Rule::enum()` instead of the string syntax for those rules.
- A value the client manages as data (a type, a tag, a category) MUST be validated with `Rule::exists()` against its table, never with `Rule::in()` against a hardcoded list.
- An upload is validated by its **content** (`finfo`), not by the type the browser claims, against types and size limits from configuration. The form request validates; the command handler writes the file to a private disk through the storage contract ([deployment.md](deployment.md#file-storage)). A request never picks a disk.

## Decimal input

When users type decimals in a comma locale (`1,25`, or `1 234,50` pasted from a spreadsheet), nothing downstream may see that string.

- The comma decimal separator is normalised in `prepareForValidation()`, before the rules run. Accept `,` and `.`; reject a value containing both.
- Thousands separators (space, non-breaking space, narrow no-break space) are stripped in the same step.
- The normalised value is validated as a decimal with an explicit scale, for example `decimal:0,2`. `numeric` alone accepts scientific notation and is too permissive for money.
- **A decimal MUST NOT be cast to `float`.** It stays a string through validation and becomes a money value object or an exact decimal where the request maps to the command.
- A percentage entered in the interface becomes its factor exactly once, in the mapping method.

## Dates and time zone

- A date is validated with an explicit format (`date_format:Y-m-d`); a time with `H:i`. Never rely on whatever `strtotime()` accepts.
- A date or time the user enters is local time in the business time zone and is converted to an instant once, in the mapping method ([domain.md](domain.md#time-and-dates)). The application clock (`app.timezone`) stays UTC.
- A value the server can compute (a duration, a total) is computed on the server. The browser MUST NOT send it, and if it does it is ignored.

## Retrieving validated data

- The request data is annotated with PHPDoc `@property-read` on the form request and MAY be read as properties (`$request->name`).
- Every property the client sent is transformed to its type in `passedValidation()`, with the request's typed accessors (`boolean()`, `integer()`, `string()`, `array()`), and set with `merge()`. Transform and merge only the keys that are present (`has()` / `array_key_exists`) and keep a sent `null` as `null`: every typed accessor fills a default for a missing or null key (`boolean()` gives `false`, `integer()` gives `0`, `string()` gives `''`), so merging all of them makes a field the client never sent look sent and a cleared one look like `false`, and the [partial update](#partial-updates-not-sent-or-cleared) rule cannot hold.
  - `string()` returns a `Stringable`; call `->toString()`.
  - `float()` MUST NOT be used for money, quantities or hours.
  - Only scalars and arrays go into the validated data; DTOs, value objects and enum cases are built later, in the mapping method, because the input bag cannot hold them.
  - **An enum field holds the backing value**, never the case. It is validated with `Rule::enum()`, stays the scalar the client sent (`passedValidation()` does not call `enum()`; an int-backed enum is transformed with `integer()`), is annotated `value-of<EnumClass>` (the PHPStan type of the backing value of a backed enum), and the mapping method turns it into the case with `EnumClass::from()`.

  ```php
  protected function passedValidation(): void
  {
      if ($this->has('active')) {
          $this->merge(['active' => $this->input('active') === null ? null : $this->boolean('active')]);
      }
  }
  ```

  ```php
  /**
   * @property-read value-of<MembershipRole> $role
   */
  final class StoreMemberRequest extends FormRequest
  {
      public function rules(): array
      {
          return ['role' => ['required', Rule::enum(MembershipRole::class)]];
      }

      public function toCommand(): CreateMemberCommand
      {
          return new CreateMemberCommand(role: MembershipRole::from($this->role));
      }
  }
  ```

## A request never writes

A form request MUST NOT write: no create, update or delete, no queued job, export or mail. `prepareForValidation()`, `rules()`, `withValidator()` and `passedValidation()` may query to validate; they may not persist. Creating a missing related record "because the user typed a new one" is a write and belongs to the command handler, inside its transaction, where it can also record who did it. A mutating request is `Critical` in review: a rejected request would be half-applied, and a rule that mutates cannot be tested in isolation.

## Partial updates: not sent or cleared

A partial update tells "field not sent" from "field cleared", so a user can empty a value. The request tells the two apart (`$this->has('field')` or `array_key_exists`), and the mapping method hands the command a distinct "not sent" state (for example an `Optional` wrapper) instead of `null`, so the handler leaves an unsent field unchanged and empties a cleared one. A test covers both: the field absent keeps the stored value, the field sent empty clears it.

## Mapping to commands and queries

- **A form request SHOULD map itself to a DTO through a public `to<Something>()` method**: `toCommand()` generally (a command is an interfaced DTO), `toQuery()` for a query, `to<Dto>()` for another DTO (for example `toFilter()`). The method builds the DTO from the validated scalars, and the controller dispatches what it returns (`$request->toCommand()`) instead of reading the request's fields itself. The request does the mapping, not a `fromRequest()` factory on the command: a command lives in `App\Domain\CQRS` and MUST NOT depend on a request of `App\Infrastructure` ([architecture.md](architecture.md#dependency-direction)). The DTO's constructor holds only its promoted properties ([Constructing commands and queries](cqrs.md#constructing-commands-and-queries)).
- The mapping method is where request-shaped values become domain-shaped ones: a decimal string becomes a money value object, a local date becomes an instant ([Dates and time zone](#dates-and-time-zone)), a percentage becomes a factor, and the backing value of an enum becomes the case with `EnumClass::from()` ([Retrieving validated data](#retrieving-validated-data)). In a [partial update](#partial-updates-not-sent-or-cleared) it passes the "not sent" state for a key the client did not send, never `null`.
- The mapping method passes the acting user (`$this->user()`) into every command and into every query whose result depends on who asks. The handler never reads it from the request.

## Testing

Each request is tested through its endpoint: the accepted input, each rejected field by its key (`assertSessionHasErrors(['field'])` or `assertJsonValidationErrors(['field'])`) with the status, never by the message text ([testing.md](testing.md#error-and-validation-messages)).

> Local additions go below this managed block. Content outside the block is preserved when the guidelines are synced.
<!-- esoul-guidelines:end requests-and-validation -->
