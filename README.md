# Content Repository Debugger (Neos.ContentRepository.Debug)

<!-- TOC -->
* [Content Repository Debugger (Neos.ContentRepository.Debug)](#content-repository-debugger-neoscontentrepositorydebug)
* [Interactive explorer (`cr:debug`)](#interactive-explorer-crdebug)
  * [Quick start](#quick-start)
  * [Resuming a session](#resuming-a-session)
  * [Built-in tools](#built-in-tools)
    * [Content Repository](#content-repository)
    * [Workspace & Dimensions](#workspace--dimensions)
    * [Nodes](#nodes)
    * [Events](#events)
    * [Other](#other)
    * [Session](#session)
* [Debug scripts (`cr:debugScript`)](#debug-scripts-crdebugscript)
  * [`$dbg` API quick reference](#dbg-api-quick-reference)
  * [`$tools` API — calling explore tools from a script](#tools-api--calling-explore-tools-from-a-script)
  * [Event copying examples](#event-copying-examples)
  * [Querying the event log](#querying-the-event-log)
  * [Arbitrary queries via `$dbg->db`](#arbitrary-queries-via-dbg-db)
* [Debug Views in the database](#debug-views-in-the-database)
* [Virtual indices for JetBrains Database Tools](#virtual-indices-for-jetbrains-database-tools)
* [Configuration](#configuration)
* [Creating custom explore tools](#creating-custom-explore-tools)
  * [Minimal example](#minimal-example)
  * [Parameter injection in `execute()`](#parameter-injection-in-execute)
  * [Writing output — `ToolIOInterface`](#writing-output--tooliointerface)
  * [Return values and context manipulation](#return-values-and-context-manipulation)
  * [`WithContextChangeInterface` — lifecycle hooks](#withcontextchangeinterface--lifecycle-hooks)
  * [`AutoRunToolInterface` — auto-execute on availability](#autoruntoolinterface--auto-execute-on-availability)
<!-- TOC -->

Tools to explore and debug the Neos Content Repository. Two complementary interfaces:

- **`cr:debug`** — interactive CLI explorer: navigate nodes, inspect events, manage shadow CRs, check subscription
  health.
- **`cr:debugScript`** — run PHP scripts with a pre-wired `ContentRepositoryDebugger` and `ScriptToolRunner` for batch
  analysis and tool automation.

> **WARNING: Development only.**
> Never run against a production database. Always work with a local copy.

---

# Interactive explorer (`cr:debug`)

## Quick start

```bash
./flow cr:debug
```

Opens an interactive session against the `default` Content Repository. A numbered menu lists all
available tools. **Availability is context-driven**: tools that require a workspace become available
once one is set; tools that require a node become available once you select one.

On startup the session automatically checks subscription health and shows a warning if any
projection is in an ERROR or non-ACTIVE state — run the `status` tool for the full stack trace.

## Resuming a session

Pass context flags to jump straight into a specific state:

```bash
./flow cr:debug --node=<uuid>
./flow cr:debug --node=<uuid> --workspace=live
./flow cr:debug --node=<uuid> --workspace=live --dsp='{"language":"en"}'
./flow cr:debug --cr=myCrId --node=<uuid>
```

At the bottom of the tool chooser, the exact command to restore the current
session is shown. Copy it to share with a colleague or bookmark a specific node.

## Built-in tools

Tools are grouped into four columns in the menu. Each tool is invoked by its short name.

### Content Repository

| Short name         | Tool                    | What it does                                                                       |
|--------------------|-------------------------|------------------------------------------------------------------------------------|
| `crId`             | ChooseContentRepository | Switch to a different (or dynamic/copy) CR; registers unknown CRs automatically    |
| `status`           | Status                  | Subscription health table, error details, and DB table sizes for the current CR    |
| `catchUp`          | CatchUp                 | Boot → catchUp → reactivate all subscriptions                                      |
| `resetProjections` | Reset                   | Truncate all projection tables and reset subscription positions (⚠ DEV only)       |
| `crCopy`           | CrCopy                  | Create an exact DB-level clone of the current CR (useful for safe experimentation) |

### Workspace & Dimensions

| Short name | Tool            | What it does                                                                                     |
|------------|-----------------|--------------------------------------------------------------------------------------------------|
| `wsId`     | ChooseWorkspace | List all workspaces in the current CR and pick one                                               |
| `dsp`      | ChooseDimension | Set the active dimension space point; shows covered DSPs for the current node if one is selected |

### Nodes

| Short name     | Tool           | What it does                                                                                      |
|----------------|----------------|---------------------------------------------------------------------------------------------------|
| `nId`          | SetNodeByUuid  | Enter a node UUID directly                                                                        |
| `n`            | NodeInfo       | Node identity, coverage, workspace presence, and URI path — **auto-runs** when a node is selected |
| `nProps`       | NodeProperties | All serialized properties as JSON (requires DSP)                                                  |
| `nRefs`        | NodeReferences | Outgoing and incoming references with navigation into referenced nodes                            |
| `cn`           | ChildNodes     | Direct children in the current subgraph; select one to navigate into it                           |
| `pn`           | GoToParentNode | Shows the full ancestor breadcrumb; navigate to any ancestor                                      |
| `nContentTree` | ContentTree    | Full content subtree under the current node                                                       |
| `nDocTree`     | DocumentTree   | Document subtree with URI paths; auto-detects site root if no node is selected                    |

### Events

| Short name                   | Tool                       | What it does                                                                                      |
|------------------------------|----------------------------|---------------------------------------------------------------------------------------------------|
| `nHist`                      | NodeHistory                | All events for the current node aggregate                                                         |
| `docHist`                    | PageHistory                | Combined events for the current document and all its content children                             |
| `seq`                        | EventContext               | Browse raw events around a given sequence number                                                  |
| `graveyardCatchUp`           | EventGraveyard             | Fault-tolerant catch-up: move failing events to a graveyard table (⚠ DEV only)                    |
| `compactEvents`              | CompactEvents              | Merge consecutive `NodePropertiesWereSet` duplicates within live streams (⚠ modifies event store) |
| `pruneRemovedContentStreams` | PruneRemovedContentStreams | Delete event history for content streams no longer referenced by any workspace (⚠ irreversible)   |

### Other

| Short name | Tool             | What it does                                                                |
|------------|------------------|-----------------------------------------------------------------------------|
| `path`     | FindNodeByPath   | Resolve a URL path to a node via the Neos routing projection (requires DSP) |
| `types`    | NodeTypeExplorer | Browse node types in use, list aggregates of a chosen type, navigate to one |
| `uriPath`  | NodeRouting      | Show the URI path for the current node                                      |

---

# Debug scripts (`cr:debugScript`)

For batch analysis and automation, write a PHP script and run it with:

```bash
./flow cr:debugScript MyScript.php
./flow cr:debugScript MyScript.php --contentRepository=myCrId
```

Inside the script three variables are pre-wired for you:

- `$dbg` — `Neos\ContentRepository\Debug\ContentRepositoryDebugger` — SQL-level event analysis and CR management
- `$cr` — `Neos\ContentRepository\Core\ContentRepository` — the CR passed via `--contentRepository`, or `default`
- `$tools` — `Neos\ContentRepository\Debug\Explore\Script\ScriptToolRunner` — call any interactive tool programmatically

Example script:

```php
<?php
/** @var $dbg   \Neos\ContentRepository\Debug\ContentRepositoryDebugger */
/** @var $cr    \Neos\ContentRepository\Core\ContentRepository */
/** @var $tools \Neos\ContentRepository\Debug\Explore\Script\ScriptToolRunner */

use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Debug\EventFilter\EventFilter;

// Copy production events to a shadow CR (skipping noisy property edits)
$debugCr = $dbg->setupCr('dbg');
$dbg->copyEvents(
    target: $debugCr,
    filter: EventFilter::create()->skipEventTypes(NodePropertiesWereSet::class)
);

// Use the explore tool layer to prune the shadow CR and check status
$tools = $tools->withContext('cr', 'dbg');
$tools->execute('pruneRemovedContentStreams', answers: ['yes']);
$tools->execute('status');

// SQL-level analysis of the result
$dbg->use($debugCr);
$dbg->printTable(
    $dbg->queryEvents()->groupByType()->count()->execute()
);
```

## `$dbg` API quick reference

- `setupCr(string $targetId, prune: false)` — create a new CR matching the production configuration. Pass `prune: true`
  to empty it on every run.
- `copyEvents($target, $filter = null, force: false)` — copy events from the current CR into `$target`, applying an
  optional `EventFilter`. Idempotent: skips if unchanged (override with `force: true`).
- `use($cr)` — switch the active CR for all subsequent calls.
- `queryEvents($cr = null)` — return an `EventLogQueryBuilder` for the given (or current) CR.
- `printTable($result, pivotBy: null)` — pretty-print a Doctrine DBAL result. Pass `pivotBy: 'column'` to rotate the
  table.

## `$tools` API — calling explore tools from a script

`$tools` is a `ScriptToolRunner` pre-configured with the CR from `--contentRepository`. It lets you
call any interactive tool programmatically, combining batch SQL analysis with the full tool layer.

```php
// Call a tool (no interactive prompts needed)
$tools->execute('status');

// Call a tool that asks questions — supply answers in order
$tools->execute('crCopy', answers: ['myCopy']);
$tools->execute('pruneRemovedContentStreams', answers: ['yes']);

// Switch context (returns new instance — does not mutate $tools)
$tools = $tools->withContext('cr', 'myCopy');
$tools = $tools->withContext('workspace', 'live');
$tools->execute('status');

// Fire bootstrap notifications (subscription warnings, dynamic CR registration, etc.)
$tools->bootstrap();
```

- `execute(string $shortName, array $answers = [])` — run a tool by its short name (e.g. `'status'`, `'crCopy'`).
  Answers are consumed in the order the tool asks for them. Throws `\RuntimeException` if the answer queue runs dry, or
  if the tool is unavailable (missing context).
- `withContext(string $name, string $value)` — return a new `ScriptToolRunner` with an additional context value set (
  same names as CLI flags: `cr`, `node`, `workspace`, `dsp`). Does not mutate the original.
- `bootstrap()` — fire `WithContextChangeInterface` bootstrap hooks (same as session start).

Tools that update context (e.g. `crId` after a `crCopy`) automatically propagate to subsequent `execute()` calls.

## Event copying examples

Copy everything (useful for replaying projections step by step):

```php
$debugCr = $dbg->setupCr('debug');
$dbg->copyEvents(target: $debugCr);
```

Copy while skipping noisy event types:

```php
use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Debug\EventFilter\EventFilter;

$debugCr = $dbg->setupCr('dbg');
$dbg->copyEvents(
    target: $debugCr,
    filter: EventFilter::create()->skipEventTypes(NodePropertiesWereSet::class)
);
```

Force a re-copy even when the source is unchanged:

```php
$dbg->copyEvents(target: $debugCr, force: true);
```

## Querying the event log

Chain filter, group-by, and aggregation methods on `$dbg->queryEvents()` and call `execute()`:

**Filtering (WHERE)**

- `whereRecordedAtBetween($from, $to)` — events in the given range (`YYYY-MM-DD` or `YYYY-MM-DD HH:MM:SS`).
- `whereStreamNotLike($pattern)` — exclude streams matching a SQL LIKE pattern (e.g. `'Workspace:%'`).
- `whereType(...$eventTypes)` — filter by type; accepts FQCNs or short names.

**Grouping (GROUP BY)**

- `groupByMonth()` / `groupByDay()` — group by recording time.
- `groupByType()` — group by event type.
- `groupByStream()` — group by stream name.

**Aggregations**

- `count()` — add `COUNT(*)`.
- `recordedAtMinMax()` — add `MIN`/`MAX`/diff for `recordedat`.
- `sequenceNumberMinMax()` — add `MIN`/`MAX`/diff for `sequencenumber`.

Examples:

```php
// Events per month
$dbg->printTable($dbg->queryEvents()->groupByMonth()->count()->execute());

// Events per type, most frequent first
$dbg->printTable($dbg->queryEvents()->groupByType()->count()->execute());

// Pivot: events per month × type
$dbg->printTable(
    $dbg->queryEvents()->groupByMonth()->groupByType()->count()->execute(),
    pivotBy: 'type'
);

// Target a specific CR without switching the global context
$dbg->printTable($dbg->queryEvents($debugCr)->groupByMonth()->count()->execute());
```

## Arbitrary queries via `$dbg->db`

For anything beyond `EventLogQueryBuilder`, run raw SQL via the Doctrine DBAL connection:

```php
$result = $dbg->db->executeQuery(
    'SELECT type, COUNT(*) AS cnt FROM cr_default_events GROUP BY type ORDER BY cnt DESC'
);
$dbg->printTable($result);
```

Use named parameters to avoid injection:

```php
$table = \Neos\ContentRepositoryRegistry\Factory\EventStore\DoctrineEventStoreFactory::databaseTableName($cr->id);
$result = $dbg->db->executeQuery(
    "SELECT * FROM {$table} WHERE recordedat >= :from",
    ['from' => '2024-01-01']
);
$dbg->printTable($result);
```

---

# Debug Views in the database

```bash
./flow cr:setupDebugViews
```

Creates two SQL views per CR for easier ad-hoc inspection:

- **`cr_{id}_dbg_allNodesInLive`** — all nodes in the live workspace, with DSP, parent node ID, and origin DSP
  pre-joined.
- **`cr_{id}_dbg_allDocumentNodesInLive`** — same as above but restricted to document nodes (those with a URI path),
  with `documenturipath` joined in and rows ordered by `sitenodename` / `uripath`.

---

# Virtual indices for JetBrains Database Tools

The Neos Content Repository intentionally omits database-level foreign key constraints. Importing
virtual indices into DataGrip, PHPStorm, or IntelliJ IDEA restores relationship awareness:

- Visual relationship diagrams between CR tables
- Jump from a foreign key value to the referenced row
- JOIN suggestions in the query editor

**How to use:**

1. Open the database connection in your JetBrains tool.
2. Right-click the database host → **Properties**.
3. Select **Options → Virtual objects and attributes**.
4. Import `virtual-indices-for-jetbrains-database-tools.xml` from this package.

**Database name:** The XML uses `neos` as the database name. Replace it before importing if yours differs:

```bash
sed -i '' 's/neos\./your_database_name./g' virtual-indices-for-jetbrains-database-tools.xml
```

---

# Configuration

The tool menu layout is configured in `Settings.yaml`. Each column is an ordered entry defining
which groups of tools appear in it:

```yaml
# Configuration/Settings.yaml (defaults)
Neos:
    ContentRepository:
        Debug:
            explore:
                menuColumns:
                    10:
                        groups: [ Workspace, Dimensions ]
                    20:
                        groups: [ Nodes ]
                    30:
                        groups: [ ContentRepository, Events ]
                    40:
                        groups: [ Other ]
```

Override in your own `Settings.yaml` to reorder columns or add custom groups.

---

# Creating custom explore tools

All tools shown in `./flow cr:debug` implement `ToolInterface`. The dispatcher discovers them
automatically via Flow's object framework — no manual registration needed.

## Minimal example

```php
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\Flow\Annotations as Flow;

#[ToolMeta(shortName: 'my-tool', group: 'Nodes')]
#[Flow\Scope('singleton')]
final class MyTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Do something useful';
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        $io->writeLine('Hello from MyTool!');
        return null;
    }
}
```

**`#[ToolMeta(shortName, group)]`** — declares the short name typed in the menu prompt and the
display group (`ContentRepository`, `Workspace`, `Dimensions`, `Nodes`, `Events`, `Other`, or any
custom group). When omitted, both are derived from the class name and namespace:

- `shortName`: class basename → strip `Tool` suffix → CamelCase to kebab-case
- `group`: sub-namespace after `Tool\` (e.g. `Tool\Nodes\FooTool` → `Nodes`)

**`#[Flow\Scope('singleton')]`** — required. Tools are Flow-managed singletons so that
`#[Flow\Inject]` works.

**`#[Flow\Inject]`** — use standard Flow property injection for service dependencies (DBAL,
registries, factories, etc.). Do not inject `ToolContext` or `ToolIOInterface` here; they are
provided per-invocation by the dispatcher.

## Parameter injection in `execute()`

The dispatcher resolves `execute()` parameters by type — you never call `execute()` directly.

| Parameter type                | How it is resolved                                         | Availability effect                        |
|-------------------------------|------------------------------------------------------------|--------------------------------------------|
| `ToolIOInterface`             | Always injected                                            | None                                       |
| `ToolContext`                 | Always injected — the full context bag                     | None                                       |
| **Registered context types**  |                                                            |                                            |
| `ContentRepositoryId`         | From context bag (`cr`)                                    | Required → unavailable when absent         |
| `NodeAggregateId`             | From context bag (`node`)                                  | Required → unavailable when absent         |
| `WorkspaceName`               | From context bag (`workspace`)                             | Required → unavailable when absent         |
| `DimensionSpacePoint`         | From context bag (`dsp`)                                   | Required → unavailable when absent         |
| **Derived types**             |                                                            |                                            |
| `ContentRepository`           | Resolved from `ContentRepositoryId`                        | Required → unavailable if resolution fails |
| `ContentGraphInterface`       | Resolved from CR + `WorkspaceName`                         | Required → unavailable when either absent  |
| `ContentSubgraphInterface`    | Resolved from CR + `WorkspaceName` + `DimensionSpacePoint` | Required → unavailable when any absent     |
| `EventStoreInterface`         | Resolved from `ContentRepositoryId`                        | Required → unavailable if resolution fails |
| `ContentRepositoryMaintainer` | Resolved from `ContentRepositoryId`                        | Required → unavailable if resolution fails |

Make a parameter optional (`?Type $x = null`) to keep the tool available even when the value is absent.

```php
// Always available (no context params)
public function execute(ToolIOInterface $io): ?ToolContext

// Available when CR is set
public function execute(ToolIOInterface $io, ContentRepositoryId $cr): ?ToolContext

// Available when CR + workspace are set (ContentGraphInterface derived from both)
public function execute(ToolIOInterface $io, ContentGraphInterface $cg, NodeAggregateId $node): ?ToolContext

// DSP optional — available with or without it
public function execute(ToolIOInterface $io, NodeAggregateId $node, ?DimensionSpacePoint $dsp = null): ?ToolContext
```

## Writing output — `ToolIOInterface`

| Method                                                                  | Description                                                                      |
|-------------------------------------------------------------------------|----------------------------------------------------------------------------------|
| `writeLine(string $text = '')`                                          | Write a plain text line (or blank line)                                          |
| `writeError(string $message)`                                           | Write a red error message                                                        |
| `writeNote(string $message)`                                            | Write a yellow note / warning                                                    |
| `writeInfo(string $message)`                                            | Write a green informational message                                              |
| `writeTable(array $headers, array $rows)`                               | Render a table                                                                   |
| `writeKeyValue(array $pairs)`                                           | Render a key → value list                                                        |
| `ask(string $question, ?callable $autocomplete = null)`                 | Free-text prompt with optional live autocomplete                                 |
| `confirm(string $question, bool $default = false)`                      | Yes/no confirmation prompt                                                       |
| `chooseMultiple(string $question, array $choices, array $default = [])` | Multi-select from a keyed list; returns selected keys                            |
| `chooseFromTable(string $question, array $headers, array $rows)`        | Combined table + row selection; returns the selected row key                     |
| `progress(string $label, int $total, \Closure $callback)`               | Progress bar; `$callback` receives `$advance` callable                           |
| `task(string $label, \Closure $callback)`                               | Spinner + scrolling live log; `$callback` receives `$log(string $line)` callable |

## Return values and context manipulation

| Return value                                            | Effect                                                                |
|---------------------------------------------------------|-----------------------------------------------------------------------|
| `null`                                                  | Context unchanged — menu re-renders with the same state               |
| `$context->with(string $name, object $value)`           | Store a typed value in the context bag by its registered name         |
| `$context->withFromString(string $name, string $value)` | Deserialise via the registry and store (use for user-entered strings) |
| `$context->without(string $name)`                       | Remove a value from the context                                       |
| `ExploreSession::exit()`                                | End the interactive session                                           |

Use `$context->get('node')` / `$context->has('workspace')` to inspect current context in
`getMenuLabel()`. Never depend on `ToolContextRegistry` directly from a tool; use
`$context->withFromString()` instead.

## `WithContextChangeInterface` — lifecycle hooks

Implement `WithContextChangeInterface` to react whenever the session context changes, and once on
bootstrap (before the first menu).

```php
use Neos\ContentRepository\Debug\Explore\Tool\WithContextChangeInterface;

#[ToolMeta(shortName: 'my-tool', group: 'ContentRepository')]
#[Flow\Scope('singleton')]
final class MyTool implements ToolInterface, WithContextChangeInterface
{
    public function onContextChange(
        ToolContext $oldContext,
        ToolContext $newContext,
        ToolIOInterface $io,
        ContentRepositoryId $cr,          // resolved from $newContext
    ): void {
        // called once on bootstrap (oldContext is empty) and after every context change
    }

    public function execute(ToolIOInterface $io, ContentRepositoryId $cr): ?ToolContext { ... }
}
```

**Parameter injection** follows the same rules as `execute()`:

- First `ToolContext` param → `$oldContext` (empty on bootstrap: `ToolContext::empty()`)
- Second `ToolContext` param → `$newContext`
- `ToolIOInterface` → injected
- Registered and derived types → resolved from `$newContext`

If a required parameter cannot be resolved, the callback is **silently skipped**.

**Two-pass ordering** — the dispatcher calls all `onContextChange` handlers in two passes:

- **Pass 1** — tools whose `onContextChange` has **no derived-type parameters** (only `ToolContext`,
  `ToolIOInterface`, and registered types like `ContentRepositoryId`). These run first.
- **Pass 2** — tools whose `onContextChange` needs **at least one derived type** (e.g.
  `ContentRepositoryMaintainer`). These run after pass 1.

This guarantees setup work (e.g. registering a dynamic CR) completes before pass-2 tools build
services that depend on it.

**Example use-cases:**

- Show a subscription health warning when entering a CR (pass 2 — needs `ContentRepositoryMaintainer`).
- Auto-register a dynamic/copy CR when its ID is not yet in Flow settings (pass 1 — only needs `ContentRepositoryId`).
- Suggest a maintenance action when the user switches to a CR with pending cleanup.

## `AutoRunToolInterface` — auto-execute on availability

Implement `AutoRunToolInterface` (extends `ToolInterface`) to have a tool execute automatically
the moment it becomes newly available in the menu — without the user selecting it.

```php
use Neos\ContentRepository\Debug\Explore\Tool\AutoRunToolInterface;

#[ToolMeta(shortName: 'n', group: 'Nodes')]
#[Flow\Scope('singleton')]
final class NodeInfoTool implements AutoRunToolInterface
{
    public function execute(ToolIOInterface $io, NodeAggregateId $node, ...): ?ToolContext { ... }
}
```

`execute()` is called automatically when the tool transitions from unavailable → available. The
return value is ignored — `execute()` must be read-only. The built-in `NodeInfoTool` uses this to
display node details automatically whenever a node is selected.

**Contrast with `WithContextChangeInterface`:**

|                      | `WithContextChangeInterface`           | `AutoRunToolInterface`               |
|----------------------|----------------------------------------|--------------------------------------|
| Trigger              | Every context change + bootstrap       | Transitions to available in the menu |
| Receives old context | Yes                                    | No                                   |
| Can change context   | No (return value ignored)              | No (return value ignored)            |
| Typical use          | Proactive warnings, registration, tips | Auto-displaying info on selection    |
