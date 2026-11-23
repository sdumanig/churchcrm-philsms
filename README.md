# PhilSMS Messaging for ChurchCRM

**Published plugin metadata version:** 1.0.0-rc2 (release candidate)
**Current development branch:** RC3 leader-assignment and schema-migration work (unreleased)
**Maintained by:** SDGTech Team of Soli Deo Gloria Christian Church–Davao City

PhilSMS is a community plugin integrating SMS messaging with ChurchCRM.

The RC2 release candidate was tested on a staging installation of
**ChurchCRM 7.8.6**. ChurchCRM **7.8.7** is the current RC3 development
target; the RC3 migration tests run against disposable MySQL and real Perpl
in CI, not against a ChurchCRM 7.8.7 staging deployment. It is not yet an approved ChurchCRM community-registry
plugin, and live SMS delivery has not been verified against a production
PhilSMS account.

## Features

### Select ChurchCRM People

- Select ChurchCRM people as SMS recipients.
- Normalize supported Philippine mobile numbers.
- Compose messages and review SMS character/segment usage.
- Support multiple recipients, subject to the plugin's submission limits.
- Use dry-run mode for testing without sending live SMS.

### Message Templates

- Five configurable message templates:
  - Individual Guest Welcome
  - Family Guest Welcome
  - General Guest Welcome
  - Guest Follow-up
  - Assigned Leader Follow-up
- Edit, preview, enable, and disable templates.
- Personalize messages using supported placeholders.
- Automatically prepare the appropriate enabled welcome template when an
  administrator selects an eligible recipient for an empty pending
  individual- or family-approval notification.
- Allow administrators to edit the prepared message before saving.

Automatic preparation does not save a draft or send an SMS. Existing
nonempty drafts are preserved.

The Assigned Leader Follow-up template is reserved in RC2. Leader registry,
assignment, and activity functionality is being developed on the RC3 branch;
it is not part of the published RC2 release candidate.

### Approval Notifications

- Queue supported ChurchCRM individual and family approval events.
- Select eligible recipients and save notification drafts.
- Review notifications before delivery.
- Require administrator authorization, CSRF protection, and explicit
  confirmation for the delivery workflow.
- Display pending ChurchCRM self-registration counts in the notification
  tab and plugin navigation when applicable.
- Maintain notification delivery state and prevent duplicate claims.

### Settings

- View plugin information and SMS configuration status.
- Configure the PhilSMS API key, Sender ID, and live-sending preference
  through ChurchCRM's plugin configuration.
- View the configured SMS mode and account-credit information where
  available.
- Avoid displaying stored API secrets in the settings view.

## Planned Features

- **Assign to a Leader:** Leader selection and assignment workflow.
  The tab currently displays a next-release placeholder.
- **Person Timeline SMS history:** Full SMS message and delivery details
  recorded in the relevant ChurchCRM person's timeline.

These features are not included in the published RC2 release candidate.
RC3 leader-assignment implementation remains unreleased and requires further
review before staging deployment.

## Installation and Configuration

1. Back up the ChurchCRM application and database.
2. Deploy the plugin directory to:
   `plugins/community/philsms`
3. Ensure the web server can read the plugin files.
4. Enable PhilSMS through ChurchCRM's Plugin Manager.
5. Configure the PhilSMS API key and Sender ID.
6. Keep `PHILSMS_LIVE_SEND=0` in the PHP runtime environment while
   testing. An unset value also prevents live sending.
7. Open the PhilSMS management interface as a ChurchCRM administrator.

Live SMS delivery requires **both** the server-side
`PHILSMS_LIVE_SEND=1` authorization and the corresponding enabled
ChurchCRM plugin setting. Do not enable live sending until the API
request format, sender registration, recipient consent, and account
configuration have been independently verified.

Never commit API keys or credentials to this repository.

## RC2/RC3 Schema Migration Procedures (Development Branch Only)

**Do not run these commands as part of plugin activation.** PhilSMS activation
does not initialize RC2 tables or apply RC3 migrations. The procedures below
are operator-controlled, staging-only operations requiring a separate,
explicit authorization for the identified staging environment. Publishing
these instructions does **not** authorize running them on CT 3200 or any
other server. Never use them against production CT 3100.

### Preconditions and safeguards

- Confirm the exact ChurchCRM staging database identity independently, and
  take and verify a restorable database backup before any approved change.
- Keep live sending disabled: `PHILSMS_LIVE_SEND=0`. Neither migration
  command sends SMS.
- Use a reviewed **absolute, non-symlink** Perpl vendor autoloader outside
  the PhilSMS plugin directory. The isolated CLI does not bootstrap ChurchCRM.
- Supply credentials via protected environment variables, not shell history,
  committed files, or application logs.
- The target database name must match the explicit positional argument,
  contain `staging`, and not contain `prod`. The adapter also verifies
  the connected database identity.
- Both commands acquire the MySQL advisory lock
  `philsms_schema_migrations` before their authoritative schema operations.
  If the lock or prerequisite validation fails, stop and investigate;
  never bypass these safeguards.

### Phase 1 — RC2 schema initialization (separate authorization required)

Only after an operator explicitly approves RC2 initialization for the
specific staging target, configure the protected environment:

```sh
export PHILSMS_RC2_INITIALIZE=YES
export PHILSMS_RC3_STAGING=YES
export PHILSMS_LIVE_SEND=0
export PHILSMS_RC3_DB_HOST='<staging-db-host>'
export PHILSMS_RC3_DB_PORT='3306'
export PHILSMS_RC3_DB_NAME='<verified_staging_db>'
export PHILSMS_RC3_DB_USER='<staging-db-user>'
# Set PHILSMS_RC3_DB_PASSWORD securely outside shell history.
php tools/rc33-initialize-rc2-isolated.php --apply \
  /absolute/external/vendor/autoload.php '<verified_staging_db>'
```

This command applies or verifies RC2 migrations 001–003, validates existing
RC2 schema compatibility, and records migration checksums. It is designed
to preserve compatible existing queue records and customized templates.
Review its output and database migration history before proceeding.

### Phase 2 — RC3 migrations (new, separate authorization required)

RC3 must **not** be inferred as authorized merely because RC2 completed.
After explicit RC3 approval for the same verified staging target, use the
same protected connection settings and:

```sh
export PHILSMS_RC3_MIGRATE=YES
export PHILSMS_RC3_STAGING=YES
export PHILSMS_LIVE_SEND=0
php tools/rc32-apply-isolated.php --apply \
  /absolute/external/vendor/autoload.php '<verified_staging_db>'
```

The RC3 runner requires valid RC2 schema and checksum history, then applies
or verifies migrations 004–006 for leader registry, assignments, and activity.
The runner validates prerequisites **under the shared lock** before RC3 DDL.
A refused command is a stop condition, not permission to repair history
manually or disable validation.

### Verification and rollback planning

Before approval, record the intended target, backup/restore procedure,
migration versions, and responsible operator. After each authorized phase,
inspect `philsms_schema_migrations` and the relevant tables; preserve the
command output without disclosing credentials. Test idempotency only with
separate approval if rerunning on staging.

MySQL DDL is not generally transactionally reversible. There is **no
automatic rollback command** for these migrations; recovery requires an
operator-approved restoration plan using the verified backup. Do not
experiment with destructive rollback SQL on a shared staging or production
database.

The CI workflow exercises these commands only against disposable local
MySQL databases, with live SMS disabled. Passing CI does not constitute
authorization to deploy or migrate a real staging installation.

## Testing

RC2 development and browser verification were performed against a
staging ChurchCRM 7.8.6 installation. Separately, RC3 development CI tests
exercise migration safety and leader-assignment components using disposable
MySQL databases and Perpl; they do not establish end-to-end staging
compatibility with ChurchCRM 7.8.7.

The existing seven-script regression suite passed **86 assertions with
zero failures**. Tests cover authorization, confirmation, notification
delivery state, message-template validation and rendering, and template
database integration.

Browser verification confirmed automatic Welcome Message preparation,
personalization, draft editing, segment counts, and the existing review
workflow.

These tests do not establish successful live SMS delivery.

## Security and Operational Notes

- Administrative operations require ChurchCRM authorization.
- Notification draft and delivery routes use CSRF validation.
- Prepared welcome messages are not automatically saved or sent.
- The delivery workflow requires an explicit review and confirmation.
- Live sending is disabled unless both required authorization controls
  are enabled.
- Administrators are responsible for recipient consent, applicable
  privacy requirements, and appropriate retention practices.

## Compatibility

**RC2 staging-tested:** ChurchCRM 7.8.6.

**RC3 development target:** ChurchCRM 7.8.7. The current RC3 branch has
passed isolated CI tests but has not been deployed to the 7.8.7 staging
installation as part of this development workflow.

Other ChurchCRM versions have not been verified for this release
candidate. The `minimumCRMVersion` value in `plugin.json` is metadata,
not a claim that every version from that minimum has been tested.

## Community Plugin Status

This is a manually deployed release candidate. Publication on GitHub
does not constitute acceptance into the official ChurchCRM community
plugin registry.

## Development Acknowledgment

Developed with AI assistance using ChatGPT by OpenAI.

Project maintenance and release management: SDGTech Team.
