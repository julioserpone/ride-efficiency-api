# AI project rules

Read the applicable rule files before changing code. When a change spans areas,
read every matching file. Keep these rules concise and defer to the contributor,
coding, and testing guides for full procedures.

| Path | Read |
| --- | --- |
| `app/Http/**`, `routes/**` | [API boundaries](api-boundaries.md) |
| `app/Actions/**`, `app/Services/**`, `app/Jobs/**`, `app/Support/**` | [Application architecture](architecture.md) |
| `app/Models/**`, `database/migrations/**` | [Data integrity](data-integrity.md) |
| `database/seeders/**`, `database/factories/**`, `tests/**` | [Testing and data safety](testing-and-data-safety.md) |

Repository-wide delivery, coding, and testing guidance lives in
[`docs/CONTRIBUTING.md`](../../docs/CONTRIBUTING.md),
[`docs/CODING_STANDARDS.md`](../../docs/CODING_STANDARDS.md), and
[`docs/TESTING.md`](../../docs/TESTING.md). `AGENTS.md` is the project-wide
assistant instruction file.
