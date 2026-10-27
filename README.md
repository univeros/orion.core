# orion-search/core

The framework-free core of Orion: the neutral query and index-schema ASTs, the engine adapter contracts
(`SearchAdapter`, `SchemaAdapter`, `WriteAdapter`, `QueryCompiling`) and the `Capability` model, the index
lifecycle (planner, versioned indices behind an alias, zero-downtime reindex by outbox replay, signed receipts),
the transactional outbox and its drain, the tenancy seam, the `search:` manifest, the agent tools and a development
`InMemory` engine. Nothing here imports a framework, an ORM or an engine client.

```sh
composer require orion-search/core orion-search/pdo
```

```php
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\IndexName;
use Orion\Orion;
use Orion\Pdo\PdoStorage;
use Orion\Query\Query;
use Orion\Registry\EngineRegistry;
use Orion\Schema\IndexSchema;
use Orion\Tenancy\Tenancy;
use Orion\Wiring\Config;

$graph = Orion::create(new Config(
    storage: new PdoStorage($pdo),                              // your connection; Orion\Pdo\Installer::install($pdo) once
    engines: new EngineRegistry(memory: new InMemoryEngine()),  // or any engine adapter
    tenancy: Tenancy::single(),
))->graph();

$graph->planner('memory')->apply(IndexSchema::for('products')->text('title', language: 'en'));

$graph->outbox()->index(IndexName::of('products'), 'p1', ['title' => 'Trail running shoe']);
$graph->dispatcher()->dispatch(IndexName::of('products'));

$results = $graph->searcher()->search(Query::on('products')->match('title', 'shoe'));
```

- `Orion::create(new Orion\Wiring\Config(...))` builds the object graph without a container. `storage`, `engines`
  and `tenancy` are required, because each is a correctness or trust boundary. Everything else (clock, logger,
  events, cache, manifest directories, dispatcher, metrics, receipt signer and verifier) is optional, and either
  has a core default or, for receipts, is off until you pass a key.
- `$orion->graph()` hands out each service once: `planner()`, `reindexer()`, `outbox()`, `drain()`,
  `dispatcher()`, `deadLetters()`, `searcher()`, `receipts()`, `verifier()`, `manifest()`, `registry()`,
  `aliases()`.
- Query through `$graph->searcher()`, never an adapter: it binds the query to the registered schema, injects the
  tenant filter beneath it (the escape hatch included), and fuses hybrid queries in core.
- Record into `$graph->outbox()` inside the transaction that writes your entity, on the same connection as the
  configured storage, and dispatch after the commit.
- `Orion\Adapter\InMemory\InMemoryEngine` is for development and tests: indices live in the process, and it passes
  the same conformance suite as the real engines.
- `Orion\Tools\Tools::for($graph)` returns the agent tools (`compile_query`, `search`, `list_indices`,
  `describe_index`, `capabilities`, `plan`, and `apply` and `reindex`, which preview until confirmed), JSON in and
  out, for a host to expose over MCP.
- Core depends only on PSR interfaces (clock, events, log, simple-cache), `symfony/uid`, `symfony/yaml` and
  `ext-sodium`.

Start with the [Getting started guide](../../docs/guides/getting-started.md); every guide is listed in the
[guides overview](../../docs/guides/README.md). The design is [`docs/spec/`](../../docs/spec/orion-spec.md).
