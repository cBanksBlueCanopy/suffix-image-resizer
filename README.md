# Suffix Image Resizer

| | |
|---|---|
| **Version** | 1.2.0 |
| **License** | GPL-2.0-or-later |
| **File** | `suffix-image-resizer.php` |
| **Settings page** | Settings → Suffix Image Resizer |
| **Required capability** | `manage_options` |

Automatically resizes images whose **filename ends with a configured string** (for example `BottleImage.webp`). Rules are applied to new uploads as they arrive and can be applied in bulk to images already in the Media Library. Three modes are supported: **fit**, **crop**, and **pad** (centers the image on a fixed-size canvas, transparent where the format allows).

---

## Contents

- [How it works](#how-it-works)
- [Installation](#installation)
- [Rule syntax](#rule-syntax)
- [Resize modes](#resize-modes)
- [Examples](#examples)
- [Resizing existing images](#resizing-existing-images)
- [Behavior and limits](#behavior-and-limits)
- [Developer reference](#developer-reference)
- [Security](#security)
- [Troubleshooting](#troubleshooting)

---

## How it works

1. You define one or more rules, one per line, in the settings page.
2. **On upload:** the `wp_handle_upload` filter checks each uploaded image's filename against the rules. On a match, the file is resized in place before WordPress generates its thumbnails.
3. **On existing images:** a button on the settings page scans the Media Library, finds every image whose filename matches a rule, resizes it, deletes its old generated thumbnails, and regenerates the attachment metadata.

If a resize fails on upload, the original upload is left untouched.

## Installation

1. Copy `suffix-image-resizer.php` into `wp-content/plugins/suffix-image-resizer/`.
2. Activate **Suffix Image Resizer** in **Plugins**.
3. Go to **Settings → Suffix Image Resizer** and review the rules.

A default rule is provided: `BottleImage.webp|800|800|pad`.

## Rule syntax

One rule per line:

```
suffix|width|height|mode|background
```

| Field | Required | Description |
|---|---|---|
| `suffix` | Yes | End of the filename, case-insensitive. Matches either the full filename (`BottleImage.webp`) or the name without its extension (`BottleImage`). |
| `width` | One of width/height | Target width in pixels. `0` means auto in fit mode. |
| `height` | One of width/height | Target height in pixels. `0` means auto in fit mode. |
| `mode` | No | `fit` (default), `crop`, or `pad`. Also accepts `contain` (alias for `pad`) and legacy values `0` (fit) and `1` (crop). |
| `background` | No | Pad mode only. `transparent` (default) or a hex color such as `#ffffff` or `fff`. |

Additional notes:

- Lines that are blank or start with `#` are ignored.
- The **first matching rule wins**, in the order listed.
- Rules with an empty suffix, or with both width and height at `0`, are skipped.
- `crop` and `pad` need **both** dimensions. If either is `0`, the rule silently falls back to `fit`.
- An unrecognized mode falls back to `fit`. An invalid background value falls back to `transparent`.

## Resize modes

| Mode | Behavior |
|---|---|
| **fit** | Shrinks the image to fit inside the width × height box, preserving aspect ratio. Final size may be smaller than the box in one dimension. |
| **crop** | Center-crops to the target aspect ratio. Edges are cut off. If the source is smaller than the target, the crop is scaled down to preserve the ratio rather than enlarging. |
| **pad** | Shrinks the whole image to fit inside the box, then centers it on a canvas of exactly width × height. Nothing is cut off. |

### Pad mode and transparency

| Format | Background behavior |
|---|---|
| WebP, PNG, GIF | Transparent if `background` is `transparent`; otherwise the chosen color. |
| JPEG | No alpha channel, so `transparent` falls back to **white**. A hex color is used as given. |

Pad rendering uses **Imagick** when available and falls back to **GD**. If neither can process the file, an error is returned (and, on upload, the file is left as-is).

## Examples

```
# Square, transparent padding
BottleImage.webp|800|800|pad

# Square, white padding
LabelImage|1000|1000|pad|#ffffff

# Max 1200px wide, any height, keep proportions
-hero.jpg|1200|0|fit

# Center-cropped 16:9 banner
BannerImage|1600|900|crop
```

## Resizing existing images

On the settings page, the **Resize existing images** section applies the **saved** rules (save before running) to the Media Library.

- **Back up originals** (checked by default) copies each file to `wp-content/uploads/sir-backups/` before it is modified. Existing backups are never overwritten, so the first original is preserved across repeated runs.
- Images are processed in **batches of 3** over AJAX, with a live log showing each result (resized with before → after dimensions, skipped, or failed).
- Images already at the target size are skipped, so the tool is **safe to run repeatedly**.
- After resizing, previously generated thumbnails are deleted and metadata is regenerated from the new file. The `original_image` metadata key, if present, is preserved.

## Behavior and limits

- **Images are never enlarged.** In pad mode, a smaller image is centered on the full canvas at its original size, so the *canvas* reaches the target size but the image itself is not scaled up.
- Files are modified **in place**. Without the backup option, the original cannot be recovered from the plugin.
- Quality for re-encoded images uses WordPress's `wp_editor_set_quality` filter (default `82` here).
- The backup directory is not cleaned up automatically.
- Matching is based on the filename only, not on the attachment title or metadata.
- Settings are stored as a single option and are **not removed on deactivation or deletion**; there is no `uninstall.php`.

## Developer reference

| Item | Value |
|---|---|
| Class | `Suffix_Image_Resizer` |
| Option name | `sir_rules` |
| Settings group | `sir_group` |
| Default rules | `BottleImage.webp\|800\|800\|pad` |
| Upload hook | `wp_handle_upload` (priority 10) |
| Admin hooks | `admin_menu`, `admin_init` |
| AJAX actions | `wp_ajax_sir_scan`, `wp_ajax_sir_process` |
| Nonce action | `sir_bulk` |
| Backup path | `{uploads basedir}/sir-backups/{_wp_attached_file}` |

### Key methods

| Method | Purpose |
|---|---|
| `get_rules()` | Parses the option text into a validated rule array (`suffix`, `w`, `h`, `mode`, `bg`). |
| `find_rule( $filename )` | Returns the first rule whose suffix matches the filename or the extensionless name. |
| `resize_file( $file, $rule, $backup_to )` | Core resize. Returns `true` (changed), `false` (already correct, skipped), or `WP_Error`. |
| `pad_file()` | Shrinks to fit, then delegates to `extend_canvas()`. |
| `extend_canvas()` | Centers the image on a new canvas using Imagick, then GD as a fallback. |
| `maybe_resize_upload( $upload )` | Upload filter callback; only acts on `image/*` uploads. |
| `ajax_scan()` | Returns attachment IDs of images matching any rule. |
| `ajax_process()` | Processes a batch of attachment IDs. |
| `process_attachment( $id, $backup )` | Resizes one attachment and regenerates its thumbnails and metadata. |

### Filters used

- `wp_editor_set_quality`: read to determine output quality when padding.

## Security

- Both AJAX endpoints verify the `sir_bulk` nonce and require `manage_options`.
- Attachment IDs from the request are cast with `absint`.
- The rules option is sanitized with `sanitize_textarea_field` and escaped on output (`esc_textarea`, `esc_attr`).
- Log output in the admin script is inserted as text (`.text()`), not HTML.
- The scan query uses only static, hard-coded SQL with no user input.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Nothing happens on upload | Filename doesn't end with a configured suffix, or the rule is invalid (both dimensions `0`, empty suffix). |
| "No valid rules saved" on scan | Rules haven't been saved, or all lines are comments or invalid. |
| Crop or pad behaves like fit | One dimension is `0`; these modes require both. |
| JPEG has white instead of transparent padding | Expected; JPEG doesn't support transparency. |
| "Neither Imagick nor GD is available" | Enable the Imagick or GD PHP extension on the server. |
| "Could not save the padded image" | The server's GD build lacks support for the file's format (e.g. WebP). |
| Bulk run says "already the right size" | The image already matches the target (fit/crop) or the exact canvas size (pad). |