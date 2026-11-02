Database migrations.

Each upgrade can ship .sql files here. They run once (tracked in the
panel_migrations table), in filename order, during installation, an auto-update,
or the first request after a manual code upload. The front controller
calls Schema::sync before dispatching the page. Its version/migration-count
marker makes later requests a no-op, and failures are logged and retried.

database\panel_schema.sql is the initial schema. Every later panel-owned table,
column or index change belongs in a new migration. Models retain recovery
fallbacks, but they are not a replacement for SQL migration coverage.
panel_migrations itself is bootstrapped by Migrator.

Do not create or alter plugin-owned tables (sa_*, VIPCore, K4, WeaponPaints,
cs2-store or chat_logs) here. The panel only manages its own panel_* schema.

Sanction Details is read-only and needs no migration. Ban/mute dialogs read
the linked sa_unbans/sa_unmutes record and resolve admin_id through sa_admins.
Missing historical actors/dates/reasons are marked as not recorded; admin_id=0
is not assumed to identify a specific panel user. Warnings have no separate
removal history in sa_warns. The recorded end date is shown as stored (panel
unbans can set bans.ends to the time of removal). IP stays moderator-only.

Naming: NNN_description.sql   e.g.  001_add_vip_groups.sql
        Use a zero-padded numeric index (001, 002, ...) so files sort in order.

Rules:
- Use "IF NOT EXISTS" / "IF EXISTS" so re-running is harmless.
- Plain ADD COLUMN statements may be used for MySQL/MariaDB portability;
  Migrator also tolerates duplicate-column/index errors on partial upgrades.
- One statement per ";" at end of line.
- Do not include stored routines (the simple splitter doesn't handle them).
- Session-local SET/PREPARE/EXECUTE statements can guard a legacy type change;
  never narrow a field that may already contain longer user data.
- Never edit an already-shipped migration; append the next numbered file.

015 adds activity, benefits and pending VIP tables.
016 adds the missing Store/Contests fields and permits orders without VIP.
017 upgrades legacy benefits columns while preserving larger text fields.

Regression test: tests\migrations-smoke.php requires PDO MySQL and explicit
PANEL_MIGRATION_TEST_HOST=127.0.0.1, PANEL_MIGRATION_TEST_PORT,
PANEL_MIGRATION_TEST_USER and PANEL_MIGRATION_TEST_PASSWORD values.
Use an isolated MySQL/MariaDB test service, never production credentials.
The test creates and removes its own cs2_panel_migration_test_* databases.

Example:
  ALTER TABLE `panel_users` ADD COLUMN IF NOT EXISTS `note` VARCHAR(255) NULL;
