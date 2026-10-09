<?php
/**
 * Plugin Name: Suffix Image Resizer
 * Description: Resizes images whose filename ends with a configured string (e.g. "BottleImage.webp"), on upload and for images already in the media library. Supports fit, crop, and pad (transparent canvas) modes.
 * Version:     1.2.0
 * Author:      You
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Suffix_Image_Resizer {

	const OPTION  = 'sir_rules';
	const DEFAULT = "BottleImage.webp|800|800|pad";

	public function __construct() {
		add_filter( 'wp_handle_upload', array( $this, 'maybe_resize_upload' ), 10, 1 );

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'wp_ajax_sir_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_sir_process', array( $this, 'ajax_process' ) );
	}

	/* ---------------------------------------------------------------
	 * Rules
	 * One per line: suffix|width|height|mode|background
	 *   mode:       fit (default) | crop | pad
	 *   background: only used by pad. "transparent" (default) or a hex like #ffffff
	 * Legacy: 0 = fit, 1 = crop.
	 * ------------------------------------------------------------- */

	private function get_rules() {
		$rules = array();
		$raw   = (string) get_option( self::OPTION, self::DEFAULT );

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$parts  = array_map( 'trim', explode( '|', $line ) );
			$suffix = $parts[0];
			$w      = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
			$h      = isset( $parts[2] ) ? absint( $parts[2] ) : 0;

			$m = strtolower( $parts[3] ?? 'fit' );
			if ( in_array( $m, array( '1', 'crop' ), true ) ) {
				$mode = 'crop';
			} elseif ( in_array( $m, array( 'pad', 'contain' ), true ) ) {
				$mode = 'pad';
			} else {
				$mode = 'fit';
			}

			$bg = 'transparent';
			if ( isset( $parts[4] ) && preg_match( '/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', $parts[4], $mm ) ) {
				$bg = '#' . strtolower( $mm[1] );
			}

			if ( '' === $suffix || ( 0 === $w && 0 === $h ) ) {
				continue;
			}
			// Crop and pad need both dimensions to define a shape.
			if ( 'fit' !== $mode && ( ! $w || ! $h ) ) {
				$mode = 'fit';
			}
			$rules[] = compact( 'suffix', 'w', 'h', 'mode', 'bg' );
		}
		return $rules;
	}

	/** Match the full filename ("X-BottleImage.webp") or the name without extension, case-insensitive. */
	private function find_rule( $filename ) {
		$full = strtolower( $filename );
		$base = strtolower( pathinfo( $filename, PATHINFO_FILENAME ) );

		foreach ( $this->get_rules() as $rule ) {
			$s   = strtolower( $rule['suffix'] );
			$len = strlen( $s );
			if ( $len && ( substr( $full, -$len ) === $s || substr( $base, -$len ) === $s ) ) {
				return $rule;
			}
		}
		return null;
	}

	/* ---------------------------------------------------------------
	 * Core resize
	 * ------------------------------------------------------------- */

	private function backup( $file, $backup_to ) {
		if ( $backup_to && ! file_exists( $backup_to ) ) {
			wp_mkdir_p( dirname( $backup_to ) );
			copy( $file, $backup_to );
		}
	}

	/**
	 * Resize a file in place.
	 * Returns true (changed), false (already correct, skipped) or WP_Error.
	 * Never upscales the image itself. If $backup_to is given, the original is copied there first.
	 */
	private function resize_file( $file, $rule, $backup_to = null ) {
		if ( 'pad' === $rule['mode'] ) {
			return $this->pad_file( $file, $rule, $backup_to );
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$size = $editor->get_size();
		$ow   = $size['width'];
		$oh   = $size['height'];
		$tw   = $rule['w'];
		$th   = $rule['h'];

		if ( 'crop' === $rule['mode'] ) {
			// Center-crop to the target ratio, scaled down if the source is too small.
			$scale = min( 1, $ow / $tw, $oh / $th );
			$fw    = (int) round( $tw * $scale );
			$fh    = (int) round( $th * $scale );
			if ( abs( $ow - $fw ) <= 1 && abs( $oh - $fh ) <= 1 ) {
				return false;
			}
			$result = $editor->resize( $fw, $fh, true );
		} else {
			if ( ( ! $tw || $ow <= $tw ) && ( ! $th || $oh <= $th ) ) {
				return false;
			}
			$result = $editor->resize( $tw ?: null, $th ?: null, false );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->backup( $file, $backup_to );
		$saved = $editor->save( $file );
		return is_wp_error( $saved ) ? $saved : true;
	}

	/**
	 * Pad mode: shrink the whole image to fit inside w×h (never enlarged),
	 * then center it on a w×h canvas. No part of the image is cut off.
	 */
	private function pad_file( $file, $rule, $backup_to ) {
		$info = @getimagesize( $file );
		if ( ! $info ) {
			return new WP_Error( 'sir_size', 'Could not read image size.' );
		}
		$ow = (int) $info[0];
		$oh = (int) $info[1];
		$tw = $rule['w'];
		$th = $rule['h'];

		if ( $ow === $tw && $oh === $th ) {
			return false; // already the target canvas size
		}

		$this->backup( $file, $backup_to );

		// Step 1: shrink to fit if larger than the canvas.
		$scale = min( 1, $tw / $ow, $th / $oh );
		if ( $scale < 1 ) {
			$editor = wp_get_image_editor( $file );
			if ( is_wp_error( $editor ) ) {
				return $editor;
			}
			$r = $editor->resize(
				max( 1, (int) round( $ow * $scale ) ),
				max( 1, (int) round( $oh * $scale ) ),
				false
			);
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$saved = $editor->save( $file );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		// Step 2: place on the canvas.
		return $this->extend_canvas( $file, $tw, $th, $rule['bg'] );
	}

	private function hex_to_rgb( $hex ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	/** Center the image file on a $cw×$ch canvas. Transparent where the format allows it. */
	private function extend_canvas( $file, $cw, $ch, $bg ) {
		$ft   = wp_check_filetype( $file );
		$mime = (string) $ft['type'];

		// JPEG has no alpha channel, so fall back to white there.
		$can_alpha   = in_array( $mime, array( 'image/webp', 'image/png', 'image/gif' ), true );
		$transparent = ( 'transparent' === $bg ) && $can_alpha;
		$hex         = ( 'transparent' === $bg ) ? '#ffffff' : $bg;

		$info = @getimagesize( $file );
		if ( ! $info ) {
			return new WP_Error( 'sir_size', 'Could not read image size.' );
		}
		$iw      = (int) $info[0];
		$ih      = (int) $info[1];
		$dx      = (int) floor( ( $cw - $iw ) / 2 );
		$dy      = (int) floor( ( $ch - $ih ) / 2 );
		$quality = (int) apply_filters( 'wp_editor_set_quality', 82, $mime );

		// Imagick first.
		if ( class_exists( 'Imagick' ) ) {
			try {
				$img    = new Imagick( $file );
				$canvas = new Imagick();
				$canvas->newImage( $cw, $ch, new ImagickPixel( $transparent ? 'none' : $hex ) );
				$canvas->setImageFormat( $img->getImageFormat() );
				$canvas->setImageAlphaChannel( Imagick::ALPHACHANNEL_ACTIVATE );
				$canvas->compositeImage( $img, Imagick::COMPOSITE_OVER, $dx, $dy );
				$canvas->setImageCompressionQuality( $quality );
				$canvas->writeImage( $file );
				$img->clear();
				$canvas->clear();
				return true;
			} catch ( Exception $e ) {
				// fall through to GD
			}
		}

		// GD fallback.
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return new WP_Error( 'sir_gd', 'Neither Imagick nor GD is available.' );
		}
		$src = @imagecreatefromstring( (string) file_get_contents( $file ) );
		if ( ! $src ) {
			return new WP_Error( 'sir_gd', 'GD could not read the image.' );
		}
		$canvas = imagecreatetruecolor( $cw, $ch );
		if ( $transparent ) {
			imagealphablending( $canvas, false );
			imagesavealpha( $canvas, true );
			$fill = imagecolorallocatealpha( $canvas, 0, 0, 0, 127 );
		} else {
			list( $r, $g, $b ) = $this->hex_to_rgb( $hex );
			$fill              = imagecolorallocate( $canvas, $r, $g, $b );
		}
		imagefill( $canvas, 0, 0, $fill );
		imagecopy( $canvas, $src, $dx, $dy, 0, 0, $iw, $ih );

		switch ( $mime ) {
			case 'image/webp':
				$ok = function_exists( 'imagewebp' ) && imagewebp( $canvas, $file, $quality );
				break;
			case 'image/png':
				$ok = imagepng( $canvas, $file );
				break;
			case 'image/jpeg':
				$ok = imagejpeg( $canvas, $file, $quality );
				break;
			case 'image/gif':
				$ok = imagegif( $canvas, $file );
				break;
			default:
				$ok = false;
		}
		imagedestroy( $src );
		imagedestroy( $canvas );

		return $ok ? true : new WP_Error( 'sir_save', 'Could not save the padded image.' );
	}

	/* ---------------------------------------------------------------
	 * New uploads
	 * ------------------------------------------------------------- */

	public function maybe_resize_upload( $upload ) {
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}
		if ( 0 !== strpos( $upload['type'], 'image/' ) ) {
			return $upload;
		}
		$rule = $this->find_rule( wp_basename( $upload['file'] ) );
		if ( $rule ) {
			$this->resize_file( $upload['file'], $rule ); // failures leave the upload untouched
		}
		return $upload;
	}

	/* ---------------------------------------------------------------
	 * Existing media library images (AJAX, batched)
	 * ------------------------------------------------------------- */

	public function ajax_scan() {
		check_ajax_referer( 'sir_bulk', '_wpnonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Not allowed.' );
		}
		if ( ! $this->get_rules() ) {
			wp_send_json_error( 'No valid rules saved. Save your rules first.' );
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_wp_attached_file'
			   AND p.post_type = 'attachment'
			   AND p.post_mime_type LIKE 'image/%'"
		);

		$ids = array();
		foreach ( $rows as $row ) {
			if ( $this->find_rule( wp_basename( $row->meta_value ) ) ) {
				$ids[] = (int) $row->post_id;
			}
		}
		wp_send_json_success( array( 'ids' => $ids ) );
	}

	public function ajax_process() {
		check_ajax_referer( 'sir_bulk', '_wpnonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Not allowed.' );
		}

		$ids    = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
		$backup = ! empty( $_POST['backup'] );
		$out    = array();

		foreach ( $ids as $id ) {
			$out[] = $this->process_attachment( $id, $backup );
		}
		wp_send_json_success( $out );
	}

	private function process_attachment( $id, $backup ) {
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return "#$id: file missing, skipped";
		}
		$name = wp_basename( $file );
		$rule = $this->find_rule( $name );
		if ( ! $rule ) {
			return "$name: no matching rule, skipped";
		}

		$before = @getimagesize( $file );

		$backup_to = null;
		if ( $backup ) {
			$uploads   = wp_get_upload_dir();
			$backup_to = trailingslashit( $uploads['basedir'] ) . 'sir-backups/' . get_post_meta( $id, '_wp_attached_file', true );
		}

		$result = $this->resize_file( $file, $rule, $backup_to );
		if ( is_wp_error( $result ) ) {
			return "$name: failed (" . $result->get_error_message() . ')';
		}
		if ( false === $result ) {
			return "$name: already the right size, skipped";
		}

		// Regenerate thumbnails/metadata from the new original.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$old = wp_get_attachment_metadata( $id );
		if ( ! empty( $old['sizes'] ) ) {
			$dir = dirname( $file );
			foreach ( $old['sizes'] as $s ) {
				if ( ! empty( $s['file'] ) ) {
					$p = $dir . '/' . $s['file'];
					if ( $p !== $file && file_exists( $p ) ) {
						@unlink( $p );
					}
				}
			}
		}
		$new = wp_generate_attachment_metadata( $id, $file );
		if ( ! empty( $old['original_image'] ) ) {
			$new['original_image'] = $old['original_image'];
		}
		wp_update_attachment_metadata( $id, $new );

		$after = @getimagesize( $file );
		$from  = $before ? "{$before[0]}x{$before[1]}" : '?';
		$to    = $after ? "{$after[0]}x{$after[1]}" : '?';
		return "$name: resized $from → $to";
	}

	/* ---------------------------------------------------------------
	 * Settings page
	 * ------------------------------------------------------------- */

	public function register_setting() {
		register_setting( 'sir_group', self::OPTION, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => self::DEFAULT,
		) );
	}

	public function add_menu() {
		add_options_page( 'Suffix Image Resizer', 'Suffix Image Resizer', 'manage_options', 'suffix-image-resizer', array( $this, 'render_page' ) );
	}

	public function render_page() {
		$nonce = wp_create_nonce( 'sir_bulk' );
		?>
		<div class="wrap">
			<h1>Suffix Image Resizer</h1>

			<h2>Rules</h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'sir_group' ); ?>
				<p>One rule per line: <code>suffix|width|height|mode|background</code></p>
				<textarea name="<?php echo esc_attr( self::OPTION ); ?>" rows="6" class="large-text code"><?php echo esc_textarea( get_option( self::OPTION, self::DEFAULT ) ); ?></textarea>
				<p class="description">
					<strong>suffix</strong>: end of the filename, case-insensitive. <code>BottleImage.webp</code> and <code>BottleImage</code> both work.<br>
					<strong>mode</strong>:
					<code>fit</code> shrink to fit inside the box, keeping the original shape (default);
					<code>crop</code> center-crop to the width:height shape (cuts off edges);
					<code>pad</code> shrink to fit, then center on a width×height canvas, <em>nothing cut off</em>.<br>
					<strong>background</strong> (pad only): <code>transparent</code> (default) or a hex color like <code>#ffffff</code>. JPEG can't be transparent, so JPEGs get white.<br>
					Use <code>0</code> for width or height in fit mode to auto-scale. Images are never enlarged.<br>
					Example, square with transparent padding: <code>BottleImage.webp|800|800|pad</code><br>
					Lines starting with <code>#</code> are ignored. New uploads are resized automatically.
				</p>
				<?php submit_button( 'Save rules' ); ?>
			</form>

			<hr>
			<h2>Resize existing images</h2>
			<p>Applies the <strong>saved</strong> rules to images already in the media library and regenerates their thumbnails. Images already at the target size are skipped, so it is safe to run more than once.</p>
			<p>
				<label>
					<input type="checkbox" id="sir-backup" checked>
					Back up originals to <code>uploads/sir-backups/</code> before resizing
				</label>
			</p>
			<p><button type="button" class="button button-primary" id="sir-run">Resize existing images</button></p>
			<div id="sir-log" style="max-height:300px;overflow:auto;background:#fff;border:1px solid #c3c4c7;padding:8px 12px;font-family:monospace;display:none"></div>
		</div>

		<script>
		(function ($) {
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var $btn = $('#sir-run'), $log = $('#sir-log'), BATCH = 3;

			function log(msg) {
				$log.show().append($('<div>').text(msg));
				$log.scrollTop($log[0].scrollHeight);
			}

			$btn.on('click', function () {
				$btn.prop('disabled', true);
				$log.empty();
				log('Scanning media library…');

				$.post(ajaxurl, { action: 'sir_scan', _wpnonce: nonce }).done(function (r) {
					if (!r.success) { log('Error: ' + r.data); $btn.prop('disabled', false); return; }
					var ids = r.data.ids, i = 0;
					log('Found ' + ids.length + ' matching image(s).');

					(function next() {
						if (i >= ids.length) { log('Done.'); $btn.prop('disabled', false); return; }
						var chunk = ids.slice(i, i + BATCH);
						i += BATCH;
						$.post(ajaxurl, {
							action: 'sir_process',
							_wpnonce: nonce,
							ids: chunk,
							backup: $('#sir-backup').is(':checked') ? 1 : 0
						}).done(function (r) {
							if (r.success) { r.data.forEach(log); } else { log('Error: ' + r.data); }
						}).fail(function () {
							log('Request failed for IDs ' + chunk.join(', '));
						}).always(next);
					})();
				}).fail(function () {
					log('Scan request failed.');
					$btn.prop('disabled', false);
				});
			});
		})(jQuery);
		</script>
		<?php
	}
}

new Suffix_Image_Resizer();