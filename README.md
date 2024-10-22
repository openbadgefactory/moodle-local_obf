Open Badge Factory plugin
=================

Open Badge Factory is a cloud platform that provides the tools your organization needs to implement a meaningful and sustainable Open Badges system.

With the local_obf plugin you can issue Open Badges created in Open Badge Factory. To use the plugin, you need an account on
[https://openbadgefactory.com](https://openbadgefactory.com) (You can register for free, see
[https://openbadgefactory.com/en/pricing/](https://openbadgefactory.com/en/pricing/) for details about different service levels).


How to install
--------------

Moodle 4.1 and up:

1. Install the zip via Moodle's plugin page. Select "local" as the type of the plugin. (alternative: unzip to moodle's local subdirectory)
2. Update the database using the notifications page
3. Complete the [Post install steps](README.md#post-install)

Totara 11.0 and greater

Totara Learn does not include an add-on installer, all additional plugins must be installed manually by server administrators.

1. Download plugin from https://moodle.org/plugins/local_obf
2. Unzip the file into the Totara installation directory.
3. By using a site administrator account, go to Site administration → Notifications and upgrade Totara database
4. Complete the [Post install steps](README.md#post-install)

Post install
------------------

To connect to Open Badge Factory, the plugin needs a request token or API key.

To generate the required API key, log in to Open Badge Factory. When logged in, navigate to `Admin tools > API`.

Legacy key:

On the API key page click on `Generate certificate signing request token` for legacy type key. Copy the generated token into OBF Moodle plugin settings, in `Site administration > Open Badges > Settings`.

OAuth2 key:

Pro level clients can also connect with OAuth2. This supports multiple clients on one Moodle installation.

On the API key page click on `Generate new client secret` for OAuth2 Client Credentials. Give a description for the key and copy the client id and secret values into OBF Moodle plugin settings, in `Site administration > Open Badges > Settings`.

Changelog
------------------

1.0.11

- Improved badge sorting

1.0.10

- Minor improvements to badge listing: sorting, show full name

1.0.8

- Badge awarding rules and history fixes

1.0.7

- Passport email verification code fix

1.0.6

- Small fixes to plugin config and activity awarding rules

1.0.5

- Course completion awarding rule fix

1.0.4

- User profile page badge list fixes
- Minor bug fixes

1.0.3

- Fix issues related to categories when multiple clients are connected

1.0.2

- Fix deprecated code issues in Moodle 4.3

1.0.1

- Bug fix for DB upgrade
- Minor bug fixes

1.0.0

- New features
  - Pro customers (OAuth2) - Set specific badge permissions on Moodle categories (link OBF categories with Moodle categories)
  - Display OBF earned badges on student profile - this feature was moved from displayer block plugin to local-obf (the displayer block plugin has reached End Of Life)

- Major improvements
  - Awarding rule activity achievement: show only resources and activities with achievement, section names introduced, name and icon of resource/activity visible
  - New web notification preferences for:
    - All users: New badge is issued.
    - Teachers: New badge is issued to a student, A student’s badge has been revoked.
  - Awarding history : new export button

- Minor Improvements:
  - Public badges selected by default when connecting OB Passport account
  - Change some inconsistent behaviors and look and feel
  - Multiple translation modifications in AMOS (French mainly)

- Privacy API implemented

- Bugs fixes:
  - Export Moodle badges to OBF account restored

0.8.0

- Badge issuing report fixes

0.7.0

- MySQL reserved word column name fix

0.6.1

- User name field fix
- String escape fixes

0.6.0

- Include recipient name in issued badges
- Badge string escape fixes

0.5.5

- API auth fixes

0.5.4

- PostgreSQL query fix
- Replaced array\_key\_first function

0.5.3

- PostgreSQL query fix

0.5.2

- Fixed warnings for missing page context
- Fixed api call for all user badges when using legacy connection

0.5.1

- Connect multiple Factory clients with OAuth2
- Awarding rules bug fixes
- Other minor fixes and improvements

0.4

- Fixed problem with Moodle 3.10.1
- Added support for Totara program and certications
