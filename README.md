# PhilSMS Messaging for ChurchCRM

**Version:** 1.0.0-rc2 (release candidate)
**Maintained by:** SDGTech Team of Soli Deo Gloria Christian Church–Davao City

PhilSMS is a community plugin integrating SMS messaging with ChurchCRM.

This release candidate has been tested on a staging installation of
**ChurchCRM 7.8.6**. It is not yet an approved ChurchCRM community-registry
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

The Assigned Leader Follow-up template is reserved for the upcoming
leader-assignment feature.

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

These features are not included in RC2.

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

## Testing

RC2 development and browser verification were performed against a
staging ChurchCRM 7.8.6 installation.

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

**Tested:** ChurchCRM 7.8.6.

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
