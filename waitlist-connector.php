<?php
/**
 * Plugin Name: Waitlist Connector
 * Description: Shows a "Join the waitlist" form via the [waitlist_form] shortcode and saves entries to the Replit API.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------- Settings page: Settings > Waitlist Connector ----------

add_action( 'admin_menu', function () {
	add_options_page( 'Waitlist Connector', 'Waitlist Connector', 'manage_options', 'waitlist-connector', 'wlc_settings_page' );
} );

add_action( 'admin_init', function () {
	register_setting( 'wlc_settings', 'wlc_api_url', array( 'sanitize_callback' => 'esc_url_raw' ) );
	register_setting( 'wlc_settings', 'wlc_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
} );

function wlc_settings_page() {
	?>
	<div class="wrap">
		<h1>Waitlist Connector</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'wlc_settings' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="wlc_api_url">Replit API URL</label></th>
					<td>
						<input type="url" id="wlc_api_url" name="wlc_api_url" class="regular-text"
							placeholder="https://yourapp.replit.app"
							value="<?php echo esc_attr( get_option( 'wlc_api_url' ) ); ?>">
					</td>
				</tr>
				<tr>
					<th><label for="wlc_api_key">API Key</label></th>
					<td>
						<input type="password" id="wlc_api_key" name="wlc_api_key" class="regular-text"
							value="<?php echo esc_attr( get_option( 'wlc_api_key' ) ); ?>">
						<p class="description">Same value as the API_KEY secret in Replit.</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

// ---------- Talking to the Replit API (server side, so the key stays hidden) ----------

function wlc_api( $method, $path, $body = null ) {
	$base = rtrim( (string) get_option( 'wlc_api_url' ), '/' );
	if ( ! $base ) {
		return new WP_Error( 'wlc_no_url', 'API URL is not set (Settings > Waitlist Connector).' );
	}

	$args = array(
		'method'  => $method,
		'timeout' => 15,
		'headers' => array(
			'Content-Type' => 'application/json',
			'x-api-key'    => (string) get_option( 'wlc_api_key' ),
		),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}

	$response = wp_remote_request( $base . $path, $args );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	return array(
		'code' => wp_remote_retrieve_response_code( $response ),
		'data' => json_decode( wp_remote_retrieve_body( $response ), true ),
	);
}

// ---------- Shortcode: [waitlist_form] ----------

add_shortcode( 'waitlist_form', function () {
	$message = '';
	$type    = '';

	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['wlc_submit'] ) ) {
		if ( ! isset( $_POST['wlc_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wlc_nonce'] ), 'wlc_join' ) ) {
			$message = 'Form expired, please try again.';
			$type    = 'error';
		} else {
			$result = wlc_api( 'POST', '/api/waitlist', array(
				'name'  => sanitize_text_field( wp_unslash( $_POST['wlc_name'] ?? '' ) ),
				'email' => sanitize_email( wp_unslash( $_POST['wlc_email'] ?? '' ) ),
			) );

			if ( is_wp_error( $result ) ) {
				$message = 'Could not reach the server: ' . $result->get_error_message();
				$type    = 'error';
			} elseif ( 201 === $result['code'] ) {
				$message = 'Thank you! You are on the waitlist.';
				$type    = 'success';
			} else {
				$message = $result['data']['error'] ?? 'Something went wrong.';
				$type    = 'error';
			}
		}
	}

	$count  = null;
	$result = wlc_api( 'GET', '/api/waitlist/count' );
	if ( ! is_wp_error( $result ) && 200 === $result['code'] ) {
		$count = (int) $result['data']['count'];
	}

	ob_start();
	?>
	<div class="wlc-box">
		<?php if ( $message ) : ?>
			<p class="wlc-msg wlc-<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $message ); ?></p>
		<?php endif; ?>

		<form method="post" class="wlc-form">
			<?php wp_nonce_field( 'wlc_join', 'wlc_nonce' ); ?>
			<input type="text" name="wlc_name" placeholder="Your name" required>
			<input type="email" name="wlc_email" placeholder="Your email" required>
			<button type="submit" name="wlc_submit" value="1">Join the waitlist now</button>
		</form>

		<?php if ( null !== $count ) : ?>
			<p class="wlc-count"><?php echo esc_html( $count ); ?> people have joined so far.</p>
		<?php endif; ?>
	</div>
	<style>
		.wlc-box { max-width: 420px; margin: 2rem auto; }
		.wlc-form { display: flex; flex-direction: column; gap: .6rem; }
		.wlc-form input, .wlc-form button { padding: .7rem; font-size: 1rem; }
		.wlc-form button { cursor: pointer; }
		.wlc-msg { padding: .7rem; border-radius: 4px; }
		.wlc-success { background: #e6f6ea; color: #1d6b32; }
		.wlc-error { background: #fdeaea; color: #8a1f1f; }
		.wlc-count { text-align: center; opacity: .75; }
	</style>
	<?php
	return ob_get_clean();
} );
