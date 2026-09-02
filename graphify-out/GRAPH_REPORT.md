# Graph Report - SyliusShippingCarriersPlugin  (2026-09-02)

## Corpus Check
- Corpus is ~7,595 words - fits in a single context window. You may not need a graph.

## Summary
- 298 nodes · 428 edges · 21 communities (12 shown, 4 thin omitted)
- Extraction: 82% EXTRACTED · 18% INFERRED · 1% AMBIGUOUS · INFERRED: 75 edges (avg confidence: 0.86)
- Token cost: 110,448 input · 0 output

## Community Hubs (Navigation)
- Composer Package Manifest
- Shipping Origin Entity
- Plugin Architecture Guides
- Dev and Test Dependencies
- Development Environment Runbook
- Working Rules and SDD Process
- Dependency Injection Wiring
- Shipping Origin Contract
- Behat and Test Scaffolding
- Composer Automation Scripts
- Doctrine Migration
- Rector Configuration
- Plugin Bundle Entry Point
- Graphify Skill Policy
- Coding Standard Configuration
- Test Services Wiring

## God Nodes (most connected - your core abstractions)
1. `require-dev` - 30 edges
2. `CarrierShippingOrigin` - 27 edges
3. `CarrierShippingOriginInterface` - 23 edges
4. `Docker Compose Workflow (No Wrapper)` - 11 edges
5. `Testing and Code Quality Toolchain` - 11 edges
6. `sylius/test-application Host` - 11 edges
7. `JpmMartinSyliusShippingCarriersExtension` - 10 edges
8. `CarrierShippingOriginTest` - 10 edges
9. `Plugin Architecture Overview` - 10 edges
10. `Removing Customizations from a Sylius Plugin Skeleton` - 10 edges

## Surprising Connections (you probably didn't know these)
- `Multi-Version Composer Constraints` --semantically_similar_to--> `Declared Constraints: php ^8.2 and sylius/sylius ^2.2`  [INFERRED] [semantically similar]
  COMPATIBILITY_GUIDE.md → CLAUDE.md
- `Rename Verification Steps` --semantically_similar_to--> `SDD Phase 5: Verify`  [INFERRED] [semantically similar]
  RENAME_GUIDE.md → CLAUDE.md
- `Step 5: Post-Cleanup Verification` --semantically_similar_to--> `Testing and Code Quality Toolchain`  [INFERRED] [semantically similar]
  CLEANUP_GUIDE.md → CLAUDE.md
- `Testing Across Sylius Versions` --semantically_similar_to--> `Testing and Code Quality Toolchain`  [INFERRED] [semantically similar]
  COMPATIBILITY_GUIDE.md → CLAUDE.md
- `Plugin Skeleton README` --semantically_similar_to--> `Plugin Rename Guide`  [INFERRED] [semantically similar]
  README.md → RENAME_GUIDE.md

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Spec-Driven Development Approval-Gate Workflow** — claude_rule_3_sdd, claude_sdd_specify_phase, claude_sdd_plan_phase, claude_sdd_tasks_phase, claude_sdd_implement_phase, claude_sdd_verify_phase, claude_specs_gitignored [EXTRACTED 1.00]
- **Test Application to Plugin Configuration Import Chain** — tests_testapplication_config_config_plugin_import, tests_testapplication_config_routes_admin_import, tests_testapplication_config_routes_shop_import, config_config_twig_hooks_import, config_twig_hooks_shop_placeholder, config_routes_admin_placeholder, config_routes_shop_placeholder [EXTRACTED 1.00]
- **Local Docker Stack (Bare CI Stack Plus Override)** — claude_docker_compose_workflow, compose_php_service, compose_mysql_service, compose_nginx_service, compose_mailhog_service, compose_override_dist_php_service, compose_override_dist_nodejs_service, compose_override_dist_nginx_working_dir, compose_override_dist_volumes [EXTRACTED 1.00]

## Communities (21 total, 4 thin omitted)

### Community 0 - "Composer Package Manifest"
Cohesion: 0.05
Nodes (40): dealerdirect/phpcodesniffer-composer-installer, php-http/discovery, phpstan/extension-installer, symfony/flex, symfony/runtime, autoload, autoload-dev, psr-4 (+32 more)

### Community 1 - "Shipping Origin Entity"
Cohesion: 0.07
Nodes (10): Doctrine\DBAL\Exception\UniqueConstraintViolationException, Doctrine\ORM\EntityManagerInterface, EntityManagerInterface, CarrierShippingOrigin, Sylius\Component\Core\Model\Channel, Sylius\Component\Core\Model\ChannelInterface, Sylius\Component\Currency\Model\Currency, Sylius\Component\Locale\Model\Locale (+2 more)

### Community 2 - "Plugin Architecture Guides"
Cohesion: 0.11
Nodes (32): AI Development Guides Index, Plugin Architecture Overview, JpmMartinSyliusShippingCarriersExtension and Configuration, JpmMartinSyliusShippingCarriersPlugin (Main Plugin Class), PSR-4 Namespace Mapping, No config/twig_hooks/admin.yaml, Step 4: Cleanup Checklist, Step 3: Configuration to Clean (+24 more)

### Community 3 - "Dev and Test Dependencies"
Cohesion: 0.07
Nodes (30): require-dev, behat/behat, dbrekelmans/bdi, dmore/behat-chrome-extension, dmore/chrome-mink-driver, friends-of-behat/mink, friends-of-behat/mink-browserkit-driver, friends-of-behat/mink-debug-extension (+22 more)

### Community 4 - "Development Environment Runbook"
Cohesion: 0.14
Nodes (29): Composer Scripts (database-reset, frontend-clear, test-app-init), Console Lives at vendor/bin/console, Database Credentials Live in the Test Application .env Files, Docker Compose Workflow (No Wrapper), KERNEL_CLASS Is the Test Application Kernel, No Makefile in This Repository, sylius/test-application Host, tests/bootstrap.php Environment Bootstrap (+21 more)

### Community 5 - "Working Rules and SDD Process"
Cohesion: 0.13
Nodes (27): AGENTS.md Delegation to CLAUDE.md, Conventional Commit Type Vocabulary, composer.lock Is Gitignored, context7 MCP Server, Declared Constraints: php ^8.2 and sylius/sylius ^2.2, Gap: PHPStan Pinned to ^1.12, Gap: 4 PHPStan Level-Max Errors in Skeleton Code, Gap: No Sylius 2.x Rector Upgrade Set Exists (+19 more)

### Community 6 - "Dependency Injection Wiring"
Cohesion: 0.12
Nodes (15): Configuration, JpmMartinSyliusShippingCarriersExtension, Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait, Sylius\Bundle\ResourceBundle\Controller\ResourceController, Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension, Sylius\Bundle\ResourceBundle\SyliusResourceBundle, Sylius\Resource\Factory\Factory, Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition (+7 more)

### Community 8 - "Behat and Test Scaffolding"
Cohesion: 0.19
Nodes (15): Behat Suite and Context Wiring, etc/build Must Exist, Gap: Feature File Belonging to No Behat Suite, page-object-extension Is a Class Library, Not a Behat Extension, Testing and Code Quality Toolchain, Test Scaffolding Is Inherited Demo Material, Greeting Feature Removal Pattern, Plugin Shop Routing Placeholder (+7 more)

### Community 9 - "Composer Automation Scripts"
Cohesion: 0.17
Nodes (12): scripts, database-reset, frontend-clear, test-app-init, cd vendor/sylius/test-application && yarn install && yarn build, @database-reset, @frontend-clear, vendor/bin/console assets:install (+4 more)

### Community 10 - "Doctrine Migration"
Cohesion: 0.43
Nodes (3): Doctrine\DBAL\Schema\Schema, Doctrine\Migrations\AbstractMigration, Version20260812140643

### Community 11 - "Rector Configuration"
Cohesion: 0.40
Nodes (4): Rector\Config\RectorConfig, Rector\Set\ValueObject\LevelSetList, Rector\Set\ValueObject\SetList, Rector\Symfony\Set\SymfonySetList

### Community 12 - "Plugin Bundle Entry Point"
Cohesion: 0.60
Nodes (3): JpmMartinSyliusShippingCarriersPlugin, Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait, Symfony\Component\HttpKernel\Bundle\Bundle

## Ambiguous Edges - Review These
- `Gap: Feature File Belonging to No Behat Suite` → `Explicitly Empty Behat Default Suites`  [AMBIGUOUS]
  CLAUDE.md · relation: references
- `Plugin Architecture Overview` → `Plugin Skeleton README`  [AMBIGUOUS]
  README.md · relation: conceptually_related_to
- `Docker Setup via make init / make database-init / make load-fixtures` → `Override php Service (xdebug, volumes, app env)`  [AMBIGUOUS]
  README.md · relation: references
- `Environment File Updates` → `Plugin Shop Routing Placeholder`  [AMBIGUOUS]
  RENAME_GUIDE.md · relation: references

## Knowledge Gaps
- **72 isolated node(s):** `name`, `type`, `description`, `sylius`, `sylius-plugin` (+67 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 135 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **4 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **What is the exact relationship between `Gap: Feature File Belonging to No Behat Suite` and `Explicitly Empty Behat Default Suites`?**
  _Edge tagged AMBIGUOUS (relation: references) - confidence is low._
- **What is the exact relationship between `Plugin Architecture Overview` and `Plugin Skeleton README`?**
  _Edge tagged AMBIGUOUS (relation: conceptually_related_to) - confidence is low._
- **What is the exact relationship between `Docker Setup via make init / make database-init / make load-fixtures` and `Override php Service (xdebug, volumes, app env)`?**
  _Edge tagged AMBIGUOUS (relation: references) - confidence is low._
- **What is the exact relationship between `Environment File Updates` and `Plugin Shop Routing Placeholder`?**
  _Edge tagged AMBIGUOUS (relation: references) - confidence is low._
- **Why does `require-dev` connect `Dev and Test Dependencies` to `Composer Package Manifest`?**
  _High betweenness centrality (0.044) - this node is a cross-community bridge._
- **Why does `CarrierShippingOrigin` connect `Shipping Origin Entity` to `Dependency Injection Wiring`, `Shipping Origin Contract`?**
  _High betweenness centrality (0.041) - this node is a cross-community bridge._
- **Why does `CarrierShippingOriginInterface` connect `Shipping Origin Contract` to `Shipping Origin Entity`, `Dependency Injection Wiring`?**
  _High betweenness centrality (0.034) - this node is a cross-community bridge._