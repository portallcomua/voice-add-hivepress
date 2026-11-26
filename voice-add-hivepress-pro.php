<?php
/**
 * Plugin Name: Voice ADD for HivePress PRO
 * Plugin URI:  https://uaserver.pp.ua/voice-add-hivepress-pro/
 * Description: Голосове додавання оголошень у HivePress з фото, прибиранням фону та атрибутами.
 * Version:     3.3.5
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

define( 'VAHP_PRO_LOADED', true );
define( 'VAHP_VERSION', '3.3.4' );
define( 'VAHP_FILE', __FILE__ );
define( 'VAHP_DIR', plugin_dir_path( __FILE__ ) );
define( 'VAHP_URL', plugin_dir_url( __FILE__ ) );
define( 'VAHP_GITHUB', 'portallcomua/voice-add-hivepress' );
define( 'VAHP_FREE_LIMIT', 25 );
define( 'VAHP_HUB', 'https://uaserver.pp.ua' ); // сервер Universal Pay Box

require_once vahpp_DIR . 'includes/product.php';
require_once vahpp_DIR . 'includes/common.php';

// PRO замінює безкоштовну та старі версії (налаштування та роль спільні).
// Старі версії (папка «voice-add-hivepress») оголошували ті самі константи й давали Warning — вимикаємо їх автоматично.
function vahpp_pro_deactivate_others() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$others = array( 'voice-add-hivepress/voice-add-hivepress.php' );
	$hit    = array();
	foreach ( array_unique( $others ) as $other ) {
		if ( plugin_basename( __FILE__ ) !== $other && is_plugin_active( $other ) ) {
			deactivate_plugins( $other );
			$hit[] = $other;
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
