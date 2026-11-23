# RC3.1 staging migration and activation checklist

**Status:** preparation only. Do not run this checklist on production CT 3100.
Do not deploy RC3 to staging CT 3200 until the operator explicitly authorizes it.
The feature branch is not a deployment instruction.

## Why an already-enabled plugin needs special handling

`PhilSMSPlugin::activate()` now initializes the RC2 notification queue and
calls `MigrationRunner::run()` for migrations 004–006. **Replacing plugin files
does not necessarily invoke `activate()` again**. Do not assume that an
already-enabled ChurchCRM installation has applied RC3 migrations.

Do not disable/re-enable the plugin merely to trigger migrations without first
confirming ChurchCRM's plugin lifecycle behavior, the current installed version,
and the effects on RC2 notification history. Do not run the standalone
`tests/migration-runner-integration.php` against a real ChurchCRM database:
it deliberately refuses databases outside `philsms_test_*`.

## Before any staging deployment (operator checklist)

1. Confirm staging CT 3200 ChurchCRM version and PHP/MySQL compatibility;
   ChurchCRM 7.8.7 has **not yet been confirmed** in this project.
2. Take and verify a restorable **staging-only** application/database backup.
   Record a rollback owner and a maintenance window.
3. Verify `PHILSMS_LIVE_SEND=0` in the actual PHP runtime and the plugin
   `liveSending` setting is off. Preserve RC2 queue/drafts/history.
4. Review the migration files, their checksums, and current schema with a
   read-only connection. Verify the existing RC2 queue table is present.
5. Determine whether ChurchCRM will call `activate()` on the intended update.
   If not, design and separately review an authenticated, explicit migration
   invocation using the **real ChurchCRM Propel connection**. Do not execute
   arbitrary SQL from a web request or invoke the test runner in staging.
6. Obtain explicit approval for both the plugin deployment and schema change
   before running either. Production CT 3100 is out of scope.

## RC3 migration order

- `004_leader_registry.sql`: designated leader registry
- `005_leader_assignments.sql`: one assignment per approval event
- `006_assignment_activity.sql`: assignment audit history

`MigrationRunner` uses a MySQL advisory lock and a SHA-256 checksum history
table. Each migration is idempotent, but MySQL DDL is not transactional; a
failed run requires investigation before retry. **Do not edit a migration
already recorded in `philsms_schema_migrations`.**

## ChurchCRM 7.8.7 bootstrap source review (not an installation test)

Upstream tag `ChurchCRM/CRM@7.8.7` has
`src/Include/Config.php` (installed configuration) which loads
`src/Include/LoadConfigs.php`. The latter loads Composer autoloading,
parses configuration, and calls `ChurchCRM\\Bootstrapper::init(...)`.
This is **not a side-effect-free database bootstrap**: initialization can
also perform application/session initialization, database version checks,
and key management. Never assume including `LoadConfigs.php` is safe
in an unattended migration script or compatible with CLI request context.

The gated migration command accepts an explicitly reviewed bootstrap file,
but **no supported, verified CLI bootstrap path has yet been established**.
Do not use the application configuration file or `LoadConfigs.php`
directly as a shortcut. First inspect the installed staging source and
version, confirm the bootstrap's behavior in an isolated clone, and
review any required environment variables or request context. The
existing simulated-bootstrap CI test cannot validate these properties.

## Gated CLI invocation for an already-active plugin (not yet staging-validated)

`tools/rc3-apply-migrations.php` is a CLI-only entry point, not a web route.
It requires an **operator-reviewed ChurchCRM bootstrap file** outside the
plugin directory that initializes the real Propel connection. The correct
bootstrap path for the installed ChurchCRM version is **not yet verified**;
do not guess it or execute this command until confirmed on CT 3200.

After explicit deployment and database-change authorization, a staging
operator can adapt this template using the *verified* bootstrap path and
*actual* staging database name:

```bash
PHILSMS_LIVE_SEND=0 PHILSMS_RC3_STAGING=YES PHILSMS_RC3_MIGRATE=YES \
  php tools/rc3-apply-migrations.php --apply \
  /verified/absolute/path/to/churchcrm-bootstrap.php actual_staging_db
```

The command refuses web execution, missing flags, non-staging database names,
mismatched connected database names, and missing RC2 notification tables.
**The name-based staging guard is not proof that a database is non-production**:
the operator must independently verify the connection target and backup
before authorizing execution. No production use is permitted.

## Post-migration verification (only after separately authorized execution)

- Verify exactly the three reviewed versions are recorded with matching
  checksums and that all three RC3 tables exist.
- Confirm RC2 notification rows and drafts are unchanged.
- Open the admin assignment tab with live sending disabled; verify the
  designated-leader and approval-event lists load without a migration warning.
- Exercise one controlled, consented staging assignment, including a family
  adult contact, and verify its audit record. Confirm duplicate prevention.
- Verify no SMS was sent; preserve logs and the staging rollback point.

## Current automated evidence

The GitHub Actions suite runs offline PHP tests and disposable MySQL 8.0
integration tests, including route-to-database assignment and audit checks.
These are **not** a substitute for ChurchCRM browser, middleware, lifecycle,
or staging compatibility verification.
