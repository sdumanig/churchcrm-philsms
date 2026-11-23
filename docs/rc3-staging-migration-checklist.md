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

1. Reconfirm the actual installed staging CT 3200 ChurchCRM version (target:
   7.8.7), PHP version and MySQL compatibility before deployment.
2. Take and verify a restorable **staging-only** application/database backup.
   Record a rollback owner and a maintenance window.
3. Verify `PHILSMS_LIVE_SEND=0` in the actual PHP runtime and the plugin
   `liveSending` setting is off. Preserve RC2 queue/drafts/history.
4. Review the migration files, their checksums, and current schema with a
   read-only connection. Verify the existing RC2 queue table is present.
5. Determine whether ChurchCRM will call `activate()` on the intended update.
   If not, use the separately authorized isolated migration CLI with an
   independently verified staging database connection. Do not execute
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

## Isolated migration strategy for ChurchCRM 7.8.7

ChurchCRM's normal application bootstrap can initialize sessions, check database
versions, and perform other application work before a migration script can verify
its target. Therefore, **do not use** `src/Include/Config.php`,
`src/Include/LoadConfigs.php`, or any arbitrary ChurchCRM bootstrap as a
migration entry point. The older bootstrap-based CLI was **removed** from
this feature branch after the isolated CLI passed integration testing.

The supported RC3 migration candidate is `tools/rc32-apply-isolated.php`.
It uses the reviewed ChurchCRM `vendor/autoload.php` (not the application
bootstrap), initializes a minimal real Perpl/Propel connection, verifies the
exact expected staging database, checks the existing RC2 queue, and then
runs migrations 004–006. It has passed the disposable PHP 8.4, real Perpl
2.7.0 and MySQL 8.0 integration suite. This does **not** establish that the
installed CT 3200 environment has been validated or authorize a deployment.

## Gated isolated CLI invocation (reference only; do not execute yet)

Only after **separate, explicit authorization for both staging deployment
and database migration**, an operator may adapt this template. Review the
real ChurchCRM vendor autoloader location, the actual staging database name,
host, port, account privileges and backup **before** entering credentials.
Never paste real secrets into GitHub, chat, shell history, or logs. Supply
database credentials through a protected operator-managed environment.

```bash
# REFERENCE ONLY — not approved for execution on CT 3200
export PHILSMS_LIVE_SEND=0
export PHILSMS_RC3_STAGING=YES
export PHILSMS_RC3_MIGRATE=YES
export PHILSMS_RC3_DB_HOST='<verified-staging-db-host>'
export PHILSMS_RC3_DB_PORT='<verified-port>'
export PHILSMS_RC3_DB_NAME='<actual_staging_db>'
export PHILSMS_RC3_DB_USER='<staging-migration-user>'
# Set PHILSMS_RC3_DB_PASSWORD securely in the operator environment.

php tools/rc32-apply-isolated.php --apply \
  /verified/absolute/path/to/ChurchCRM/vendor/autoload.php \
  '<actual_staging_db>'
```

The autoloader must be a readable, absolute, non-symlink path outside the
plugin directory. The command requires explicit authorization flags, disables
live sending, rejects mismatched database identity, and refuses an installation
without the RC2 notification queue. The name-based staging guard is **not**
proof that a database is non-production; verify the physical target
independently. This command is **never** permitted on production CT 3100.

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

The GitHub Actions suite runs offline PHP tests, disposable MySQL 8.0
integration tests, and an isolated PHP 8.4 / real Perpl 2.7.0 migration
CLI test (including refusal cases and idempotent reapplication).
These are **not** a substitute for ChurchCRM browser, middleware, lifecycle,
or staging compatibility verification.
