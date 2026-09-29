=== Olymp Tools ===
Contributors: olympagency
Tags: google reviews, geolocation, shortcodes, ai disclosure, marketing
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standalone marketing tools: Google Reviews shortcodes, GDPR-friendly visitor-location shortcodes and an AI-image disclosure marker.

== Description ==

Olymp Tools is a lightweight container for standalone marketing features. Each tool has its own submenu under the **Olymp Tools** admin menu. Use as much or as little as you need.

**Google Reviews** — Display your Google rating and review count anywhere via shortcodes:

* `[olymp_google_reviews_average]` → average rating, e.g. "4.6"
* `[olymp_google_reviews_count]` → total review count, e.g. "128"

The values are fetched server-side from the Google Places API and cached; your API key never reaches the browser and the shortcodes keep serving the last known-good numbers if a refresh fails.

**Visitor Location** — Insert the current visitor's location into your copy for location-personalised marketing (e.g. "Best offers near [olymp_visitor_city]"):

* `[olymp_visitor_city default="..."]`, `[olymp_visitor_region ...]`, `[olymp_visitor_country ...]`, `[olymp_visitor_country_code ...]`
* `[olymp_visitor_location field="city,country" separator=", " default="..."]`

Lookups are 100% local against a DB-IP Lite City database stored on your server — the visitor's IP never leaves your site. Rendering is client-side, so it works correctly behind full-page caching.

**AI-Image Marker** — Mark images as AI-generated and disclose it to visitors with a badge overlaid on every use of the image (transparency obligations such as the EU AI Act):

* Mark images one by one in the attachment details, in bulk in the Media Library list view (with an "AI" column and filter), or automatically on upload.
* Configurable text or custom-image badge — position, size, colors, tooltip, link, exclusion selectors, custom CSS — with a live preview; optional alt-text disclosure for screen readers.

The mark is stored with the image in the library — never baked into the image file — so the badge is configured once and applies everywhere. Rendering is client-side on the final DOM, so it works with any theme or page builder and behind full-page caching.

= Third-Party Services =

Olymp Tools connects to the following external services. Each connection only happens for the tool that uses it, and only once you have configured and used that tool.

**Google Reviews tool**

If you configure the Google Reviews tool, your server (never the browser) requests your business's `rating` and `userRatingCount` from the Google Places API for the Place ID you enter. Your API key is stored on your server and used only for this request.

* **Service provider:** Google LLC — Places API (New)
* **API endpoint:** [https://places.googleapis.com/v1/places/](https://places.googleapis.com/v1/places/)
* **Terms of use:** [https://cloud.google.com/maps-platform/terms](https://cloud.google.com/maps-platform/terms)
* **Privacy policy:** [https://policies.google.com/privacy](https://policies.google.com/privacy)

**Visitor Location tool**

If you enable the Visitor Location tool, your server downloads the free DB-IP Lite City database (a data file) and stores it locally. All IP-to-location lookups then happen entirely on your server — no visitor data or IP address is ever sent to DB-IP or any other third party.

* **Service provider:** DB-IP — "IP Geolocation by DB-IP", licensed CC-BY 4.0
* **Download endpoint:** [https://download.db-ip.com/free/](https://download.db-ip.com/free/)
* **Website / terms:** [https://db-ip.com/](https://db-ip.com/)

No data is sent to any of these services except as described above, and only when you configure and use the corresponding tool.

== Installation ==

1. Upload the `olymp-tools` folder to the `/wp-content/plugins/` directory, or install directly through the WordPress plugin screen.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Open the **Olymp Tools** admin menu and configure the tools you want to use.

== Frequently Asked Questions ==

= I used Olymp Tools inside SparkPlus before. Do I lose my settings? =

No. Olymp Tools was previously bundled with SparkPlus (versions 1.1.5 – 1.1.8). The standalone plugin uses exactly the same settings, so your Google Reviews credentials, Visitor Location database and AI-Image Marker settings and marks carry over. While an older SparkPlus with the bundled copy is still active, this plugin stands down and shows a notice — update SparkPlus to switch over.

= How do the Google Reviews shortcodes work? =

Enter a Google Places API key and your business's Place ID under **Olymp Tools > Google Reviews**. Then place `[olymp_google_reviews_average]` or `[olymp_google_reviews_count]` anywhere in your content. The values are fetched server-side and cached, so your API key is never exposed and the numbers keep showing even if a refresh temporarily fails.

= Is the Visitor Location tool GDPR-friendly? =

Yes. Visitor Location resolves the visitor's city/region/country entirely on your own server using a locally-stored DB-IP Lite City database. The visitor's IP address never leaves your site, and no third-party geolocation API is called at request time. Use shortcodes like `[olymp_visitor_city default="your area"]` for location-personalised copy.

== Changelog ==

= 1.0.0 =
* Olymp Tools extracted from SparkPlus into its own plugin. Includes Google Reviews, Visitor Location and AI-Image Marker, unchanged in behaviour; settings are shared with the previously bundled version.
* Own text domain `olymp-tools`.
* Safe side-by-side operation with SparkPlus 1.1.8 and older: the bundled copy is detected and used instead, with an admin notice to update SparkPlus.

== Upgrade Notice ==

= 1.0.0 =
First standalone release. Coming from SparkPlus's bundled Olymp Tools? Install this plugin and update SparkPlus — your settings are kept.
