=== AI Blog Builder ===
Contributors: cbiastudio
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 1.0.1
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate complete blog posts with AI (text + featured image) using a safe checkpoint workflow and a live log.

== Description ==

AI Blog Builder helps you generate WordPress posts with OpenAI without blocking the admin screen. It processes one post at a time, keeps progress with a checkpoint, and lets you stop and resume safely.

Key features:

* AI-generated HTML content (no H1) + 1 featured image
* Checkpoint-based queue (stop / resume anytime)
* Live activity log
* Uses your own OpenAI API key and explicit consent
* No external dependencies required

How it works:

1. Add titles in the Blog tab.
2. Start generation.
3. The plugin processes 1 post per run and re-schedules if needed.
4. Use STOP to pause and resume later.

== Installation ==

1. Upload the ZIP in Plugins → Add New → Upload Plugin
2. Activate the plugin
3. Go to Settings → AI Blog Builder
4. Add your OpenAI API key and accept consent
5. Configure and start generating

== Frequently Asked Questions ==

= Do I need an OpenAI API key? =
Yes. You must provide your own API key and explicitly accept consent in the settings.

= Can I stop and resume? =
Yes. The checkpoint keeps the queue and progress. Press STOP to pause, then resume later.

= Will it block my admin? =
No. It runs in short batches and keeps the admin responsive.

= Does it require Yoast or other plugins? =
No. It works standalone. If Yoast is installed, the plugin updates basic metadata on post creation.

== Changelog ==

= 1.0.1 =
* Fix default author selector display.
* Move admin button styling and logic to assets.
* Update post length labels and API key link.

= 1.0.0 =
* Initial stable release
* AI text + featured image
* Checkpoint queue and live log
* Safe STOP / resume

== Screenshots ==

1. Main panel with checkpoint status
2. Live activity log
3. Settings (API key + model)
