# Code map

One row per class under `src/`. Read the rows you need instead of opening neighbouring files. Kept in sync by the
`docs-sync` rule and verified by `tools/check-docs.php` (every class must have a row; paths and tests must exist).

Columns: Path | Layer | Responsibility | Key public API | Covering test (`-` if none)

| Path | Layer | Responsibility | Key public API | Test |
|---|---|---|---|---|
| `src/Kernel.php` | framework | Symfony micro-kernel; loads `config/` | — | HealthEndpointTest |
| `src/Controller/HealthController.php` | UserInterface (shared) | `GET /health` liveness probe used by the compose healthcheck | `__invoke(): JsonResponse` | HealthEndpointTest |
