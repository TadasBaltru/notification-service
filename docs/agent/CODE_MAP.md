# Code map

One row per class under `src/`. Read the rows you need instead of opening neighbouring files. Kept in sync by the
`docs-sync` rule and verified by `tools/check-docs.php` (every class must have a row; paths and tests must exist).

Columns: Path | Layer | Responsibility | Key public API | Covering test (`-` if none)

| Path | Layer | Responsibility | Key public API | Test |
|---|---|---|---|---|
| `src/Kernel.php` | framework | Symfony micro-kernel; loads `config/` | — | HealthEndpointTest |
| `src/Controller/HealthController.php` | UserInterface (shared) | `GET /health` liveness probe used by the compose healthcheck; OpenAPI template for later endpoints | `__invoke(): JsonResponse` | HealthEndpointTest |
| `src/NotificationPublisher/UserInterface/Http/OpenApi/ProblemSchema.php` | UserInterface | RFC 7807 `Problem` schema; 1.3 error listener must emit this shape | `#[OA\Schema(schema: 'Problem')]` | OpenApiCoverageTest |
