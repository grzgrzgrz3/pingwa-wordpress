<?php
/**
 * Settings → pingwa: how to get a hook URL (the `join wp` link and its QR), the hook URL
 * itself, the trigger toggles and "Send test".
 *
 * The hook URL is the only thing stored (option, not autoloaded). Every action needs
 * manage_options and its own nonce.
 *
 * @package pingwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin page.
 */
final class Pingwa_Settings {

	/**
	 * Option holding the hook URL.
	 */
	const OPTION_URL = 'pingwa_hook_url';

	/**
	 * Admin page slug.
	 */
	const PAGE = 'pingwa';

	/**
	 * Capability for everything on the page.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * The saved hook URL, or "" when none (or an invalid one) is saved.
	 *
	 * @return string
	 */
	public static function hook_url() {
		return Pingwa_Hook_Url::sanitize( get_option( self::OPTION_URL, '' ) );
	}

	/**
	 * Hook the page and its actions.
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_pingwa_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_pingwa_forget', array( __CLASS__, 'handle_forget' ) );
		add_action( 'admin_post_pingwa_test', array( __CLASS__, 'handle_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PINGWA_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Settings → pingwa.
	 */
	public static function menu() {
		add_options_page(
			__( 'pingwa', 'pingwa' ),
			__( 'pingwa', 'pingwa' ),
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * A "Settings" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Settings', 'pingwa' ) )
		);
		return $links;
	}

	/**
	 * The page URL.
	 *
	 * @return string
	 */
	private static function page_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	/**
	 * Stop unless the current user may manage options. Each handler then checks its own
	 * nonce with check_admin_referer().
	 */
	private static function require_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'pingwa' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Save the hook URL (when one is pasted) and the toggles.
	 */
	public static function handle_save() {
		self::require_capability();
		check_admin_referer( 'pingwa_save' );

		$raw = isset( $_POST['pingwa_hook_url'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['pingwa_hook_url'] ) ) ) : '';
		$bad = false;
		if ( '' !== $raw ) {
			$url = Pingwa_Hook_Url::sanitize( $raw );
			if ( '' === $url ) {
				$bad = true;
			} else {
				update_option( self::OPTION_URL, $url, false );
			}
		}

		$saved = get_option( Pingwa_Triggers::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		foreach ( array_keys( Pingwa_Triggers::names() ) as $key ) {
			if ( Pingwa_Triggers::is_active( $key ) ) {
				$saved[ $key ] = isset( $_POST['pingwa_triggers'][ $key ] ) ? '1' : '0';
			}
		}
		update_option( Pingwa_Triggers::OPTION, $saved, false );

		if ( $bad ) {
			self::notice( 'error', __( 'That is not a pingwa hook URL. It starts with https:// and ends in /h/hk_ followed by 32 characters. The toggles are saved; the hook URL is unchanged.', 'pingwa' ) );
		} else {
			self::notice( 'success', __( 'Settings saved.', 'pingwa' ) );
		}
		self::back();
	}

	/**
	 * Forget the hook URL.
	 */
	public static function handle_forget() {
		self::require_capability();
		check_admin_referer( 'pingwa_forget' );
		delete_option( self::OPTION_URL );
		self::notice( 'success', __( 'The hook URL is removed from this site. Nothing is sent until you paste a new one. To stop the URL itself, revoke the hook in the pingwa panel.', 'pingwa' ) );
		self::back();
	}

	/**
	 * Post a test body and report the server's answer.
	 */
	public static function handle_test() {
		self::require_capability();
		check_admin_referer( 'pingwa_test' );
		if ( '' === self::hook_url() ) {
			self::notice( 'error', __( 'Save a hook URL first.', 'pingwa' ) );
			self::back();
		}
		$response = Pingwa_Sender::post( Pingwa_Payload::test( Pingwa_Triggers::site() ), true );
		if ( is_wp_error( $response ) ) {
			self::notice(
				'error',
				sprintf(
					/* translators: %s: the error WordPress reported. */
					__( 'WordPress could not reach the hook URL: %s', 'pingwa' ),
					str_replace( self::hook_url(), Pingwa_Hook_Url::mask( self::hook_url() ), $response->get_error_message() )
				)
			);
		} else {
			$summary = self::test_summary( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
			self::notice( $summary['type'], $summary['message'] );
		}
		self::back();
	}

	/**
	 * What the test's answer means: notice type and message.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Response body.
	 * @return array{type: string, message: string}
	 */
	public static function test_summary( $code, $body ) {
		$json = json_decode( $body, true );
		if ( 200 === $code && is_array( $json ) && isset( $json['accepted'] ) ) {
			if ( true === $json['accepted'] ) {
				return array(
					'type'    => 'success',
					'message' => __( 'pingwa answered HTTP 200: accepted. The test message is on its way to your WhatsApp.', 'pingwa' ),
				);
			}
			$error = isset( $json['error'] ) && is_string( $json['error'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( $json['error'] ) ) : '';
			return array(
				'type'    => 'warning',
				'message' => sprintf(
					/* translators: %s: pingwa's refusal code, e.g. quota_exceeded. */
					__( 'pingwa answered HTTP 200 but did not send the message: %s.', 'pingwa' ),
					'' === $error ? 'unknown' : substr( $error, 0, 40 )
				),
			);
		}
		if ( 404 === $code ) {
			return array(
				'type'    => 'error',
				'message' => __( 'pingwa answered HTTP 404: this hook URL is unknown or was revoked. Make a new hook in the pingwa panel and paste its URL.', 'pingwa' ),
			);
		}
		return array(
			'type'    => 200 === $code ? 'warning' : 'error',
			'message' => sprintf(
				/* translators: %d: HTTP status code. */
				__( 'pingwa answered HTTP %d.', 'pingwa' ),
				$code
			),
		);
	}

	/**
	 * Keep a notice for the current user's next page view.
	 *
	 * @param string $type    success, warning or error.
	 * @param string $message Message.
	 */
	private static function notice( $type, $message ) {
		set_transient(
			'pingwa_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Back to the page.
	 */
	private static function back() {
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * The page.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$key    = 'pingwa_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );
		$url    = self::hook_url();
		$saved  = get_option( Pingwa_Triggers::OPTION, array() );
		$server = PINGWA_SERVER_URL;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'pingwa', 'pingwa' ); ?></h1>
			<?php if ( is_array( $notice ) && isset( $notice['type'], $notice['message'] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( in_array( $notice['type'], array( 'success', 'warning', 'error' ), true ) ? $notice['type'] : 'info' ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'WhatsApp alerts from your forms and your shop, to you and your team. On the official WhatsApp API: no Meta setup, and your own number is never at risk.', 'pingwa' ); ?></p>

			<h2><?php esc_html_e( '1. Get a hook URL', 'pingwa' ); ?></h2>
			<div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start">
				<ol style="max-width:46em;margin-top:0">
					<li>
						<?php
						printf(
							/* translators: %s: the WhatsApp command, "join wp". */
							esc_html__( 'Text %s to pingwa on WhatsApp: scan the code or tap the button. Your API key comes straight back, and your phone is the one that gets the messages.', 'pingwa' ),
							'<strong>join wp</strong>'
						);
						?>
						<br><a class="button button-primary" style="margin-top:6px" href="<?php echo esc_url( PINGWA_JOIN_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Send “join wp” on WhatsApp', 'pingwa' ); ?></a>
					</li>
					<li>
						<?php
						printf(
							/* translators: 1: link to the pingwa panel, 2: "Custom — any other sender". */
							esc_html__( 'In the %1$s open Hooks → + new hook, set Sender to %2$s, give it a name (for example wordpress) and click create hook. Copy the URL: it is shown only once.', 'pingwa' ),
							'<a href="' . esc_url( $server . '/app' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'pingwa panel', 'pingwa' ) . '</a>',
							'<strong>' . esc_html__( '“Custom — any other sender”', 'pingwa' ) . '</strong>'
						);
						?>
						<?php
						printf(
							/* translators: %s: example hook URL. */
							esc_html__( 'It looks like %s.', 'pingwa' ),
							'<code>' . esc_html( $server . '/h/hk_…' ) . '</code>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Paste it below, save, and press Send test.', 'pingwa' ); ?></li>
				</ol>
				<figure style="margin:0;text-align:center">
					<img src="<?php echo esc_url( plugins_url( 'assets/join-wp-qr.svg', PINGWA_FILE ) ); ?>" width="198" height="198" alt="<?php esc_attr_e( 'QR code: opens WhatsApp with “join wp” ready to send to pingwa', 'pingwa' ); ?>">
					<figcaption><?php esc_html_e( 'Scan with your phone', 'pingwa' ); ?></figcaption>
				</figure>
			</div>
			<p class="description" style="max-width:60em">
				<?php
				printf(
					/* translators: 1: link to the hook URL docs, 2: link to the WooCommerce recipe, 3: link to the forms recipe. */
					esc_html__( 'A hook URL is made in the pingwa panel or with the API (POST /v1/hooks), not by a WhatsApp command. More: %1$s, %2$s, %3$s. With Sender “Custom” every message is the text this plugin writes, for orders and forms alike. A hook with the sender “WooCommerce: new order” or “Form: new response” works too: pingwa’s template then picks the fields.', 'pingwa' ),
					'<a href="' . esc_url( $server . '/llms.txt' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Hook URLs reference', 'pingwa' ) . '</a>',
					'<a href="' . esc_url( $server . '/woocommerce' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'WooCommerce recipe', 'pingwa' ) . '</a>',
					'<a href="' . esc_url( $server . '/forms' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'forms recipe', 'pingwa' ) . '</a>'
				);
				?>
			</p>

			<h2><?php esc_html_e( '2. Settings', 'pingwa' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pingwa_save">
				<?php wp_nonce_field( 'pingwa_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pingwa_hook_url"><?php esc_html_e( 'Hook URL', 'pingwa' ); ?></label></th>
						<td>
							<input type="url" class="large-text code" id="pingwa_hook_url" name="pingwa_hook_url" value="" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( '' === $url ? $server . '/h/hk_…' : Pingwa_Hook_Url::mask( $url ) ); ?>">
							<p class="description">
								<?php
								if ( '' === $url ) {
									esc_html_e( 'No hook URL saved: nothing is sent.', 'pingwa' );
								} else {
									printf(
										/* translators: %s: the saved hook URL with its token hidden. */
										esc_html__( 'Saved: %s. Paste a new one to replace it; leave the field empty to keep it. The URL works like a password, so it is not shown again.', 'pingwa' ),
										'<code>' . esc_html( Pingwa_Hook_Url::mask( $url ) ) . '</code>'
									);
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Send a message on', 'pingwa' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( Pingwa_Triggers::names() as $key => $name ) : ?>
									<?php $active = Pingwa_Triggers::is_active( $key ); ?>
									<label style="display:block;margin-bottom:6px">
										<input type="checkbox" name="pingwa_triggers[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( Pingwa_Triggers::resolve( $saved, $key, $active ) ); ?> <?php disabled( ! $active ); ?>>
										<?php echo esc_html( $name ); ?>
										<?php if ( ! $active ) : ?>
											<span class="description"><?php esc_html_e( '(plugin not active)', 'pingwa' ); ?></span>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Forms: the form title and each field’s label and value. Passwords, uploads, hidden fields and spam traps are never sent. Orders: one message per order with its number, total, currency, status, items, the customer’s name, the payment method and a link to the order in your admin. Never the customer’s e-mail, phone or address.', 'pingwa' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'pingwa' ) ); ?>
			</form>

			<h2><?php esc_html_e( '3. Send test', 'pingwa' ); ?></h2>
			<p><?php esc_html_e( 'Posts a short test message to the saved hook URL and shows pingwa’s answer.', 'pingwa' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:12px">
				<input type="hidden" name="action" value="pingwa_test">
				<?php wp_nonce_field( 'pingwa_test' ); ?>
				<?php submit_button( __( 'Send test', 'pingwa' ), 'secondary', 'submit', false, '' === $url ? array( 'disabled' => 'disabled' ) : array() ); ?>
			</form>
			<?php if ( '' !== $url ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
					<input type="hidden" name="action" value="pingwa_forget">
					<?php wp_nonce_field( 'pingwa_forget' ); ?>
					<?php submit_button( __( 'Remove hook URL', 'pingwa' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
