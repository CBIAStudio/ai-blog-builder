=== AI Blog Builder Pro ===
Contributors: webgoh
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 3.0.4
Requires PHP: 8.2
Network: true
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pro version of AI Blog Builder: advanced AI generation (text + images), checkpoint resume, and pending image fill. Estimated and real cost tracking.

== Description ==

Pro plugin to create complete posts with OpenAI (text + images) without blocking the screen. It processes in batches, resumes with checkpoints, assigns categories/tags by rules, and lets you fill pending images (manual or CRON). Requires the free version active.
This plugin uses the OpenAI API only when enabled by the user in the settings and with explicit consent.

Key features:
* 1 featured image + (images_limit - 1) in-content images
* [IMAGE: ...] markers inserted automatically if missing; extra markers are removed
* Live log and safe STOP
* Costs: quick estimate and REAL cost per call (optional fixed image price)
* Quick environment/plugin diagnostics
* Optional Yoast SEO support. The plugin does not require Yoast; if active, it syncs meta and hooks.

== Installation ==
1. Upload the plugin folder to `wp-content/plugins/`.
2. Activate the plugin from “Plugins”.
3. Go to Settings -> AI Blog Builder, add your API Key, and configure.

== Frequently Asked Questions ==

= How does resume work? =
It uses a checkpoint that stores queue, index, and totals. The “Create Blogs” button enqueues an event; each event processes N posts (default 1) and re-schedules if the queue remains.

= What happens if an image fails? =
It is replaced by a hidden “pending” marker. You can click “Fill pending” or let CRON handle it.

= Why does the real cost not match? =
Enable “fixed price per image”, adjust mini/full rates and, if needed, add the text/SEO call overhead and the real-cost multiplier.

