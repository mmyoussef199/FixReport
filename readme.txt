=== FixReport - Maintenance Logger ===
Contributors: mohamedyoussef404
Donate link: https://paypal.me/myoussef188
Tags: maintenance log, activity log, pdf report, admin tools, site audit
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Record WordPress maintenance, capture historical site snapshots, and prepare client reports with PDF export.

== Description ==

FixReport, developed by Mohamed Youssef under the Code Admin brand, helps site administrators document maintenance work and prepare client reports from recorded evidence. Its admin screens distinguish historical records from the site's current state. FixReport does not perform maintenance tasks or continuously monitor a site.

Features include:

* Maintenance Activities: Add and edit manual records with a category, status, priority, date, description, and user attribution. Search and filter recorded activities. Deleting a manual activity soft-deletes its record rather than erasing the historical row.
* Automatic activity recording: Record supported WordPress core, plugin, and theme update or lifecycle events when the corresponding WordPress hooks run. These records describe observed events; they do not initiate updates.
* Site Baseline and Snapshots: Capture an initial historical site baseline and later manual snapshots. Review captured WordPress, environment, plugin, theme, and other available site information. Captures are historical evidence, not live monitoring.
* Snapshot Comparison: Compare supported completed snapshots and see recorded changes, unchanged values, and evidence that could not be compared reliably.
* Maintenance Checklist: Document routine tasks through manual status changes and view their recorded history. A completed task is documentation entered by a user, not independent verification that the work was performed.
* Guided Report Builder: Create and save Draft maintenance reports, select which recorded activities and report-checklist items to include, and add summaries, findings, and recommendations. Reports may include selected historical snapshots and comparisons.
* Report language and branding: Prepare report content in English or Arabic. Configure optional company details and a logo as defaults; a Draft can inherit, override, or clear those values.
* PDF export and finalization: Export Draft or Finalized reports as PDF. Finalization binds the report to its saved preview and freezes an integrity-checked historical document. Finalized reports are read-only; later changes to site records or branding defaults do not rewrite them.

The operational Maintenance Checklist and the checklist items within a report are separate records. Changing an operational task does not automatically change a report.

== Installation ==

1. In WordPress, go to Plugins > Add New and search for "FixReport - Maintenance Logger" in the WordPress.org directory. Alternatively, select Upload Plugin and choose a valid FixReport plugin ZIP file.
2. Install and activate the plugin.
3. Open FixReport in the WordPress admin menu to access the Dashboard, Activities, Site Snapshots, Site Baseline, Maintenance Checklist, Reports, and Report Branding screens.

== Frequently Asked Questions ==

= What does FixReport record? =

It stores manual Maintenance Activities and supported automatic WordPress core, plugin, and theme events. It can also store historical site snapshots, manual checklist history, and report documents.

= Does FixReport perform maintenance automatically? =

No. Automatic activity recording documents supported WordPress events when they occur. It does not run updates, repairs, backups, or other maintenance tasks for you.

= Can I create site snapshots? =

Yes. An administrator can capture an initial Site Baseline and later manual Site Snapshots, then compare supported completed snapshots. Each capture reflects what was recorded at that time, not the site's live state.

= Can I generate PDF reports? =

Yes. You can export a saved Draft or an integrity-verified Finalized report as PDF. A report includes only the evidence selected for it; exporting does not automatically include every activity on the site.

= Can I customize report branding? =

Yes. Company details and a logo are optional. A Draft can inherit the site-wide defaults, override individual values, or leave them blank. Changing defaults later does not change a Finalized report.

= What happens when a report is finalized? =

FixReport checks the saved Draft and preview before freezing the report as an integrity-checked historical document. The Finalized report can be viewed and exported but cannot be edited or reopened as a Draft.

= Does FixReport support Arabic reports? =

Yes. Report content and PDF presentation support Arabic as well as English. This does not mean every WordPress admin screen is translated into Arabic.

== Changelog ==

= 1.3.1 =

* Introduced manual and supported automatic Maintenance Activities, a historical Site Baseline, manual Site Snapshots, and Snapshot Comparison.
* Added a manually documented Maintenance Checklist with status history.
* Added a guided Draft report workflow with selective evidence, optional branding, English and Arabic report content, PDF export, and integrity-checked Finalized reports.
* Added Dashboard and Reports administration screens for accessing recorded evidence and existing reports.

= 1.0.1 =

* Manual maintenance logs, admin list views, and PDF export.
