# Infinite Monkeys Search

Fast live search for WooCommerce and WordPress content, built by An Infinite Number of Monkeys to replace FiboSearch on Prologue. Private plugin, internal use only.

- Custom search index (not WordPress's LIKE search), updated in real time
- Fuzzy matching (typo tolerance), stopwords and synonyms
- Searches titles, descriptions, SKUs (including variations), categories, tags, brands, custom taxonomies and custom fields
- Two Bricks Builder elements (Search bar, Search results) and three shortcodes
- Results grouped by type, with images, prices (Currency Converter aware), SKUs, excerpts and an optional "View" button
- The site's search results page (your Bricks search template) uses the same index

## Requirements

- WordPress 6.4+, PHP 7.4+
- WooCommerce (optional; needed for products, SKUs and prices)
- Bricks Builder (optional; needed for the elements)

## Install

1. Download the repository as a ZIP, or build one (see below). The ZIP must contain a single folder named `infinite-monkeys-search`.
2. In WordPress: Plugins > Add New > Upload Plugin, choose the ZIP, then Activate.
3. Activation creates the index tables and builds the index in the background. Check progress under **Search** in the admin menu.

## Automatic updates

Sites running the plugin get normal WordPress update notices whenever a new GitHub release is published (Plugin Update Checker, bundled in `vendor/plugin-update-checker`). Plugins > Installed Plugins also gets a "Check for updates" link.

Because the repository is private, each site needs a read-only GitHub token. Use one of these:

- **Per site (recommended):** add `define( 'IMSEARCH_GITHUB_TOKEN', 'github_pat_...' );` to the site's `wp-config.php`.
- **Built in:** paste the token into `FALLBACK_READONLY_TOKEN` in `includes/class-imsearch-updater.php`. The token then ships with the plugin to every site, so give it read-only **Contents** access to this repository only.

Create the token at GitHub > Settings > Developer settings > Personal access tokens > Fine-grained tokens. Set Repository access to "Only select repositories" (this one) and Permissions > Contents to "Read-only".

To publish an update:

1. Bump the version.
2. Create a release tagged `vX.Y.Z`.
3. Attach the plugin ZIP to the release.

## Build a release ZIP

From the folder that contains the `infinite-monkeys-search` folder:

```
zip -r infinite-monkeys-search.zip infinite-monkeys-search -x "*/.git/*" "*/.gitignore" "*/.DS_Store"
```

Before releasing, bump the version in two places in `infinite-monkeys-search.php`: the `Version:` header and the `IMSEARCH_VERSION` constant. The constant is also the CSS/JS cache-buster.

## Usage

### Bricks

Add the **Search bar** and **Search results** elements (category: Infinite Monkeys Search). The results element connects to the nearest Search bar before it, or to the bar whose CSS ID you enter in "Search bar ID".

### Shortcodes

| Shortcode | Purpose |
|---|---|
| `[imsearch]` | Search bar with a results dropdown |
| `[imsearch_bar id="main-search"]` | Search bar only |
| `[imsearch_results for="main-search"]` | Results only, for the bar with that id |

The full list of shortcode attributes is on the **Search** settings page.

### Settings (admin menu > Search)

- **Configuration**: exclude out-of-stock products, excluded terms, fuzzy level, minimum characters, stopwords, synonyms, custom fields to index, use the index for the search results page
- **Autocomplete**: maximum suggestions per group, "No results" and "More results" labels
- **Indexing**: scheduled rebuild (off, daily, weekly, twice a month, monthly), status and "Re-index now"

## How it works

| Part | File |
|---|---|
| Index tables (`wp_imsearch_docs`, `wp_imsearch_words`, `wp_imsearch_postings`) | `includes/class-imsearch-db.php` |
| Indexer: full rebuilds into shadow tables then an atomic swap; real-time updates on `save_post`, `deleted_post`, `created_term`, `edited_term`, `delete_term` and WooCommerce/Bricks hooks | `includes/class-imsearch-indexer.php` |
| Search engine: prefix matching, FiboSearch-style fuzzy matching (Levenshtein), synonyms, stopwords, field weighting | `includes/class-imsearch-engine.php` |
| Fast endpoint: loads WordPress with `SHORTINIT` (database only), read-only | `endpoint/search.php` |
| REST fallback `/wp-json/imsearch/v1/search`, shortcodes, search results page integration | `includes/class-imsearch-frontend.php` |
| Bricks elements | `elements/` |
| Front end (jQuery) and styles, all classes prefixed `imsearch-` | `assets/` |

If the host blocks PHP files inside plugin folders, live search falls back to the REST endpoint automatically. The settings page shows a notice when that happens.

## Security notes

- The fast endpoint is read-only and returns only published, non-password-protected content that the indexer stored. Custom field values are searched but never returned.
- Every input is checked against a whitelist. Every value that reaches SQL goes through `$wpdb->prepare()` or is cast to an integer.
- Results are built in the browser with DOM methods and text nodes. HTML strings from the server are never inserted.
- Admin actions check `manage_options` and a nonce.

## Uninstall

Deleting the plugin from WordPress removes its tables, options and scheduled events.

## Changelog

- **1.0.7**: The price is now inside `.imsearch-result-body` (after the title row, before the excerpt); `.imsearch-result-aside` holds only the View button. Removed the "Button position" and "Space between price and button" controls.
- **1.0.6**: Wrapper `div.imsearch-result-content` around each result's text (`.imsearch-result-body`) and price/button (`.imsearch-result-aside`), with direction, alignment and gap controls in the Search results element
- **1.0.5**: Automatic updates from GitHub releases (Plugin Update Checker)
- **1.0.4**: "View" button option for results (label, styling, position)
- **1.0.3**: Option to hide group headings
- **1.0.2**: Custom field picker lists all fields; fields chosen in a Search bar are indexed automatically
- **1.0.1**: Escape closes results before the surrounding offcanvas; search page integration limited to front-end queries
- **1.0.0**: First release
