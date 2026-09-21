---
name: symfony-testing
description: Test recipes for this Symfony 7.4 project - PHPUnit 11 unit tests with in-repo fakes, KernelTestCase and WebTestCase JSON API tests against MariaDB with dama rollback, MockHttpClient scripting for provider adapters including timeouts, MailerAssertionsTrait, MockClock, Messenger in-memory and sync transports. Use when writing or fixing tests under tests/ or wiring the test environment.
disable-model-invocation: true
---

# Symfony testing recipes

Pick the cheapest test that gives real evidence. Fragments in [reference.md](reference.md).

| Need | Kind | Fragment |
|---|---|---|
| Domain rule / state transition | Unit, plain PHPUnit | T1 |
| Failover policy branches | Unit with in-repo fake providers | T2 |
| Provider adapter over HTTP (Twilio) | Unit with `MockHttpClient` | T3 |
| SMTP provider builds the right email | KernelTestCase + `MailerAssertionsTrait` on `null://` | T4 |
| Repository round-trip | KernelTestCase + real DB (dama rollback) | T5 |
| API contract (202/200/404/422) | WebTestCase JSON | T6 |
| End-to-end request -> delivery -> attempts | WebTestCase + in-memory `async` drained on `delivery.bus` | T6 |
| Time-dependent logic (throttle windows) | `MockClock` injected as `ClockInterface` | T7 |
| "Message was dispatched" only | Messenger `in-memory://` transport | T8 |
| Test env wiring (dama, sync transport, null mailer) | config | T9 |

## Conventions (see `.cursor/rules/testing.mdc`)
- `test_it_<does_something>_when_<condition>()`; arrange / act / assert blocks; assert outcomes, not call order.
- Fakes live in `src/NotificationPublisher/Infrastructure/Provider/Fake/`, builders in `tests/Support/`.
- Run one test: `docker compose exec -T app vendor/bin/phpunit --filter <Name>`; full suite before ending a phase.
- Test DB is `app_test` (Doctrine `dbname_suffix`); schema is created by the `test` make target / COMMANDS.md recipe.
