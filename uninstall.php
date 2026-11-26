<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
// Налаштування та роль спільні для Lite/PRO та старих версій: якщо активна будь-яка інша версія — нічого не видаляємо.
foreach ( (array) get_option( 'active_plugins', array() ) as $vahp_active ) {
	if ( 0 === strpos( $vahp_active, 'voice-add-hivepress' ) || 0 === strpos( $vahp_active, 'voice-add-hivepress' ) ) {
		return;
	}
}
remove_role( 'vahp_manager' );
foreach ( array( 'vahp_hub', 'vahp_pro', 'vahp_draft', 'vahp_rbg_api', 'vahp_lang', 'vahp_allowed_roles', 'vahp_ver' ) as $vahp_option_name ) {
	delete_option( $vahp_option_name );
}
delete_metadata( 'user', 0, 'vahp_ops', '', true );
delete_metadata( 'user', 0, 'vahp_pro_since', '', true );
