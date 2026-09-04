---
paths:
  - 'tests/**'
---

# Tests

## Register suite-wide Pest hooks on the pest() chain
A file-level `beforeEach()` inside a test file works normally. The trap is only in tests/Pest.php: `beforeEach(fn () => ...)->in('Feature')` there silently never fires, because `pest()->extend(...)->in('Feature')` already claims that directory. No error, no warning, the hook just does not run.

For a suite-wide hook, chain it: `pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature')->beforeEach(...)`.

Verified on Pest v5.1.3. This is how the API bearer token header reaches every feature test; tests exercising the auth guard itself call `$this->flushHeaders()`.
