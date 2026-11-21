# PhilSMS for ChurchCRM 7.8.5 — release candidate 1

**Status:** PHP syntax-checked against the supplied ChurchCRM 7.8.5 source. **Not yet tested in a running ChurchCRM or against a live PhilSMS account.** This is a manually deployed community plugin candidate, not an approved-registry installer ZIP.

## Install on a staging copy of ChurchCRM

1. Back up ChurchCRM database and files. Extract `philsms/` into the ChurchCRM application `plugins/community/` directory (not `src/plugins`). Ensure permissions allow the PHP process to read the files.
2. Configure the PHP runtime environment (`php-fpm` pool or Apache environment): `PHILSMS_API_TOKEN` = your PhilSMS bearer token. Optionally set `PHILSMS_SENDER_ID` if your PhilSMS account requires it. **Do not put secrets in web-accessible files.**
3. Set `PHILSMS_LIVE_SEND=0` (or leave unset) for dry run. Restart PHP-FPM / Apache as applicable.
4. In ChurchCRM's plugin management screen, find PhilSMS Messaging and enable it if discovered. Open `/plugins/philsms/manage` as an administrator.
5. Enter ChurchCRM person IDs from member profile URLs, compose a message, and test dry run. Use the balance link to test API authentication.
6. Only after a successful dry run and verification of your PhilSMS API payload, set `PHILSMS_LIVE_SEND=1` and restart PHP. Send **one** test SMS to a consenting member, then verify in PhilSMS dashboard before any batch.

## Important limitations

- ChurchCRM 7.8.5's official community plugin installer requires an entry in its approved plugin registry, an approved SHA-256 hash, and an HTTPS download URL. This ZIP is **not** approved and must not be presented as installable through the official URL installer.
- This release candidate provides individual and multi-member sending by **person ID** (up to 25 per request), plus a balance endpoint. It does **not** yet include a member picker, group selection, persistent message history, delivery-status polling, or a settings UI. Do not assume these exist.
- The PhilSMS endpoint and request schema must be verified with your account's current API documentation before enabling live sending. Current implementation uses `POST https://app.philsms.com/api/v3/sms/send` with JSON `recipient`, `message`, and optional `sender_id`. If your account requires other fields, adjust before live use.
- The application must have PHP cURL and mbstring enabled. The plugin relies on ChurchCRM's existing authenticated admin middleware and PHP session for CSRF checks.
- Consent, opt-out handling, and retention requirements remain the church administrator's responsibility. This version does not automatically check contact preferences.

---

## Project Information

**Plugin:** PhilSMS Messaging for ChurchCRM  
**Version:** 1.0.0-rc1  
**Developed and maintained by:** SDGTech Team  
**Organization:** Soli Deo Gloria Christian Church

This plugin was developed with AI assistance using ChatGPT by OpenAI and is maintained by SDGTech Team.

## Compatibility

Tested with ChurchCRM 7.8.5.

Compatibility with other ChurchCRM versions has not yet been verified.

## Development Status

Release Candidate (RC1).

The following functionality has been tested:

- PhilSMS API authentication
- PhilSMS account balance retrieval
- ChurchCRM person lookup
- Philippine mobile number normalization
- Multiple-recipient selection
- SMS dry-run processing

Live SMS delivery has not yet been verified and requires an approved PhilSMS Sender ID.

## Security

Configure the PhilSMS API token through the server environment.

Never include API tokens, credentials, or church member information in the distributed plugin.

Keep live sending disabled until configuration and testing are complete.

## Development Acknowledgment

AI-assisted software development using ChatGPT by OpenAI.

Project maintenance and release management: SDGTech Team.
