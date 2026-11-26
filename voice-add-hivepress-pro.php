<?php
/**
 * Plugin Name: Voice ADD for HivePress PRO
 * Plugin URI:  https://uaserver.pp.ua/voice-add-hivepress-pro/
 * Description: Голосове додавання оголошень у HivePress з фото, прибиранням фону та атрибутами.
 * Version:     3.3.6
 * Author:      UAServer
 * Author URI:  https://uaserver.pp.ua/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: voice-add-hivepress
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VAHPP_PRO_LOADED', true );
define( 'VAHPP_VERSION', '3.3.6' );
define( 'VAHPP_FILE', __FILE__ );
define( 'VAHPP_DIR', plugin_dir_path( __FILE__ ) );
define( 'VAHPP_URL', plugin_dir_url( __FILE__ ) );
define( 'VAHPP_GITHUB', 'portallcomua/voice-add-hivepress' );
define( 'VAHPP_FREE_LIMIT', 25 );
define( 'VAHPP_HUB', 'https://uaserver.pp.ua' ); // сервер Universal Pay Box

require_once VAHPP_DIR . 'includes/product.php';
require_once VAHPP_DIR . 'includes/common.php';

// PRO замінює безкоштовну та старі версії (налаштування та роль спільні).
// Старі версії (папка «voice-add-hivepress») оголошували ті самі константи й давали Warning — вимикаємо їх автоматично.
function vahpp_pro_deactivate_others() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$current = plugin_basename( __FILE__ );
	$plugins = get_plugins();
	$active  = (array) get_option( 'active_plugins', array() );
	$hit     = array();

	foreach ( $plugins as $plugin_file => $data ) {
		if ( $plugin_file === $current || ! in_array( $plugin_file, $active, true ) ) {
			continue;
		}

		$name = strtolower( isset( $data['Name'] ) ? wp_strip_all_tags( $data['Name'] ) : '' );
		$path = strtolower( $plugin_file );

		// Вимикаємо всі старі копії Voice ADD for HivePress незалежно від назви папки.
		$is_voice_add = 0 === strpos( $name, 'voice add for hivepress' )
			|| 0 === strpos( $path, 'voice-add-hivepress/' )
			|| 0 === strpos( $path, 'voice-add-hivepress-pro/' );

		if ( $is_voice_add ) {
			deactivate_plugins( $plugin_file );
			$hit[] = $plugin_file;
		}
	}

	return $hit;
}
register_activation_hook( __FILE__, function () {
	vahpp_setup();
	vahpp_pro_deactivate_others();
} );
add_action( 'admin_init', function () {
	$hit = vahpp_pro_deactivate_others();
	if ( $hit ) {
		set_transient( 'vahp_deactivated_notice', implode( ', ', $hit ), 60 );
	}
} );
add_action( 'admin_notices', function () {
	$t = get_transient( 'vahp_deactivated_notice' );
	if ( $t && current_user_can( 'activate_plugins' ) ) {
		delete_transient( 'vahp_deactivated_notice' );
		echo '<div class="notice notice-success is-dismissible"><p><strong>Voice ADD for HivePress PRO:</strong> ' . esc_html( 'Старі/безкоштовні версії вимкнено: ' . $t . '. Тепер їх можна видалити через FTP або файловий менеджер хостингу (не кнопкою «Видалити» у WordPress — вона стирає спільні налаштування).' ) . '</p></div>';
	}
} );
