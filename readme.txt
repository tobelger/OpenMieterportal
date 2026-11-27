=== OpenMieterportal ===
Contributors: tobelger
Tags: tenant portal, property management, documents, meter readings, support
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: supportcandy
Stable tag: 0.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A shared account per tenancy for contracts, private documents, tenant enquiries and meter readings in WordPress.

== Description ==
OpenMieterportal adds a tenant portal to an existing WordPress website. Buildings and apartments retain their measuring points and meter history when tenants change.

Features include:
* One shared account per tenancy, with a single-use 16-character invitation valid for 30 days.
* Printable invitation letters with locally generated QR codes and configurable branding.
* Contract dates, termination dates and rent components with effective dates.
* Private PDF documents, server-side ownership checks, integrity verification and first-retrieval timestamps.
* Measuring points, replaceable meter devices, validity periods and readings submitted by tenants or management.
* Enquiries through the free SupportCandy plugin, installed and updated separately.
* Contact profiles, required bank details when saving, password verification for bank changes and confirmed email-address changes.
* Immediate, daily or weekly notifications. Account confirmations and security notifications are immediate.
* Optional context-bound encryption using AES-256-GCM and a separate server-side key file.
* Optional manager email two-factor enforcement through the official free Two Factor plugin.

The interface supports WordPress translation functions. Existing technical table names and legacy shortcodes remain compatible. No Divi, Timber, WPForms, subscription or paid SupportCandy add-on is required.

This plugin does not automatically transfer changes into external accounting software. A bank account entry is not a direct debit mandate. The communication agreement is a configurable starting text that the site operator must review for their actual use.

== Installation ==
1. Install and activate SupportCandy from WordPress.org.
2. Install OpenMieterportal. Configure a private document directory owned by the PHP process (recommended permissions: 0700) before making documents available. Define BPM_PRIVATE_DIR in wp-config.php before wp-settings.php. There is no public uploads fallback.
3. Activate on a separate test installation first. The new portal page containing [openmieterportal] starts private.
4. Create an activation page with the same shortcode. Set its page ID in the bpm_activation_page_id option; the portal page ID is stored in bpm_page_id.
5. Set management branding, logo, address and communication wording in the portal administration. Review the agreement before enabling it.
6. Configure SupportCandy categories and notifications. Set bpm_supportcandy_enabled to production to enable integration for normal tenant accounts.
7. Configure and verify WordPress email delivery. Set up reliable execution of WP-Cron. Exclude portal, activation and download endpoints from page/CDN caches. Use HTTPS.
8. Test invitation registration, two separate tenant accounts, own and foreign document requests, mail delivery and backup restoration before publishing pages or sending invitations.

See README.md for commands and SECURITY.md for private storage, key setup, explicit encryption migration, key rotation, recovery and limitations. An update alone does not encrypt existing records. Key files must be separate from the database and inaccessible through every public hosting alias.

== Privacy and security ==
The portal stores tenancy/contact information, bank details, rent components, documents, readings, communication choices, retrieval timestamps and security events. WordPress and SupportCandy store additional account and enquiry data.

After explicit setup and migration, selected plugin-owned fields, account profiles and files in the private portal store are encrypted. Native WordPress/SupportCandy email addresses, native ticket subjects, messages and attachments, and some relationship/device/workflow metadata are not covered by that field encryption. SupportCandy attachments still require server-side HTTP protection and authorization. A full server or privileged WordPress compromise is outside the protection of at-rest encryption.

PDFs are limited to 15 MB. Meter photos accept JPG/PNG up to 8 MB and 12 megapixels and are re-encoded without EXIF metadata. PDFs are checked against their stored SHA-256 and length before delivery. Corrections are new documents; existing PDFs cannot be edited or replaced through the portal. This does not provide an independent immutable archive or legal certification.

Notifications are sent through the site's configured WordPress mail service. QR codes are generated locally; there is no external QR service or built-in telemetry. The configured email provider receives email recipients and notification content. Operators must configure appropriate retention, backups and privacy notices for WordPress, SupportCandy and any mail logging plugins.

Access expires at most 25 months after a recorded tenancy end. This is an access limit, not automatic deletion of legally retained documents. Deactivation does not delete tenancy records or private files.

== Frequently Asked Questions ==

= Is a paid service required? =
No. WordPress and the free SupportCandy base plugin provide the required platform. A working hosting and email setup is needed.

= Does installing an update encrypt the database automatically? =
No. Configure the separate key file and run the documented explicit CLI migration during a maintenance window. Secure and test backups first.

= Can every database field and third-party attachment be encrypted? =
No. SECURITY.md lists the exact boundaries. Never assume that WordPress or SupportCandy data is fully encrypted by this plugin.

= Can I delete everything by deactivating the plugin? =
No. Deactivation preserves records and files to avoid accidental loss. Review retention and take a backup before administrator-led removal.

== Changelog ==

= 0.10.0 =
* Prepare the distribution for WordPress.org with a neutral text domain, translation support, clearer setup/privacy documentation and a separate installable package.
* Use WordPress SQL identifier placeholders and strengthen explicit data-context handling.

= 0.9.1 =
* Correct encrypted building and apartment labels in the apartment table containing count subqueries.

= 0.9.0 =
* Add separate encryption keys, context-bound encrypted fields/private files, explicit migration and key rotation.
* Add attempt limits, bounded sessions, password verification for bank changes and optional manager two-factor enforcement.
* Make branding and communication wording configurable.

= 0.8.1 =
* Prevent overlapping long rent-component labels.

== Upgrade Notice ==

= 0.10.0 =
Back up before upgrading. Existing table names and shortcodes remain compatible. Encryption requires separate explicit configuration and migration; see SECURITY.md.
