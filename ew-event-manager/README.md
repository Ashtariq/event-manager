# EW Event Manager

A lightweight WordPress plugin that adds an **Events** post type, a date/location meta box, a **cached public REST endpoint**, and a shortcode.

## Overview

| Feature | Details |
|---|---|
| Custom post type | `ewem_event`, archive at `/events/` |
| Meta fields | Date (`Y-m-d`), Location (text) |
| REST API | `GET /wp-json/ewem/v1/events` |
| Shortcode | `[ewem_events per_page="5" upcoming="true"]` |
| Settings | Events > Settings (default shortcode count) |
| Caching | Transients with version-based invalidation |

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Installation

1. Copy the `ew-event-manager` folder to `wp-content/plugins/`.
2. Activate it from **Plugins** in the WordPress admin.
3. Go to **Events > Add New**, fill in the date and location, publish.

## Usage

### Shortcode

```
[ewem_events per_page="5" upcoming="true"]
```

| Attribute | Default | Description |
|---|---|---|
| `per_page` | Setting value (default `5`) | Number of events (1-50) |
| `upcoming` | `true` | Only show events from today onward |

### REST API

```
GET /wp-json/ewem/v1/events?per_page=10&upcoming=true
```

Example response:

```json
[
  {
    "id": 42,
    "title": "WordPress Meetup",
    "link": "https://example.com/events/wordpress-meetup/",
    "date": "2026-11-05",
    "location": "Montreal"
  }
]
```

## Design decisions

- **Security:** nonce and capability checks on save, input sanitised on write (`register_post_meta` sanitize callbacks), all output escaped, and `permission_callback` set explicitly (public read is intentional).
- **Performance:** results are cached in transients; `no_found_rows` skips the pagination COUNT query; term caches are not loaded because they are not used.
- **Cache invalidation:** instead of deleting many transient keys, a version number in the key is bumped whenever an event is saved, trashed, or deleted. Old entries become unreachable and expire on their own.
- **Single data source:** `Repository::get_events()` is used by both the REST endpoint and the shortcode, so they never disagree.
- **Structure:** small single-responsibility classes in the `EWEM` namespace to avoid naming collisions.

## Hooks reference

This plugin uses standard WordPress hooks: `init`, `add_meta_boxes`, `save_post_ewem_event`, `rest_api_init`, `trashed_post`, `deleted_post`.

## Testing

```bash
composer require --dev wp-coding-standards/wpcs
vendor/bin/phpcs --standard=WordPress ew-event-manager.php
```

Manual checks:
1. Create an event, then call `/wp-json/ewem/v1/events` and confirm it appears.
2. Edit the event and confirm the endpoint updates (cache invalidation).
3. Enter an invalid date and confirm it is rejected (saved as empty).

## Changelog

### 1.1.0
- Added settings page (Events > Settings) for default shortcode count.
- Added uninstall cleanup.

### 1.0.0
- Initial release.

## License

GPL-2.0-or-later
