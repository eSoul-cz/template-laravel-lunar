<!-- esoul-guidelines:begin integrations -->
# Project guidelines - Integrations

A shop depends on other systems: a payment gateway takes the money, a carrier takes the parcel, an ERP holds stock and issues the documents. Without shared rules each integration is shaped differently and none can be tested. The project's ADR for each system records its contract: what it owns, which direction data flows and which operations exist. This file holds the rules every such integration follows.

## Every integration

- **A port per system.** An external system is reached only through a contract in `App\Application\Contract`. The vendor client lives in `App\Infrastructure\Integration\<System>`, and application and domain code never reference a vendor SDK class ([architecture.md](architecture.md#modules)).
- **A fake per port.** The fake lives next to the real client and is chosen by a driver setting (`<SYSTEM>_DRIVER`). Tests and local development use it. A production configuration MUST NOT accept the fake.
- **No external call in a page request.** An outgoing call runs in a queued job with a bounded retry policy, so a customer finishes checkout while the system is down. An exception (a lookup the customer waits for, an action only staff trigger) needs an ADR, a short timeout, a cache and a manual fallback.
- **Failures are classified.** A client throws a typed exception that says whether the call is worth repeating: timeouts, 5xx and rate limits are transient, a rejection of the content is permanent. A client never retries itself; the job or the outbox owns the retry policy. Retrying a permanent failure forever hides it.
- **Failures are visible.** A failure is recorded as an operator event, a record staff see in the admin with a human-readable reason. A silent `catch` is a `Major` review finding ([code-review.md](code-review.md)). A failed integration call never changes the state of the order by itself.
- **The call log is redacted.** Each call records method, path, status, duration and a correlation id. Credentials, tokens, payer data and bodies that name people never reach a log ([security-and-data.md](security-and-data.md#personal-and-client-data)). Credentials come from configuration, never from code ([security-and-data.md](security-and-data.md#secrets)).
- **Amounts are integer minor units with a currency.** A provider's decimal number is read as text and converted at the boundary, once, by moving the decimal point or with bcmath, never through a float ([domain.md](domain.md#money)).

## Writes and the outbox

This section applies where the project's outbox ADR applies. Without one, a write to an external system is a queued job that carries a stable idempotency key ([Idempotency keys](#idempotency-keys)), and the rules of [Every integration](#every-integration) still hold.

- A write that must not be lost or duplicated (a payment, a shipment, an invoice or any ERP document) is an outbox message written by the command handler in the same transaction as the state change ([cqrs.md](cqrs.md#writing-commands)). The project's outbox ADR defines the mechanism. Dispatching a job after the commit loses the write when the process dies between the two.
- The outbox row is the source of truth and the queued job only wakes it up. A message is closed only after the external write succeeded and its response was stored. A lost job, a crash or an ambiguous result (a timeout without a response) leaves it open for the sweeper, which is safe because every write carries a stable idempotency key.
- Messages SHOULD be ordered per (aggregate, target): while one is processing or waiting for a retry, later ones of the same pair wait, and a dead message stops only that pair. A dependency across targets (an invoice after a capture) is an explicit link. An operation whose failure must not hold up the rest is listed as non-blocking in configuration.
- A deduplication key is unique among undelivered messages, and a newer upsert of the same aggregate and operation supersedes the older pending one.
- Retries are bounded with growing delays for transient failures; a permanent failure goes straight to `dead` with an operator event. A circuit breaker SHOULD pause a target that keeps failing. Staff retry, cancel or resolve a message in the admin with a reason, and each action is audited.
- A transport delivers one operation, throws the typed transient or permanent failure and never retries itself. Only a command handler creates messages. A record imported from the remote system never enters the outbox beyond its link.
- Retention is part of the ADR: delivered messages lose their payload after a period, dead ones stay until resolved.

### Idempotency keys

- Every write to an external system carries an explicit, stable key built from business identifiers (the order number, the kind of document, the payment instruction), never from a random value and never different between attempts. Repeating the write updates the same remote record.
- One function builds the key, and it exists before the first delivery attempt. It is written exactly as built: a remote system that treats a case variant as a second key leaves a record that no lookup can find again.
- A remote system without idempotency keys is made safe by checking first: create only for an attempt that holds no remote id, read the remote history before repeating a refund, find the record before creating it. The gateway returns what exists instead of failing on a repeat.
- A change made in the remote system is decided by comparing content with the last sent and the last read payload. The remote modification timestamp only triggers the comparison, because the shop's own writes change it too.

## Scheduled reads and reconciliation

- A read is a scheduled job. The job calls a synchroniser, which reads through the gateway and hands the result to commands. The commands write the local mirror and move the cursor in one transaction.
- A synchronisation reads the changes since its cursor, and a periodic full snapshot reconciles what the changes missed, including removals. A row is rewritten only when its hash differs, so a repeated run changes nothing.
- A snapshot that looks wrong is not applied: an empty list while local rows are linked to the remote system ends nothing and zeroes nothing. It marks the cursor failed and raises an operator event.
- A value the shop cannot take over skips that record with an operator event and does not stop the run. The event never carries a value that only some roles may see, such as a purchase price.
- A cursor keeps the time of its last success. A failed run keeps that time, records its reason and shows both in the admin.
- A notification that can be lost is backed by a reconciliation job that reads again what is still unsettled (payments waiting for the customer, shipments in transit). Each run takes a bounded batch, the items read longest ago first, and marks them read before it calls the remote system, so an item that keeps failing never crowds out the others.

## Jobs

- A job is a thin entry point: it builds its input from identifiers, calls the gateway or synchroniser and dispatches commands. It holds no business rule and changes no state outside a command ([cqrs.md](cqrs.md#calling-commands-and-queries)).
- A job declares its tries and its backoff, and a job that must not overlap is unique or guarded by a lock. A job is idempotent, because a retried job runs twice.
- A `failed()` hook records the failure through a command; the framework calls it with the exception only, so the bus comes from the facade.
- A job names its queue after the integration, so workers can be scaled per queue without a code change.
- A job that wakes up on a webhook is a trigger: it reads the state from the source and never trusts the notification's content.

## Payment gateways

- A gateway is a Lunar payment driver registered with `Payments::extend('<name>', ...)` in `App\Domain\Providers`. It extends `Lunar\PaymentTypes\AbstractPayment` behind the project's gateway port and returns Lunar's `PaymentAuthorize`, `PaymentCapture` and `PaymentRefund`. The driver is thin: it dispatches the command that starts the payment or the refund, maps the result and dispatches `PaymentAttemptEvent`. It resolves the order as Lunar does (the given order, else the cart's draft order, else a new order from the cart).
- Lunar writes an order status from a payment type's `authorized` setting. A project whose order state machine is a command sets it to `null` for every payment type and moves the status itself, including for offline methods.
- A driver operation the order process does not allow (a capture outside the capture step) returns a failure result with a reason, never an exception. An offline method that must keep the order status is a project subclass of Lunar's offline payment type.
- A payment starts as an outbox message and has a local payment attempt row that holds the provider's payment id and state. The provider's payment id and state are recorded once, as a Lunar `Transaction` (`intent`, `capture`, `refund`), and the order's payment state follows from the transactions.
- **Notifications are verified.** The endpoint sits outside the session and CSRF middleware. Each source configures its verification (a secret in a header or query, an HMAC of the raw body, or an allowlist of source addresses). A request that fails it gets 403 and is not stored, and a source without a secret accepts nothing.
- **Notifications are answered first.** A verified request is stored, the provider gets the answer it expects at once, and the processing is queued after the response. A sweeper queues stored notifications that were never processed.
- **Notifications are idempotent.** The processor takes only identifiers and reads the payment's real state from the provider's API; it never trusts the payload. It records the pair (provider payment id, state) once through one command, and never moves an attempt back from a final state. It throws a permanent failure when retrying cannot help; any other failure is retried with growing delays before the notification fails.
- **The redirect is never the result.** The customer's return page shows "verifying payment" and polls the shop for the attempt's state. The return URL carries only the local attempt id.
- **The status mapping is in one place.** One class or enum maps the provider's states to the shop's attempt and instruction states, with a test per state. An unknown state is recorded as an operator event, never guessed.
- A failed or expired payment is retried from the result page or from a signed link with an expiry, which creates a new attempt for the same payment instruction. A paid or cancelled instruction refuses it.
- A capture and a void are outbox operations of the order. A failed capture is decided by reading the payment's state: already captured counts as delivered. A pre-authorization has a watchdog that warns before it lapses.
- A refund is an outbox operation. It is refused above what the capture charged less the refunds already recorded or waiting. The transport compares the provider's refund history with the shop's records, so a refund the provider already holds is recorded and not repeated, and a pending refund is retried until the history shows it settled. A refund never changes the paid amount; the credit note belongs to accounting.

## Carriers and shipping labels

This section applies when the shop integrates a carrier or a shipping aggregator.

- One port serves every carrier, and a carrier service is one value object in one form, never a string that each caller parses.
- A shipment has a stable external id (the order number and the shipment sequence). Adding it again returns the first packages, and dropping a missing package succeeds.
- The transport records the carrier's answer (package numbers, tracking numbers, label location) with a command as soon as the carrier accepts the shipment, before it downloads the label. The label download is a separate outbox operation that runs after the shipment exists and stores the PDF on a private disk. A refused shipment stays a draft for staff. An accepted one whose download failed is retried or dropped at the carrier, and is never sent again with other contents.
- A handover to the carrier is one record per carrier batch. The order moves to dispatched only when all of its kept shipments are handed over, and the customer notice carries the tracking links.
- Tracking is a scheduled job per carrier that reads the history of the shipments in transit. Each state is stored once per shipment, time and state, a mapping in one place turns the carrier's states into shipment states, and the newest mapped state wins. Delivery and return move the order through commands.
- Checkout never calls the carrier. Pickup points are synchronised on a schedule into a local table, upserted by network and external id, and deactivated when the list no longer has them, never deleted. When the carrier fails, or lists nothing for a network that has active points, nothing changes. The server validates the chosen point and stores a snapshot of it with the order.
- A shipping price is calculated inside Lunar by a shipping modifier from local data ([Calculation cost](architecture.md#calculation-cost)); a carrier API is never called during a cart calculation.

## ERP export, import and stock

This section applies when the shop integrates an ERP or accounting system.

- When the ERP is the system of record for documents, the shop writes orders and payments to it and reads the numbers, due dates, tax points and stock. It never computes a value the ERP owns, and it never assumes the ERP is reachable: every read has a locally stored answer and every write is queued.
- An order export is an outbox upsert under the order's idempotency key. A derived document extends that key with the kind of document and the instruction or sequence number, so each document has its own key. A document that depends on another (an invoice after the capture, an advance payment after the advance invoice) names it as a dependency.
- A document that staff create in the ERP and that the shop does not know is listed for staff. The import is a command that matches the customer and the lines, marks the order with its source and writes back only a link. The lines of an imported order belong to the ERP, and the shop never writes them.
- A correction made in the ERP is detected by content, applied through an audited command as an order revision (a changed total creates a payment task or a refund task) and never echoed back to the ERP.
- A price list or catalogue import finds a variant by the remote id, and before the first run by its SKU. An item with no variant becomes a draft in a queue for staff and is never published by the import. An item the remote system ends ends its variant through the lifecycle command, and offering it again does not bring the variant back.
- A stock synchronisation reads a full snapshot or the changes, computes the sellable quantity (stock less reservations, never below zero) and writes only the rows that differ, through a command. The command raises the stock-changed events inside its transaction, so derived data (availability, composite products) is recomputed or the whole snapshot rolls back and retries. The remote system's reservation at order time is the authority; the shop's stock is a mirror.
- Freshness is measured: a health check compares each cursor's last success with the schedule, raises an operator event when it is stale, and past a longer threshold the storefront says that availability is being verified instead of showing stock. Whether ordering stays allowed is the ADR's decision.

## Testing integrations

- A contract test case per port runs against the fake, against the HTTP driver with recorded or simulated responses, and, in the `sandbox` group, against the provider's test API ([testing.md](testing.md#test-groups)). A new driver behaviour is added to the contract first, then to the drivers. A recording keeps no headers and no credentials, and nothing is recorded against production.
- A webhook test proves that a failed verification gets 403 and is not stored, that a repeated notification is processed once and that the state comes from the provider's API, not from the payload.
- An idempotency test runs a write twice and asserts that the remote system holds one record.
- Payments and the ERP's documents are tier-1 flows ([testing.md](testing.md#how-much-to-test)).

> Local additions go below this managed block. Content outside the block is preserved when the guidelines are synced.
<!-- esoul-guidelines:end integrations -->
