# orion-search/core

The framework-free core of Orion: the neutral query and index-schema ASTs, the engine adapter
contracts (`SearchAdapter`, `SchemaAdapter`, `WriteAdapter`) and the `Capability` model, the index
lifecycle (planner, versioned indices and alias swap, zero-downtime reindex by outbox replay, signed
receipts), the tenancy seam, the `search:` manifest, the health checks and a dev `InMemory` engine.

Entry point: `Orion::create(new Orion\Wiring\Config(...))`. Every port in `Config` has a working
default; pass your own to replace it. Nothing here imports a framework, an ORM or an engine client.

The design is `docs/spec/` in the monorepo (`github.com/univeros/orion`).
