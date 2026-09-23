# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# How we work here

These rules govern *how* to work in this repo. They take precedence over the reflex to answer fast.

**What belongs in this file:** durable rules and non-obvious facts that survive the next commit.
**What does not:** snapshots of mutable state — whether a commit exists, whether a directory has
been created yet, what today's branch is. Anything a shell command answers in one second does not
belong here, because a stale note reads as verified truth and is worse than no note. State each
fact once, in the section it topically belongs to; never in two places.

## Rule 1 — Read the real source before saying anything

Before offering an opinion, an option, a recommendation, or a decision that will materialize in
**Symfony, Sylius, Doctrine, the UPS or FedEx APIs, or any library in
`composer.json`/`package.json`** — consult the real source **for the versions this package actually
resolves**, *before* writing the options down.

This is not conditional on someone naming a library. It applies even when no library is mentioned:
in a shipping carrier plugin practically every decision lands on one of them.

### Which source, in which order

Read in this order and stop at the first one that settles the question:

1. **`vendor/` source and config** — the *exact* pinned code that runs here. It is local, free to
   read, and outranks every other source, so it goes first. Grep the bundle's
   `Resources/config/*` and `src/`.
2. **`vendor/bin/console debug:*` / `config:dump-reference`** — what the container actually
   resolves, when the question is about wiring rather than code.
3. **`context7`** — for guides, migration notes, and *intended* usage that source alone does not
   reveal. It comes after the code, never before it: context7 indexes the upstream repo, while
   `vendor/` is what executes. When the two disagree, `vendor/` wins.
4. **Official docs, version pinned in the URL** — <https://docs.sylius.com>,
   <https://symfony.com/doc/7.4/>, <https://www.doctrine-project.org/>,
   <https://developer.ups.com/>, <https://developer.fedex.com/>.

After consulting:
- **If what the source returns changes the options** → reformulate the question/answer and state
  explicitly *what changed and why*.
- **If it does not change them** → say that too ("checked `vendor/sylius/sylius` at the resolved
  version, the options stand"). Name the version you actually read from `composer.lock` — derive
  it, never copy it from this file.

Silence about having checked is not acceptable. The reader must be able to tell the difference
between a verified answer and an unverified one.

### Versions to query against

Never query "latest", and never trust a version written down in this file. Only two things here are
stable enough to record: the declared constraints, `php ^8.2` and `sylius/sylius ^2.2.6`.

Everything else is derived, never quoted. Print the resolved versions:

```bash
php -r '$j=json_decode(file_get_contents("composer.lock"),true);
foreach (array_merge($j["packages"], $j["packages-dev"]) as $p)
    printf("%-45s %s\n", $p["name"], $p["version"]);' | sort
```

Two reasons a hardcoded table would be actively harmful here:

- **`composer.lock` is gitignored** (correct for a library), so a fresh clone has none. Without a
  lock, `composer install` behaves like `composer update` and resolves *different* versions than
  whoever wrote the table got. Drift is not a risk, it is the guaranteed outcome.
- Per the policy at the top of this file, a stale version note reads as verified truth. One command
  answers it in under a second, so it does not belong here.

That prints every resolved package, which is a few hundred lines. Pipe it through `grep` for the
one you need:

```bash
… | grep -E 'sylius/sylius|symfony/framework-bundle|doctrine/orm'
```

If `composer.lock` is absent, run `composer install` first — then derive. Do not guess.

### How `context7` reaches this session

context7 is an MCP server. It may arrive as a marketplace **plugin** (tools appear as
`mcp__plugin_context7_context7__*`) or as a project-scoped server in a `.mcp.json` (tools appear as
`mcp__context7__*`). This repository declares neither — it relies on whatever the developer has
configured.

**If context7 tools are not visible in the current session, say so and rely on steps 1, 2 and 4
above.** Since `vendor/` already outranks it, losing context7 degrades the answer but never blocks
it. Never present an unverified answer as a checked one.

> **Unverified:** how precisely context7 can be pinned to a version is not established here. It
> resolves by library ID, and version-specific coverage depends on what is indexed upstream, so
> "query v2.2.8" may silently return docs for another version. Until someone confirms the
> behaviour, treat any context7 result that contradicts `vendor/` as wrong, and do not cite a
> context7 answer as version-verified.

## Rule 2 — Assume nothing

If something is not defined, **ask before supposing**. If the corpus (docs, `vendor/` source,
config, this file) does not settle a point, **mark it as unsettled** — do not force a plausible
answer into the gap. "The docs don't specify this; here are the two readings" is a valid, preferred
answer.

## Rule 3 — Spec-Driven Development with OpenSpec

The spec is the source of truth. Code serves the spec, not the other way around. Specs are managed
with [OpenSpec](https://github.com/Fission-AI/OpenSpec) through its `/opsx:*` commands.

### When SDD applies
- New features, new endpoints, integrations, schema changes, or any change touching 3+ files.
- Does NOT apply to: typos, one-line bug fixes, dependency bumps, formatting. Do those directly.
- When in doubt, ask: "Does this need a spec?"

### Workflow (strict order, one approval gate before code)
1. **Explore** *(optional)* — `/opsx:explore` to think the problem through before anything is
   written. Open technical decisions are raised here as questions, one per message.
2. **Propose** — `/opsx:propose <NNN>-<slug>` creates `openspec/changes/<NNN>-<slug>/` and writes,
   in one step: `proposal.md` (WHAT and WHY: problem, users, scope, out of scope),
   `specs/<capability>/spec.md` (the behaviour the system must have, as requirements with
   scenarios), `design.md` (HOW: architecture, data model, contracts, edge cases, testing strategy)
   and `tasks.md` (a numbered checklist of small, independently verifiable tasks, ordered by
   dependency). Then run `openspec validate <NNN>-<slug> --strict`: `/opsx:propose` does not
   validate on its own.
3. **Review** — STOP. Do not proceed until the four artifacts are explicitly approved. This is the
   only gate before code, so spec, design and tasks are reviewed together while a wrong spec or a
   wrong decomposition is still cheap to fix. Corrections go through `/opsx:update <NNN>-<slug>`.
4. **Implement** — `/opsx:apply <NNN>-<slug>`. Execute one task at a time. After each task: run
   tests, check the box in `tasks.md`, and reference the task in the commit message (format: Rule 4).
5. **Verify** — `/opsx:verify <NNN>-<slug>` before declaring anything done: it walks every requirement
   and scenario of the change (or `## Criterios de aceptación` in `proposal.md` when the change
   declares `skip_specs: true`) against what was built and reports what does not hold. Installed on
   2026-09-21; before that the walk was done by hand, which is why the older changes carry their
   own criterion→test table.
6. **Archive** — `/opsx:archive <NNN>-<slug>` once every task is checked and step 5 passed. It merges
   the change's specs into `openspec/specs/` and moves the change to
   `openspec/changes/archive/YYYY-MM-DD-<NNN>-<slug>/`.

### Rules
- Never write implementation code during Explore, Propose or Review.
- If implementation reveals the spec is wrong or incomplete: stop, propose the amendment with
  `/opsx:update`, get approval, then continue. Never diverge silently.
- If a change is requested mid-implementation: update the change's artifacts first, then the code.
- Ambiguous requirements = ask before proposing. Do not invent.
- A change with no behaviour of its own (documentation, tooling) declares `skip_specs: true` in its
  `.openspec.yaml` right after the change is created and before any artifact is written.
  `/opsx:propose` never decides that by itself.
- Artifacts are written in Spanish. OpenSpec's structural headings (`## Purpose`,
  `### Requirement:`, `#### Scenario:`, **WHEN**/**THEN**) and the SHALL/MUST keywords stay in
  English: `openspec validate --strict` fails on a requirement without SHALL or MUST.

### OpenSpec artifacts are deliberately not published

> **`openspec/` is gitignored.** Changes, specs and research exist only in the author's working
> copy; they are not part of the distributed package and no clone can see them.
>
> Two consequences, stated so nobody is surprised:
> - The `Refs:` footer in Rule 4 is a **local navigation aid only**. It resolves on the author's
>   machine and nowhere else.
> - The artifacts are not backed up by the repository. Losing the working copy loses them.
>
> Do not "fix" this by committing `openspec/`. It is a decision, not an oversight.

### Spec quality bar
- Requirements describe observable behaviour; implementation detail belongs in `design.md`.
- Every requirement must be verifiable, and each scenario is a potential test ("returns 404 when X",
  not "handles errors properly").
- One change = one feature. Split anything bigger.
- A requirement may carry `**ID**: <NNN>/CA-N` below its name. Inside a change's `tasks.md`,
  «Cubre: CA-N» refers to that ID in the same change.

### Interaction between SDD and Rule 1
Rule 1 fires hardest when `design.md` is written or updated: every architectural option in it must
be backed by pinned-version docs or `vendor/` source, and `design.md` must record what was checked.
A `design.md` containing an unverified claim about how Sylius, UPS or FedEx behaves is a defective
design.

## Rule 4 — Conventional Commits

Every commit message follows [Conventional Commits v1.0.0](https://www.conventionalcommits.org/en/v1.0.0/):

```
<type>[optional scope][!]: <description>

[optional body]

[optional footer(s)]
```

**Types.** Only `feat` and `fix` are mandated by the spec. The rest is the conventional
(Angular-derived) set we also use here — stick to this list, do not invent new ones:

| Type | Use for |
|---|---|
| `feat` | New behaviour visible to a shop or admin user, or a new public API affordance |
| `fix` | Bug fix |
| `docs` | Documentation only, including this file and the README |
| `refactor` | Restructuring with no behaviour change |
| `perf` | Performance work |
| `test` | PHPUnit or Behat only |
| `build` | Composer/npm dependencies, webpack, Docker |
| `ci` | CI workflow configuration |
| `chore` | Repo scaffolding and tooling that fits nothing above |
| `style` | Formatting only — never mixed with other types |
| `revert` | Reverting a previous commit |

Note `docs`, not `doc`.

### SemVer is binding here

> This is a **published package**. Consumers install it with a caret constraint, so the commit type
> is a promise about the next release number:
>
> | Commit | Release |
> |---|---|
> | `fix` | PATCH |
> | `feat` | MINOR |
> | `!` or a `BREAKING CHANGE:` footer | MAJOR |
>
> A breaking change needs `!` after the type/scope **and** a `BREAKING CHANGE:` footer explaining the
> migration. In this package that means: a change to a public interface, service id or DI tag another
> plugin could rely on; an entity or schema change requiring a non-backward-compatible migration; or
> a change to the shipping method / carrier configuration keys already stored in existing
> installations.
>
> **The table above assumes a `1.0.0` or later release line.** Under SemVer 2.0.0 §4, `0.y.z` makes
> no compatibility promise at all: there, a breaking change goes in MINOR and `MAJOR` stays at zero,
> so `feat` → MINOR / `!` → MAJOR does not hold. `composer.json` sets
> `extra.branch-alias.dev-main` to `1.0-dev`, so the first line is `1.x` and the table applies
> from the first tag. If a `0.x` pre-release ever ships ahead of that, say so explicitly in the
> release notes and do not pretend the mapping is binding for it.
>
> From `1.0.0` onward this obligation never relaxes.

### Task references

A commit that implements a task of a change ends with the footer `Refs: <change>#T-NN`, for example
`Refs: 001-carrier-rates-and-tracking#T-05`. It names the change and the task, not a path: the path
changes when the change is archived. Commits made before the move to OpenSpec use
`Refs: specs/<change>/tasks.md#T-NN`; read them as the same change and task.

## Development Commands

> **There is no `Makefile` in this repository.** Any `make <target>` instruction you find in a
> guide, a README or an older revision of this file does not work here. Use the commands below,
> or add a `Makefile` first.

### Composer scripts

`composer run-script --list` prints each with its description, from `scripts-descriptions`.

```bash
composer check                 # ecs, phpstan, lint-container, phpunit, behat — stops at the first failure
composer run database-reset    # drop + create + migrate + load fixtures
composer run frontend-clear    # yarn install && yarn build in the test app, then assets:install
composer run test-app-init     # database-reset + frontend-clear
```

### Traditional development

```bash
# Frontend setup
(cd vendor/sylius/test-application && yarn install && yarn build)
vendor/bin/console assets:install

# Database setup
vendor/bin/console doctrine:database:create
vendor/bin/console doctrine:migrations:migrate -n
vendor/bin/console sylius:fixtures:load -n

# Start server
symfony server:start -d
```

Note the console lives at `vendor/bin/console` (provided by `sylius/test-application`), not
`bin/console`. The only file in `bin/` is `show-success.php`.

### Docker

`compose.yml` and `compose.override.dist.yml` exist, but there is no wrapper. Drive Compose
directly:

```bash
cp compose.override.dist.yml compose.override.yml   # gitignored; edit APP_SECRET before use
docker compose up -d
docker compose exec php sh
docker compose down
```

`compose.yml` alone is the bare CI stack (php, mysql, nginx, mailhog) — no volumes, ports or app
environment. The override file is what makes it usable locally: it mounts the working copy, adds
the `nodejs` service, publishes nginx on `:80` and mailhog on `:8025`, and points nginx at
`vendor/sylius/test-application`.

### Testing and code quality

Every tool is configured at the repo root. Paths and levels live in those files, so the commands
take no flags:

```bash
vendor/bin/phpunit                  # phpunit.xml.dist  → tests/{Unit,Integration,Functional}
vendor/bin/behat --strict           # behat.yml.dist    → suites from tests/Behat/Resources/suites.yml
vendor/bin/phpstan analyse          # phpstan.neon      → level max over src/ and tests/
vendor/bin/ecs check                # ecs.php           → add --fix to apply
vendor/bin/rector process --dry-run # rector.php        → drop --dry-run to apply
```

Non-obvious wiring, none of which is discoverable from the commands themselves:

- **`tests/bootstrap.php`** is PHPUnit's bootstrap. It loads the Composer autoloader, forces
  `APP_ENV=test`, then delegates to `vendor/sylius/test-application/config/bootstrap.php`, which
  boots Dotenv from the test application *and* from this plugin's `tests/TestApplication/.env`.
  Setting `APP_ENV` before that delegation is what makes `.env.test` win.
- **`KERNEL_CLASS` is `Sylius\TestApplication\Kernel`**, set in `phpunit.xml.dist` and again under
  `FriendsOfBehat\SymfonyExtension` in `behat.yml.dist`. The kernel is the test application's, not
  one of ours.
- **Behat suites are not defined in `behat.yml.dist`.** It imports
  `tests/Behat/Resources/suites.yml`, which imports one file per capability from
  `tests/Behat/Resources/suites/`. Each suite runs the features of `features/<capability>/` tagged
  with it. Contexts and pages are wired as services in `tests/Behat/Resources/services.xml`, which the
  kernel picks up through `tests/TestApplication/config/services_test.php`.
- **In the `test` environment UPS and FedEx are fakes, for PHPUnit too.**
  `tests/Behat/Resources/services.xml` replaces `jpmmartin_carrier.carrier.ups` and
  `jpmmartin_carrier.carrier.fedex` with `Tests\...\Behat\Carrier\FakeCarrier`, whose answer and call
  count live in `%kernel.cache_dir%/jpmmartin_carrier_fake_carriers.json`. A file, because
  `FriendsOfBehat\SymfonyExtension` sets up contexts in one kernel and serves requests from another,
  so nothing kept in memory reaches the carrier the shop calls. No test calls a real carrier.
- **The rate cache is on the filesystem in `test` as well.** It outlives the database and earlier
  runs, so a test that counts carrier calls or expects a new rate must clear
  `jpmmartin_carrier.cache.rates` first. The Behat carrier hook does it before every scenario.
- **Behat and PHPUnit share the test database.** Sylius purges it before each scenario and the
  plugin's database hook purges it after, because the PHPUnit tests expect it empty. A Behat run cut
  short can leave rows behind that make PHPUnit fail on unique constraints; running any scenario to
  the end cleans them.
- **`etc/build/`** must exist — `FriendsOfBehat\MinkDebugExtension` writes screenshots and logs
  there. It is kept by `etc/build/.gitignore`.
- **`friends-of-behat/page-object-extension` is a class library, not a Behat extension.** It ships
  no `ServiceContainer`, so it must not be listed under `extensions:`. Its `SymfonyPage` is simply
  extended by the page objects.

Behat JS scenarios additionally need headless Chrome on port 9222 and a running server:

```bash
APP_ENV=test symfony server:start --port=8080 --daemon
vendor/bin/behat --strict --tags="@javascript"
```

### Known gaps in the inherited scaffolding

Not bugs in the configuration above — real gaps, recorded so nobody rediscovers them:

- `rector/rector` 1.2.10 has no Symfony set past `SYMFONY_71` while the stack resolves Symfony
  7.4, and `sylius/sylius-rector` 2.0 ships **no** Sylius 2.x upgrade set — only `plus/` and
  `price-history/`. Do not add a `SyliusSetList` entry for 2.x; it does not exist.
- `phpstan/phpstan` is pinned to `^1.12` and prints an upgrade notice on every run. Moving to `^2.2`
  is a `build:` change with its own error-surface, not a bump to do in passing.

## Architecture

`jpmmartin/sylius-shipping-carriers-plugin` — UPS and FedEx shipping for Sylius: real-time rates,
labels, tracking and customs. It began as the Sylius plugin skeleton, so skeleton scaffolding is
still present alongside the plugin's own code.

### Core structure
- **Main plugin class**: `src/JpmMartinSyliusShippingCarriersPlugin.php` — entry point using
  `SyliusPluginTrait`
- **DI extension**: `src/DependencyInjection/JpmMartinSyliusShippingCarriersExtension.php`, with
  `src/DependencyInjection/Configuration.php`
- **Namespaces**: `JpmMartin\SyliusShippingCarriersPlugin\` → `src/`;
  `Tests\JpmMartin\SyliusShippingCarriersPlugin\` → `tests/` and `tests/TestApplication/src/`
- **Services**: `config/services.xml`, which imports every file under `config/services/`
- **Config**: `config/config.yaml`, `config/routes/`, `config/twig_hooks/`, and validation mappings
  in `config/validation/`
- **Migrations**: `src/Migrations/`
- **Templates**: `templates/`

### Key facts
- **Test application**: `sylius/test-application` hosts the plugin in isolation; it also supplies
  `vendor/bin/console` and the public dir (`extra.public-dir`)
- **Asset management**: Webpack Encore, driven through the test application's `yarn build`
- **Symfony version pin**: `extra.symfony.require` is `^7.4`
- **Tests**: PHPUnit in `tests/{Unit,Integration,Functional}`; Behat features in
  `features/<capability>/`, one folder per capability of the OpenSpec change, with the plugin's
  contexts in `tests/Behat/Context/`. What an administrator or a buyer sees is tested with both;
  pieces nobody sees are tested only with PHPUnit, as in Sylius's official plugins
- **Tooling**: PHPStan, ECS, Rector, PHPUnit and Behat are installed and configured at the repo
  root (`phpstan.neon`, `ecs.php`, `rector.php`, `phpunit.xml.dist`, `behat.yml.dist`)

### Publication

- **The public surface is a list, and the list is tested.** `docs/extending.md` names every seam a
  store may rely on: hooks, template paths, routes, the interfaces with their service ids, the carrier
  tags, the value objects and exceptions that cross them, the stored configuration values and the
  resources. Every class under `src/` is either named there or carries `@internal`, and
  `tests/Unit/Docs/ExtendingSurfaceTest` fails when either half drifts. A new class starts internal;
  making it public is a decision taken in the document, not by leaving the tag off. SemVer is promised
  about the list and nothing else.
- **The README's install section is executed.** The *Install* workflow runs, in a Sylius Standard
  store, the ` ```bash ` commands `bin/extract-readme-commands` finds between `## Installation` and
  `## Setting up the store`, and `bin/apply-readme-edits` mirrors the `php`, `yaml` and `gitignore`
  blocks of that section. A command belongs in a `bash` fence and nothing else does; change the shape
  of one of the mirrored blocks and that script must change with it.
- **How a release is cut, and what counts as breaking in this package, is in `RELEASING.md`.** The
  consumer's side is `docs/upgrading.md`.

### Database Configuration
Database credentials should be configured in:
- `tests/TestApplication/.env` (for development)
- `tests/TestApplication/.env.test` (for testing)

## AI Development Guides

This project includes specialized AI guides to assist with common plugin development tasks:

- **CLEANUP_GUIDE.md** - Guidelines for cleaning up and organizing plugin code
- **RENAME_GUIDE.md** - Step-by-step instructions for renaming plugins and components
- **COMPATIBILITY_GUIDE.md** - Best practices for maintaining compatibility across different Sylius versions

These guides provide detailed instructions and automated workflows to help maintain code quality and ensure proper plugin structure.
