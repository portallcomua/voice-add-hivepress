<?php
/**
 * Спільне ядро Voice ADD (Voice ADD for HivePress PRO).
 * Мова, роль, права, інтеграція з Pay Box, оновлення з GitHub, налаштування,
 * головна сторінка, інструкції, збереження.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ====================================================================== */
/* 1. МОВА                                                                 */
/* ====================================================================== */
function vahpp_lang() {
	$o = get_option( 'vahpp_lang', 'auto' );
	if ( 'uk' === $o || 'en' === $o ) {
		return $o;
	}
	$loc = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
	return ( 0 === strpos( $loc, 'en' ) ) ? 'en' : 'uk';
}
function vahpp_en() { return 'en' === vahpp_lang(); }
function vahpp_t( $ua, $en ) { return vahpp_en() ? $en : $ua; }

function vahpp_norm( $s ) {
	$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
	$s = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s );
	return trim( $s );
}

/* ====================================================================== */
/* 2. АВТООНОВЛЕННЯ З GITHUB                                               */
/* ====================================================================== */
class vahpp_Updater {
	private $basename, $slug, $repo, $version;

	public function __construct( $file, $repo, $version ) {
		$this->basename = plugin_basename( $file );
		$this->slug     = dirname( $this->basename );
		$this->repo     = $repo;
		$this->version  = $version;
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check' ) );
		add_filter( 'plugins_api', array( $this, 'info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_dir' ), 10, 4 );
	}

	private function key() { return 'vahp_gh_' . md5( $this->repo ); }

	public function flush() { delete_transient( $this->key() ); }

	public function remote() {
		$cached = get_transient( $this->key() );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}
		$resp = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo . '/releases/latest',
			array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'WordPress/' . $this->slug ) )
		);
		$out = null;
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$d = json_decode( wp_remote_retrieve_body( $resp ) );
			if ( $d && isset( $d->tag_name ) ) {
				$zip = isset( $d->zipball_url ) ? $d->zipball_url : '';
				if ( ! empty( $d->assets ) ) {
					foreach ( $d->assets as $a ) {
						if ( isset( $a->name, $a->browser_download_url ) && preg_match( '/\.zip$/i', $a->name ) ) {
							$zip = $a->browser_download_url;
							if ( $a->name === $this->slug . '.zip' ) {
								break;
							}
						}
					}
				}
				$out = (object) array( 'version' => ltrim( $d->tag_name, 'vV' ), 'zip' => $zip, 'url' => $d->html_url );
			}
		}
		set_transient( $this->key(), $out ? $out : 0, 6 * HOUR_IN_SECONDS );
		return $out;
	}

	public function check( $t ) {
		if ( ! is_object( $t ) ) {
			return $t;
		}
		$r = $this->remote();
		if ( $r && $r->zip && version_compare( $this->version, $r->version, '<' ) ) {
			$t->response[ $this->basename ] = (object) array(
				'slug' => $this->slug, 'plugin' => $this->basename, 'new_version' => $r->version,
				'package' => $r->zip, 'url' => $r->url,
			);
		}
		return $t;
	}

	public function info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $res;
		}
		$r = $this->remote();
		if ( ! $r ) {
			return $res;
		}
		return (object) array(
			'name' => 'Voice ADD for HivePress PRO', 'slug' => $this->slug, 'version' => $r->version,
			'download_link' => $r->zip, 'requires_php' => '7.4',
			'sections' => array( 'description' => 'Голосове додавання оголошень у HivePress з фото, прибиранням фону та атрибутами.' ),
		);
	}

	public function fix_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}
		global $wp_filesystem;
		$new = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $new ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $new, true ) ) {
			return $new;
		}
		return $source;
	}
}

function vahpp_updater() {
	static $u = null;
	if ( null === $u ) {
		$u = new vahpp_Updater( VAHPP_FILE, VAHPP_GITHUB, VAHPP_VERSION );
	}
	return $u;
}
vahpp_updater();

/* ====================================================================== */
/* 3. PRO ЧЕРЕЗ FREEMIUS + UA PAY BOX                                      */
/* ====================================================================== */
function vahpp_hub_url() {
	return untrailingslashit( esc_url_raw( VAHPP_HUB ) );
}

/** Pay Box працює на цьому ж сайті — використовуємо його PHP API без HTTP. */
function vahpp_hub_is_local() {
	$hub_host  = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( vahpp_hub_url(), PHP_URL_HOST ) ) );
	$site_host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	return $hub_host && $hub_host === $site_host
		&& class_exists( '\\UAPM\\PayBox\\UPB_Rest' )
		&& class_exists( '\\UAPM\\PayBox\\UPB_Freemius' );
}

/** Запит до центрального UA Pay Box. Product ID, Plan ID і секрети залишаються тільки в Pay Box. */
function vahpp_hub_call( $method, $route, array $params = array() ) {
	if ( vahpp_hub_is_local() ) {
		$req = new WP_REST_Request( $method, '/upb/v1/' . $route );
		foreach ( $params as $key => $value ) {
			$req->set_param( $key, $value );
		}
		$res = rest_do_request( $req );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = $res->get_data();
		$code = $res->get_status();
	} else {
		$url = vahpp_hub_url() . '/wp-json/upb/v1/' . $route;
		$args = array( 'timeout' => 15, 'headers' => array( 'Accept' => 'application/json' ) );
		$r = 'GET' === strtoupper( $method )
			? wp_remote_get( add_query_arg( $params, $url ), $args )
			: wp_remote_post( $url, array_merge( $args, array( 'body' => $params ) ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
	}
	if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
		$message = is_array( $data ) && ! empty( $data['message'] )
			? $data['message']
			: vahpp_t( 'UA Pay Box тимчасово недоступний.', 'UA Pay Box is temporarily unavailable.' );
		return new WP_Error( 'vahp_paybox', $message );
	}
	return $data;
}

/** Статус Freemius для поточного користувача WordPress. */
function vahpp_pro_state( $force = false ) {
	$user_id = get_current_user_id();
	$free = array( 'state' => 'free', 'expires' => 0, 'checked' => time() );
	if ( ! $user_id ) {
		return $free;
	}

	// Якщо UA Pay Box встановлений локально, користуємося його штатною перевіркою.
	if ( function_exists( 'upb_service_id' ) && function_exists( 'wcc_check_user_premium' ) ) {
		$service_id = (int) upb_service_id( 'voice-add-hivepress' );
		if ( $service_id ) {
			$active  = (bool) wcc_check_user_premium( $user_id, $service_id );
			$expires = $active && function_exists( 'wcc_get_user_premium_expiry' )
				? wcc_get_user_premium_expiry( $user_id, $service_id )
				: 0;
			return array(
				'state'   => $active ? ( $expires ? 'active' : 'unlimited' ) : 'free',
				'expires' => $active ? (int) $expires : 0,
				'checked' => time(),
			);
		}
	}

	// Для зовнішнього сайту ліцензія перевіряється за email поточного WP-користувача.
	$user  = get_userdata( $user_id );
	$email = $user ? sanitize_email( $user->user_email ) : '';
	if ( ! $email || ! is_email( $email ) ) {
		return $free;
	}

	$cache_key = 'vahp_fs_' . $user_id . '_' . md5( strtolower( $email ) );
	$cached = get_transient( $cache_key );
	if ( ! $force && is_array( $cached ) && isset( $cached['checked'], $cached['state'] )
		&& time() - (int) $cached['checked'] < 5 * MINUTE_IN_SECONDS ) {
		return $cached;
	}

	$result = vahpp_hub_call( 'POST', 'license/status', array(
		'service' => 'voice-add-hivepress',
		'email'   => $email,
	) );

	if ( ! is_wp_error( $result ) ) {
		$valid   = ! empty( $result['valid'] );
		$expires = isset( $result['expires'] ) ? absint( $result['expires'] ) : 0;
		$state   = $valid ? ( $expires ? 'active' : 'unlimited' ) : 'free';
		$state_data = array( 'state' => $state, 'expires' => $valid ? $expires : 0, 'checked' => time() );
		set_transient( $cache_key, $state_data, 5 * MINUTE_IN_SECONDS );
		return $state_data;
	}

	// Короткий збій сервера не вимикає вже підтверджену ліцензію миттєво.
	if ( is_array( $cached ) && ! empty( $cached['state'] ) && 'free' !== $cached['state']
		&& isset( $cached['checked'] ) && time() - (int) $cached['checked'] < DAY_IN_SECONDS ) {
		return $cached;
	}
	return $free;
}

function vahpp_is_pro() {
	$state = vahpp_pro_state();
	return in_array( $state['state'], array( 'active', 'expiring', 'unlimited' ), true )
		&& ( empty( $state['expires'] ) || (int) $state['expires'] > time() );
}

/** Кнопка Freemius Checkout, створена налаштованою послугою UA Pay Box. */
function vahpp_buy_button( $label = '' ) {
	$label = $label ? $label : vahpp_t( 'Купити PRO', 'Buy PRO' );

	// На сайті, де встановлено Pay Box, використовуємо його штатну кнопку й модальне вікно.
	if ( function_exists( 'upb_service_id' ) && function_exists( 'upb_buy_button' ) ) {
		$service_id = (int) upb_service_id( 'voice-add-hivepress' );
		if ( $service_id ) {
			return upb_buy_button( $service_id, $label, 'va-buy' );
		}
	}

	// На зовнішньому сайті отримуємо checkout URL із центрального Pay Box.
	$user  = get_userdata( get_current_user_id() );
	$email = $user ? sanitize_email( $user->user_email ) : '';
	if ( ! $email || ! is_email( $email ) ) {
		return '<span class="va-muted">' . esc_html( vahpp_t( 'Для купівлі PRO потрібен коректний email облікового запису.', 'A valid account email is required to buy PRO.' ) ) . '</span>';
	}
	$service = vahpp_hub_call( 'GET', 'service', array(
		'service' => 'voice-add-hivepress',
		'email'   => $email,
		'locale'  => get_locale(),
	) );
	$url = is_array( $service ) && ! empty( $service['checkout_url'] )
		? $service['checkout_url']
		: ( is_array( $service ) && ! empty( $service['url'] ) ? $service['url'] : '' );
	if ( ! $url ) {
		return '<span class="va-muted">' . esc_html( vahpp_t( 'Оплата PRO наразі недоступна. Перевірте налаштування послуги в UA Pay Box.', 'PRO checkout is unavailable. Check the service settings in UA Pay Box.' ) ) . '</span>';
	}
	return '<a class="button button-primary va-buy" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">💰 ' . esc_html( $label ) . '</a>';
}

// Якщо Pay Box встановлений локально і послуги ще немає — створюємо запис за slug.
// Уже налаштований продукт Freemius не змінюємо й не перезаписуємо.
add_action( 'admin_init', function () {
	if ( function_exists( 'upb_register_service' ) && function_exists( 'upb_service_id' )
		&& ! upb_service_id( 'voice-add-hivepress' ) ) {
		upb_register_service( 'voice-add-hivepress', 'Voice ADD for HivePress PRO' );
	}
} );

/* Безкоштовний ліміт — на користувача */
function vahpp_limit() { return (int) apply_filters( 'vahp_free_limit', VAHPP_FREE_LIMIT ); }
function vahpp_count() { return (int) get_user_meta( get_current_user_id(), 'vahp_ops', true ); }
function vahpp_inc( $n = 1 ) { update_user_meta( get_current_user_id(), 'vahp_ops', vahpp_count() + (int) $n ); }
function vahpp_remaining() { return max( 0, vahpp_limit() - vahpp_count() ); }
function vahpp_can_create( $n = 1 ) { return vahpp_is_pro() || vahpp_count() + (int) $n <= vahpp_limit(); }

/* ====================================================================== */
/* 4. РОЛЬ, ПРАВА, АКТИВАЦІЯ                                               */
/* ====================================================================== */
function vahpp_setup() {
	if ( get_role( 'vahp_manager' ) ) {
		remove_role( 'vahp_manager' );
	}
	add_role( 'vahp_manager', 'Менеджер плагіна voice-add-hivepress', vahpp_role_caps() );
	add_option( 'vahp_draft', '0' );
	add_option( 'vahp_rbg_api', '' );
	add_option( 'vahpp_lang', 'auto' );
	add_option( 'vahp_allowed_roles', array( 'administrator', 'vahp_manager' ) );
	update_option( 'vahp_ver', VAHPP_VERSION );
}
add_action( 'admin_init', function () {
	if ( get_option( 'vahp_ver' ) !== VAHPP_VERSION ) {
		vahpp_setup();
	}
} );

/** Віртуальне право vahp_use: адміністратори + вибрані в налаштуваннях ролі. */
add_filter( 'user_has_cap', function ( $allcaps, $caps, $args, $user ) {
	if ( in_array( 'vahp_use', (array) $caps, true ) ) {
		$allowed = (array) get_option( 'vahp_allowed_roles', array( 'administrator', 'vahp_manager' ) );
		if ( ! empty( $allcaps['manage_options'] ) || array_intersect( (array) $user->roles, $allowed ) ) {
			$allcaps['vahp_use'] = true;
		}
	}
	return $allcaps;
}, 10, 4 );

/* ====================================================================== */
/* 5. ПОЛЯ (маяки)                                                         */
/* ====================================================================== */
function vahpp_fields() {
	static $f = null;
	if ( null === $f ) {
		$f = array_merge( vahpp_base_fields(), vahpp_dynamic_fields() );
	}
	return $f;
}
function vahpp_label( $def ) { return vahpp_en() ? $def['en'] : $def['ua']; }

function vahpp_field_map() {
	static $m = null;
	if ( null === $m ) {
		$m = array();
		foreach ( vahpp_fields() as $d ) {
			$names = array_merge( array( $d['ua'], $d['en'] ), isset( $d['aliases'] ) ? $d['aliases'] : array() );
			foreach ( $names as $n ) {
				$k = vahpp_norm( $n );
				if ( '' !== $k && ! isset( $m[ $k ] ) ) {
					$m[ $k ] = $d['key'];
				}
			}
		}
	}
	return $m;
}
function vahpp_field_def( $key ) {
	foreach ( vahpp_fields() as $d ) {
		if ( $d['key'] === $key ) {
			return $d;
		}
	}
	return null;
}

function vahpp_template() {
	$out = '';
	foreach ( vahpp_fields() as $d ) {
		if ( ! empty( $d['tpl'] ) ) {
			$out .= vahpp_label( $d ) . ": \n";
		}
	}
	return $out;
}

/** Розбір тексту форми: «Маяк: значення» → масив. */
function vahpp_parse_text( $raw ) {
	$map  = vahpp_field_map();
	$data = array();
	$rep  = array();
	$cust = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
		$line = trim( $line );
		$pos  = strpos( $line, ':' );
		if ( '' === $line || false === $pos ) {
			continue;
		}
		$label = trim( substr( $line, 0, $pos ) );
		$val   = trim( substr( $line, $pos + 1 ) );
		if ( '' === $val ) {
			continue;
		}
		$n = vahpp_norm( $label );
		if ( isset( $map[ $n ] ) ) {
			$def = vahpp_field_def( $map[ $n ] );
			if ( $def && 'attr' === $def['kind'] ) {
				continue; // рядок-маяк «Атрибут:» без назви — пропускаємо
			}
			if ( $def && 'repeat' === $def['kind'] ) {
				$rep[ $def['key'] ][] = $val;
			} else {
				$data[ $map[ $n ] ] = $val;
			}
		} elseif ( preg_match( '/^(атрибут|attribute)\s+(.+)$/u', $n ) ) {
			$cname          = trim( preg_replace( '/^(атрибут|attribute)\s+/iu', '', $label ) );
			$cust[ $cname ] = isset( $cust[ $cname ] ) ? $cust[ $cname ] . ', ' . $val : $val;
		}
	}
	return array( 'data' => $data, 'rep' => $rep, 'custom' => $cust );
}

/** Назва атрибута зі слів диктовки → ключ 'attr:slug' існуючого атрибута (або ''). */
function vahpp_resolve_attr( $name ) {
	$n = vahpp_norm( $name );
	foreach ( vahpp_attr_defs() as $a ) {
		if ( $n === vahpp_norm( $a['label'] ) || $n === vahpp_norm( $a['slug'] ) ) {
			return $a['key'];
		}
	}
	return '';
}

function vahpp_num( $s ) {
	$s = str_replace( array( ' ', "\xC2\xA0" ), '', (string) $s );
	$s = str_replace( ',', '.', $s );
	if ( preg_match( '/-?\d+(?:\.\d+)?/', $s, $m ) ) {
		return $m[0];
	}
	return '';
}

function vahpp_term_ids( $names, $taxonomy ) {
	$ids = array();
	foreach ( (array) $names as $name ) {
		$name = trim( $name );
		if ( '' === $name ) {
			continue;
		}
		$t = term_exists( $name, $taxonomy );
		if ( ! $t ) {
			$t = wp_insert_term( $name, $taxonomy );
		}
		if ( is_wp_error( $t ) ) {
			continue;
		}
		$ids[] = (int) ( is_array( $t ) ? $t['term_id'] : $t );
	}
	return $ids;
}

/** Ієрархія «Батько > Дитина»: повертає ID останнього терму. */
function vahpp_term_path( $path, $taxonomy ) {
	$parent = 0;
	$last   = 0;
	foreach ( array_filter( array_map( 'trim', explode( '>', $path ) ) ) as $name ) {
		$t = term_exists( $name, $taxonomy, $parent );
		if ( ! $t ) {
			$t = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent ) );
		}
		if ( is_wp_error( $t ) ) {
			break;
		}
		$last   = (int) ( is_array( $t ) ? $t['term_id'] : $t );
		$parent = $last;
	}
	return $last;
}

/* ====================================================================== */
/* 6. ЗОБРАЖЕННЯ                                                           */
/* ====================================================================== */
function vahpp_save_images( $pid, $imgs ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$ids = array();
	$map = array(
		IMAGETYPE_JPEG => array( 'jpg', 'image/jpeg' ),
		IMAGETYPE_PNG  => array( 'png', 'image/png' ),
		IMAGETYPE_WEBP => array( 'webp', 'image/webp' ),
	);
	foreach ( array_slice( (array) $imgs, 0, 10 ) as $i => $b64 ) {
		if ( ! is_string( $b64 ) || false === ( $p = strpos( $b64, 'base64,' ) ) ) {
			continue;
		}
		$bin = base64_decode( substr( $b64, $p + 7 ), true );
		if ( ! $bin ) {
			continue;
		}
		$info = @getimagesizefromstring( $bin );
		if ( ! $info || ! isset( $map[ $info[2] ] ) ) {
			continue;
		}
		$up = wp_upload_bits( 'vahp_' . (int) $pid . '_' . time() . '_' . (int) $i . '.' . $map[ $info[2] ][0], null, $bin );
		if ( ! empty( $up['error'] ) ) {
			continue;
		}
		$aid = wp_insert_attachment(
			array( 'post_mime_type' => $map[ $info[2] ][1], 'post_title' => get_the_title( $pid ) ? get_the_title( $pid ) : 'image', 'post_status' => 'inherit' ),
			$up['file'],
			$pid
		);
		if ( ! $aid || is_wp_error( $aid ) ) {
			continue;
		}
		wp_update_attachment_metadata( $aid, wp_generate_attachment_metadata( $aid, $up['file'] ) );
		$ids[] = (int) $aid;
	}
	return $ids;
}

function vahpp_sideload_images( $pid, $urls ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$ids = array();
	foreach ( array_slice( (array) $urls, 0, 10 ) as $url ) {
		$url = esc_url_raw( trim( $url ) );
		if ( ! $url ) {
			continue;
		}
		$id = media_sideload_image( $url, $pid, null, 'id' );
		if ( ! is_wp_error( $id ) ) {
			$ids[] = (int) $id;
		}
	}
	return $ids;
}

/* ====================================================================== */
/* 7. МЕНЮ                                                                 */
/* ====================================================================== */
add_action( 'admin_menu', function () {
	add_menu_page( 'Voice ADD for HivePress PRO', 'Voice ADD HP', 'vahp_use', 'vahp_main', 'vahpp_page_main', 'dashicons-microphone', 30 );
	add_submenu_page( 'vahp_main', vahpp_t( 'Додавання голосом', 'Voice add' ), '🎤 ' . vahpp_t( 'Додавання голосом', 'Voice add' ), 'vahp_use', 'vahp_main', 'vahpp_page_main' );
	add_submenu_page( 'vahp_main', vahpp_t( 'Налаштування', 'Settings' ), '⚙️ ' . vahpp_t( 'Налаштування', 'Settings' ), 'manage_options', 'vahp_settings', 'vahpp_page_settings' );
}, 20 );

add_filter( 'plugin_action_links_' . plugin_basename( VAHPP_FILE ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=vahp_settings' ) ) . '">' . esc_html( vahpp_t( 'Налаштування', 'Settings' ) ) . '</a>' );
	return $links;
} );

/* ====================================================================== */
/* 8. НАЛАШТУВАННЯ                                                         */
/* ====================================================================== */
function vahpp_page_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden' );
	}
	$msg = '';
	if ( isset( $_POST['vahp_save'] ) && check_admin_referer( 'vahp_cfg' ) ) {
		update_option( 'vahp_rbg_api', sanitize_text_field( wp_unslash( isset( $_POST['rbg_api'] ) ? $_POST['rbg_api'] : '' ) ) );
		update_option( 'vahp_draft', isset( $_POST['draft'] ) ? '1' : '0' );
				$lang = isset( $_POST['lang'] ) ? sanitize_key( $_POST['lang'] ) : 'auto';
		update_option( 'vahpp_lang', in_array( $lang, array( 'auto', 'uk', 'en' ), true ) ? $lang : 'auto' );
		$valid = array_keys( get_editable_roles() );
		$roles = isset( $_POST['roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['roles'] ) ) : array();
		$roles = array_values( array_intersect( $roles, $valid ) );
		if ( ! in_array( 'administrator', $roles, true ) ) {
			$roles[] = 'administrator';
		}
		update_option( 'vahp_allowed_roles', $roles );
		$msg = '<div class="notice notice-success is-dismissible"><p>✅ ' . esc_html( vahpp_t( 'Збережено!', 'Saved!' ) ) . '</p></div>';
	}

	$rbg   = get_option( 'vahp_rbg_api', '' );
	$draft = get_option( 'vahp_draft', '0' );
	$roles = (array) get_option( 'vahp_allowed_roles', array( 'administrator', 'vahp_manager' ) );
	$lang  = get_option( 'vahpp_lang', 'auto' );
	?>
	<div class="wrap">
		<h1>
			⚙️ Voice ADD for HivePress PRO
			<span style="font-size:14px;color:#666;font-weight:400">v<?php echo esc_html( VAHPP_VERSION ); ?></span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=vahp_main' ) ); ?>" class="page-title-action">🎤 <?php echo esc_html( vahpp_t( 'Перейти на плагін', 'Go to plugin' ) ); ?></a>
		</h1>
		<?php echo $msg; // phpcs:ignore ?>

		<form method="post"><?php wp_nonce_field( 'vahp_cfg' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label>Remove.bg API Key</label></th>
					<td><input type="text" name="rbg_api" value="<?php echo esc_attr( $rbg ); ?>" class="regular-text code" autocomplete="off">
					<p class="description"><?php echo esc_html( vahpp_t( 'Для прибирання фону на фото. Ключ: remove.bg/api. Без ключа вибір фону вимкнено.', 'For background removal on photos. Get a key at remove.bg/api. Without a key the background picker is disabled.' ) ); ?></p></td>
				</tr>
				<tr>
					<th><?php echo esc_html( vahpp_t( 'Статус нового запису', 'New item status' ) ); ?></th>
					<td><label><input type="checkbox" name="draft" value="1" <?php checked( $draft, '1' ); ?>> <?php echo esc_html( vahpp_t( 'Зберігати як чернетку', 'Save as draft' ) ); ?></label></td>
				</tr>
				<tr>
					<th><?php echo esc_html( vahpp_t( 'Мова плагіна', 'Plugin language' ) ); ?></th>
					<td><select name="lang">
						<option value="auto" <?php selected( $lang, 'auto' ); ?>><?php echo esc_html( vahpp_t( 'Автоматично (мова сайту)', 'Auto (site language)' ) ); ?></option>
						<option value="uk" <?php selected( $lang, 'uk' ); ?>>Українська</option>
						<option value="en" <?php selected( $lang, 'en' ); ?>>English</option>
					</select>
					<p class="description"><?php echo esc_html( vahpp_t( 'Мова інтерфейсу, маяків та розпізнавання голосу.', 'Interface, beacon words and speech recognition language.' ) ); ?></p></td>
				</tr>
				<tr>
					<th><?php echo esc_html( vahpp_t( 'Тип користувачів з доступом', 'User roles with access' ) ); ?></th>
					<td>
						<?php foreach ( get_editable_roles() as $r_name => $r_info ) : ?>
							<label style="display:block;margin-bottom:3px">
								<input type="checkbox" name="roles[]" value="<?php echo esc_attr( $r_name ); ?>" <?php checked( in_array( $r_name, $roles, true ) ); ?> <?php disabled( 'administrator' === $r_name ); ?>>
								<?php echo esc_html( translate_user_role( $r_info['name'] ) ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php echo esc_html( vahpp_t( 'Ці ролі бачать плагін і можуть ним працювати. Адміністратор має доступ завжди. Роль «Менеджер плагіна» створюється автоматично при встановленні.', 'These roles can see and use the plugin. Administrators always have access. The “Plugin manager” role is created automatically on install.' ) ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" name="vahp_save" value="1" class="button button-primary"><?php echo esc_html( vahpp_t( 'Зберегти', 'Save' ) ); ?></button>
			</p>
		</form>

	</div>
	<?php
}

/* ====================================================================== */
/* 9. ГОЛОВНА СТОРІНКА                                                     */
/* ====================================================================== */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( false === strpos( $hook, 'vahp_main' ) ) {
		return;
	}
	wp_enqueue_style( 'vahp-app', VAHPP_URL . 'assets/app.css', array(), VAHPP_VERSION );
	wp_enqueue_script( 'vahp-app', VAHPP_URL . 'assets/app.js', array(), VAHPP_VERSION, true );

	$fields = array();
	foreach ( vahpp_fields() as $d ) {
		$al = array( $d['ua'], $d['en'] );
		if ( ! empty( $d['aliases'] ) ) {
			$al = array_merge( $al, $d['aliases'] );
		}
		$fields[] = array(
			'key'     => $d['key'],
			'label'   => vahpp_label( $d ),
			'aliases' => array_values( array_unique( array_filter( array_map( 'vahpp_norm', $al ) ) ) ),
			'kind'    => $d['kind'],
			'tpl'     => ! empty( $d['tpl'] ),
			'join'    => isset( $d['join'] ) ? $d['join'] : ' ',
		);
	}
	$S = array(
		'mic'        => '🎤 ' . vahpp_t( 'ДИКТУВАТИ', 'DICTATE' ),
		'micStop'    => '🛑 ' . vahpp_t( 'ЗУПИНИТИ', 'STOP' ),
		'save'       => '➕ ' . ( '1' === get_option( 'vahp_draft', '0' ) ? vahpp_t( 'ЗБЕРЕГТИ ЯК ЧЕРНЕТКУ', 'SAVE AS DRAFT' ) : vahpp_t( 'ОПУБЛІКУВАТИ', 'PUBLISH' ) ),
		'saving'     => '⏳ ' . vahpp_t( 'Зберігаю...', 'Saving...' ),
		'noSpeech'   => vahpp_t( 'Ваш браузер не підтримує розпізнавання голосу. Використовуйте Chrome (Android/ПК) через HTTPS.', 'Your browser does not support speech recognition. Use Chrome (Android/PC) over HTTPS.' ),
		'noCam'      => vahpp_t( 'Камера недоступна. Можна додати фото з галереї.', 'Camera unavailable. You can add photos from the gallery.' ),
		'listening'  => vahpp_t( 'Слухаю…', 'Listening…' ),
		'target'     => vahpp_t( 'Диктуєте в поле', 'Dictating into' ),
		'created'    => vahpp_t( 'Створено!', 'Created!' ),
		'edit'       => vahpp_t( 'Відкрити', 'Open' ),
		'limit'      => vahpp_t( 'Безкоштовний ліміт вичерпано. Придбайте PRO.', 'Free limit reached. Please buy PRO.' ),
		'bgWork'     => vahpp_t( 'Прибираю фон', 'Removing background' ),
		'bgFail'     => vahpp_t( 'Не вдалося прибрати фон — збережено оригінал', 'Background removal failed — original saved' ),
		'importing'  => vahpp_t( 'Імпортую…', 'Importing…' ),
		'importDone' => vahpp_t( 'Імпорт завершено', 'Import finished' ),
		'created_n'  => vahpp_t( 'створено', 'created' ),
		'failed_n'   => vahpp_t( 'помилок', 'failed' ),
		'pickCsv'    => vahpp_t( 'Виберіть CSV-файл', 'Choose a CSV file' ),
		'netErr'     => vahpp_t( 'Помилка зʼєднання', 'Connection error' ),
		'remove'     => vahpp_t( 'Натисніть, щоб видалити фото', 'Tap to remove photo' ),
		'printTitle' => vahpp_t( 'Інструкція', 'Instruction' ),
	);
	wp_localize_script(
		'vahp-app',
		'VA_CFG',
		array(
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'vahp_nonce' ),
			'actions' => array( 'save' => 'vahp_save', 'bg' => 'vahp_remove_bg', 'import' => 'vahp_import' ),
			'attrNames' => array_map( function ( $a ) { return $a['label']; }, vahpp_attr_defs() ),
			'lang'    => vahpp_en() ? 'en-US' : 'uk-UA',
			'hasRbg'  => '' !== (string) get_option( 'vahp_rbg_api', '' ),
			'fields'  => $fields,
			'tpl'     => vahpp_template(),
			'S'       => $S,
		)
	);
} );

function vahpp_page_main() {
	if ( ! current_user_can( 'vahp_use' ) ) {
		wp_die( esc_html( vahpp_t( 'Доступ заборонено', 'Access denied' ) ) );
	}
	$pro     = vahpp_is_pro();
	$draft   = '1' === get_option( 'vahp_draft', '0' );
	$has_rbg = '' !== (string) get_option( 'vahp_rbg_api', '' );
	$exp_url = add_query_arg( array( 'action' => 'vahp_export', 'nonce' => wp_create_nonce( 'vahp_nonce' ) ), admin_url( 'admin-ajax.php' ) );
	?>
	<div class="wrap va-wrap" id="va_app">
		<div class="va-head">
			<h1>🎤 Voice ADD for HivePress PRO <sup>v<?php echo esc_html( VAHPP_VERSION ); ?></sup></h1>
			<div class="va-head-btns">
				<button type="button" class="button" id="va_go_instr">📖 <?php echo esc_html( vahpp_t( 'Інструкція', 'Instructions' ) ); ?></button>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=vahp_settings' ) ); ?>">⚙️ <?php echo esc_html( vahpp_t( 'Налаштування', 'Settings' ) ); ?></a>
				<?php endif; ?>
			</div>
		</div>

		<?php $pst = vahpp_pro_state(); ?>
		<?php if ( $pro ) : ?>
			<div class="va-pro-bar va-pro-ok">✅ PRO — <?php echo esc_html( vahpp_t( 'без обмежень', 'unlimited' ) ); ?><?php echo ! empty( $pst['expires'] ) ? ' · ' . esc_html( vahpp_t( 'до ', 'until ' ) . wp_date( 'd.m.Y', (int) $pst['expires'] ) ) : ''; ?></div>
		<?php else : ?>
			<div class="va-pro-bar<?php echo 'blocked' === $pst['state'] ? ' va-pro-blocked' : ''; ?>">
				<?php if ( 'blocked' === $pst['state'] ) : ?>
					⛔ <?php echo esc_html( vahpp_t( 'PRO заблоковано адміністратором — оплата не надійшла.', 'PRO was blocked by the administrator — payment not received.' ) ); ?>
				<?php endif; ?>
				📊 <?php echo esc_html( vahpp_t( 'Залишилось безкоштовно:', 'Free left:' ) ); ?>
				<strong id="va_left"><?php echo (int) vahpp_remaining(); ?></strong>/<?php echo (int) vahpp_limit(); ?>
				<?php echo vahpp_buy_button(); // phpcs:ignore ?>
			</div>
		<?php endif; ?>

		<video id="va_cam" autoplay playsinline muted></video>
		<div id="va_gal" class="va-gal"></div>

		<div id="va_bg_wrap" class="va-bg" style="display:none">
			<strong><?php echo esc_html( vahpp_t( 'Фон:', 'Background:' ) ); ?></strong>
			<?php if ( $has_rbg ) : ?>
				<label><input type="radio" name="va_bg" value="original" checked> <?php echo esc_html( vahpp_t( 'Оригінал', 'Original' ) ); ?></label>
				<label><input type="radio" name="va_bg" value="transparent"> <?php echo esc_html( vahpp_t( 'Прозорий', 'Transparent' ) ); ?></label>
				<label><input type="radio" name="va_bg" value="ffffff"> <?php echo esc_html( vahpp_t( 'Білий', 'White' ) ); ?></label>
			<?php else : ?>
				<span class="va-muted"><?php echo esc_html( vahpp_t( 'вкажіть Remove.bg API Key в налаштуваннях', 'set the Remove.bg API key in settings' ) ); ?></span>
			<?php endif; ?>
		</div>

		<div class="va-row">
			<button type="button" id="va_photo" class="va-btn va-blue" style="flex:3">📷 <?php echo esc_html( vahpp_t( 'ФОТО', 'PHOTO' ) ); ?></button>
			<button type="button" id="va_file" class="va-btn va-gray" style="flex:2">🖼️ <?php echo esc_html( vahpp_t( 'З ГАЛЕРЕЇ', 'GALLERY' ) ); ?></button>
			<button type="button" id="va_clr" class="va-btn va-gray" style="flex:1">❌</button>
			<input type="file" id="va_files" accept="image/*" multiple hidden>
		</div>

		<div id="va_target" class="va-target" hidden></div>
		<textarea id="va_ta" rows="<?php echo (int) min( 22, max( 10, count( vahpp_fields() ) + 2 ) ); ?>" spellcheck="false"><?php echo esc_textarea( vahpp_template() ); ?></textarea>

		<button type="button" id="va_mic" class="va-btn va-mic">🎤 <?php echo esc_html( vahpp_t( 'ДИКТУВАТИ', 'DICTATE' ) ); ?></button>
		<button type="button" id="va_save" class="va-btn va-save">➕ <?php echo esc_html( $draft ? vahpp_t( 'ЗБЕРЕГТИ ЯК ЧЕРНЕТКУ', 'SAVE AS DRAFT' ) : vahpp_t( 'ОПУБЛІКУВАТИ', 'PUBLISH' ) ); ?></button>
		<div id="va_msg" class="va-msg"></div>

		<!-- CSV -->
		<div class="va-card" id="va_csv">
			<h2>📥 <?php echo esc_html( vahpp_t( 'Імпорт / експорт CSV', 'CSV import / export' ) ); ?></h2>
			<p class="va-muted"><?php echo esc_html( vahpp_t( 'Колонки: див. інструкцію нижче. Роздільник «;» або «,» визначається автоматично.', 'Columns: see the instructions below. Delimiter “;” or “,” is detected automatically.' ) ); ?></p>
			<div class="va-row">
				<input type="file" id="va_csv_file" accept=".csv,text/csv">
				<button type="button" id="va_import" class="va-btn va-blue">📥 <?php echo esc_html( vahpp_t( 'Імпортувати CSV', 'Import CSV' ) ); ?></button>
			</div>
			<p>
				<a class="button" href="<?php echo esc_url( $exp_url . '&tpl=1' ); ?>">⬇️ <?php echo esc_html( vahpp_t( 'Шаблон CSV', 'CSV template' ) ); ?></a>
				<a class="button" href="<?php echo esc_url( $exp_url . '&sep=semi' ); ?>">📤 <?php echo esc_html( vahpp_t( 'Експорт (Excel «;»)', 'Export (Excel “;”)' ) ); ?></a>
				<a class="button" href="<?php echo esc_url( $exp_url . '&sep=comma' ); ?>">📤 <?php echo esc_html( vahpp_t( 'Експорт («,»)', 'Export (“,”)' ) ); ?></a>
			</p>
			<div id="va_csv_msg" class="va-msg"></div>
		</div>

		<!-- ІНСТРУКЦІЇ -->
		<div id="va_instr" class="va-instr-wrap">
			<h2>📖 <?php echo esc_html( vahpp_t( 'Інструкції', 'Instructions' ) ); ?></h2>
			<?php vahpp_render_instructions(); ?>
		</div>
	</div>
	<?php
}

/* ====================================================================== */
/* 10. ІНСТРУКЦІЇ (поля підтягуються з магазину / HivePress)               */
/* ====================================================================== */
function vahpp_instr_open( $id, $title ) {
	echo '<section class="va-instr" id="' . esc_attr( $id ) . '"><div class="va-instr-head"><h3>' . esc_html( $title ) . '</h3>';
	echo '<button type="button" class="button va-print" data-target="' . esc_attr( $id ) . '">🖨️ ' . esc_html( vahpp_t( 'Роздрукувати', 'Print' ) ) . '</button></div><div class="va-instr-body">';
}
function vahpp_instr_close() { echo '</div></section>'; }

function vahpp_render_instructions() {
	// 1. Як диктувати
	vahpp_instr_open( 'va_i_how', vahpp_t( '1. Як диктувати', '1. How to dictate' ) );
	echo '<ol>';
	echo '<li>' . esc_html( vahpp_t( 'Натисніть «ДИКТУВАТИ» і дозвольте мікрофон (потрібен HTTPS і браузер Chrome).', 'Press “DICTATE” and allow the microphone (HTTPS and Chrome are required).' ) ) . '</li>';
	echo '<li>' . esc_html( vahpp_t( 'Скажіть слово-маяк, наприклад «Назва». Курсор одразу з’явиться в цьому рядку після двокрапки.', 'Say a beacon word, e.g. “Name”. The cursor jumps to that line right after the colon.' ) ) . '</li>';
	echo '<li>' . esc_html( vahpp_t( 'Скажіть значення — воно запишеться саме в цей рядок (напр. «холодильник Samsung»).', 'Say the value — it is written into that line (e.g. “Samsung refrigerator”).' ) ) . '</li>';
	echo '<li>' . esc_html( vahpp_t( 'Скажіть наступний маяк — курсор перейде далі. Якщо повторити маяк, старе значення замінюється новим.', 'Say the next beacon — the cursor moves on. Repeating a beacon replaces the old value.' ) ) . '</li>';
	echo '<li>' . esc_html( vahpp_t( 'Для списків (мітки) говоріть значення з паузами або кажіть «кома».', 'For lists (tags) speak values with pauses or say “comma”.' ) ) . '</li>';
	echo '<li><b>' . esc_html( vahpp_t( 'Атрибути:', 'Attributes:' ) ) . '</b> ' . esc_html( vahpp_t( 'у вікні лише маяк «Атрибут». Кажіть: «Атрибут колір значення червоний значення жовтий Атрибут стан значення новий» — плагін сам створить рядки «Атрибут Колір: червоний, жовтий» та «Атрибут Стан: новий».', 'the window holds only the “Attribute” beacon. Say: “Attribute color value red value yellow Attribute condition value new” — the plugin creates the lines “Attribute Color: red, yellow” and “Attribute Condition: new”.' ) ) . '</li>';
	echo '<li>' . esc_html( vahpp_t( 'Текст у полі можна виправляти вручну в будь-який момент.', 'You can edit the text by hand at any moment.' ) ) . '</li>';
	echo '</ol>';
	echo '<p><strong>' . esc_html( vahpp_t( 'Приклад:', 'Example:' ) ) . '</strong> ' . esc_html( vahpp_t( '«Назва» → «холодильник Samsung» → «Ціна» → «15000» → «Категорія» → «Побутова техніка»', '“Name” → “Samsung refrigerator” → “Price” → “15000” → “Category” → “Appliances”' ) ) . '</p>';
	vahpp_instr_close();

	// 2. Таблиця маяків
	vahpp_instr_open( 'va_i_fields', vahpp_t( '2. Маяки (поля)', '2. Beacons (fields)' ) );
	echo '<table class="va-table"><thead><tr><th>' . esc_html( vahpp_t( 'Скажіть', 'Say' ) ) . '</th><th>' . esc_html( vahpp_t( 'Що записується', 'What is saved' ) ) . '</th><th>' . esc_html( vahpp_t( 'Приклад значення', 'Example value' ) ) . '</th></tr></thead><tbody>';
	foreach ( vahpp_fields() as $d ) {
		$ex   = isset( $d['ex'] ) ? ( vahpp_en() ? $d['ex'][1] : $d['ex'][0] ) : '';
		$desc = isset( $d['desc'] ) ? ( vahpp_en() ? $d['desc'][1] : $d['desc'][0] ) : '';
		echo '<tr><td><b>' . esc_html( vahpp_label( $d ) ) . '</b></td><td>' . esc_html( $desc ) . '</td><td class="va-muted">' . esc_html( $ex ) . '</td></tr>';
	}
	echo '</tbody></table>';
	vahpp_instr_close();

	// 3+. Блоки конкретного плагіна (атрибути, варіації, категорії…)
	foreach ( vahpp_extra_instructions() as $blk ) {
		vahpp_instr_open( $blk['id'], $blk['title'] );
		echo $blk['html']; // phpcs:ignore -- HTML сформовано в плагіні, значення екрановано
		vahpp_instr_close();
	}

	// CSV
	vahpp_instr_open( 'va_i_csv', vahpp_t( 'Імпорт та експорт CSV', 'CSV import and export' ) );
	$cols = vahpp_csv_columns();
	echo '<p>' . esc_html( vahpp_t( 'Перший рядок — назви колонок. Підтримуються такі колонки (регістр не важливий):', 'The first row holds column names. Supported columns (case-insensitive):' ) ) . '</p>';
	echo '<p><code>' . esc_html( implode( ' ; ', array_map( function ( $c ) { return $c[0]; }, $cols ) ) ) . '</code></p>';
	echo '<p>' . esc_html( vahpp_t( 'Кілька значень (категорії, мітки, значення атрибутів) розділяйте комою. Колонка Images — посилання на фото через кому. Натисніть «Шаблон CSV», щоб завантажити готовий приклад.', 'Separate multiple values (categories, tags, attribute values) with commas. The Images column holds photo URLs separated by commas. Press “CSV template” to download a ready example.' ) ) . '</p>';
	vahpp_instr_close();

	// Фон
	vahpp_instr_open( 'va_i_bg', vahpp_t( 'Фото та прибирання фону', 'Photos and background removal' ) );
	echo '<ul><li>' . esc_html( vahpp_t( 'Кнопка «ФОТО» знімає з камери, «З ГАЛЕРЕЇ» — додає готові фото. Натисніть на мініатюру, щоб видалити.', '“PHOTO” shoots from the camera, “GALLERY” adds existing photos. Tap a thumbnail to delete it.' ) ) . '</li>';
	echo '<li>' . esc_html( vahpp_t( 'Фон: «Оригінал» — без змін; «Прозорий» — PNG без фону; «Білий» — чисто білий фон. Працює через remove.bg (ключ у налаштуваннях).', 'Background: “Original” — unchanged; “Transparent” — PNG with no background; “White” — plain white. Powered by remove.bg (key in settings).' ) ) . '</li>';
	echo '<li>' . esc_html( vahpp_t( 'Перше фото стає головним.', 'The first photo becomes the main one.' ) ) . '</li></ul>';
	vahpp_instr_close();
}

/* ====================================================================== */
/* 11. AJAX                                                                */
/* ====================================================================== */
function vahpp_ajax_guard() {
	if ( ! check_ajax_referer( 'vahp_nonce', 'nonce', false ) ) {
		wp_send_json_error( vahpp_t( 'Сесія застаріла — оновіть сторінку', 'Session expired — reload the page' ) );
	}
	if ( ! current_user_can( 'vahp_use' ) ) {
		wp_send_json_error( vahpp_t( 'Немає прав', 'No permission' ) );
	}
}

add_action( 'wp_ajax_vahp_save', function () {
	check_ajax_referer( 'vahp_nonce', 'nonce' );
	vahpp_ajax_guard();
	if ( ! vahpp_can_create( 1 ) ) {
		wp_send_json_error( vahpp_t( 'Безкоштовний ліміт вичерпано. Придбайте PRO.', 'Free limit reached. Please buy PRO.' ) );
	}
	$raw  = sanitize_textarea_field( wp_unslash( isset( $_POST['text'] ) ? $_POST['text'] : '' ) );
	$imgs = json_decode( sanitize_text_field( wp_unslash( isset( $_POST['images'] ) ? $_POST['images'] : '[]' ) ), true );
	$imgs = is_array( $imgs ) ? $imgs : array();

	$res = vahpp_create_item( vahpp_parse_text( $raw ), $imgs, array() );
	if ( is_wp_error( $res ) ) {
		wp_send_json_error( $res->get_error_message() );
	}
	if ( ! vahpp_is_pro() ) {
		vahpp_inc( 1 );
	}
	$res['left'] = vahpp_is_pro() ? -1 : vahpp_remaining();
	wp_send_json_success( $res );
} );

add_action( 'wp_ajax_vahp_remove_bg', function () {
	vahpp_ajax_guard();
	$key = (string) get_option( 'vahp_rbg_api', '' );
	if ( '' === $key ) {
		wp_send_json_error( 'No API key' );
	}
	$img = wp_unslash( isset( $_POST['image'] ) ? $_POST['image'] : '' );
	$bg  = isset( $_POST['bg'] ) ? sanitize_key( $_POST['bg'] ) : 'transparent';
	$p   = is_string( $img ) ? strpos( $img, 'base64,' ) : false;
	if ( false === $p ) {
		wp_send_json_error( 'Bad image' );
	}
	$body = array( 'image_file_b64' => substr( $img, $p + 7 ), 'size' => 'auto', 'format' => 'png' );
	if ( 'ffffff' === $bg ) {
		$body['bg_color'] = 'ffffff';
	}
	$r = wp_remote_post( 'https://api.remove.bg/v1.0/removebg', array( 'timeout' => 90, 'headers' => array( 'X-Api-Key' => $key ), 'body' => $body ) );
	if ( is_wp_error( $r ) ) {
		wp_send_json_error( $r->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $r );
	if ( 200 !== $code ) {
		$j   = json_decode( wp_remote_retrieve_body( $r ), true );
		$err = isset( $j['errors'][0]['title'] ) ? $j['errors'][0]['title'] : 'HTTP ' . $code;
		wp_send_json_error( $err );
	}
	wp_send_json_success( array( 'image' => 'data:image/png;base64,' . base64_encode( wp_remote_retrieve_body( $r ) ) ) );
} );

/* ---------- CSV ---------- */
/** Нормалізований заголовок колонки → ключ поля. */
function vahpp_csv_header_map( array $header ) {
	$map   = vahpp_field_map();
	$extra = vahpp_csv_extra_aliases();
	$out   = array();
	foreach ( $header as $i => $h ) {
		$n = vahpp_norm( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) );
		if ( isset( $extra[ $n ] ) ) {
			$out[ $i ] = $extra[ $n ];
		} elseif ( isset( $map[ $n ] ) ) {
			$out[ $i ] = $map[ $n ];
		}
	}
	return $out;
}

/** Імпорт із вже прочитаного тексту CSV (використовується AJAX-обробником і тестами). */
function vahpp_import_csv_text( $content ) {
	$content = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $content );
	if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) {
		$content = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $content, 'UTF-8', 'Windows-1251' ) : ( function_exists( 'iconv' ) ? iconv( 'Windows-1251', 'UTF-8//IGNORE', $content ) : $content );
	}
	$first = strtok( $content, "\n" );
	$delim = ( substr_count( (string) $first, ';' ) >= substr_count( (string) $first, ',' ) ) ? ';' : ',';

	$fh = fopen( 'php://temp', 'r+' );
	fwrite( $fh, $content );
	rewind( $fh );
	$header = fgetcsv( $fh, 0, $delim );
	if ( ! $header ) {
		return new WP_Error( 'empty', vahpp_t( 'Порожній файл', 'Empty file' ) );
	}
	$hmap = vahpp_csv_header_map( $header );
	if ( ! $hmap ) {
		return new WP_Error( 'cols', vahpp_t( 'Не знайдено відомих колонок. Завантажте шаблон CSV.', 'No known columns found. Download the CSV template.' ) );
	}
	@set_time_limit( 300 );
	$created = 0; $failed = 0; $errors = array(); $stopped = false; $rows = 0;
	while ( ( $row = fgetcsv( $fh, 0, $delim ) ) !== false && $rows < 500 ) {
		if ( 1 === count( $row ) && '' === trim( (string) $row[0] ) ) {
			continue;
		}
		$rows++;
		if ( ! vahpp_can_create( 1 ) ) {
			$stopped = true;
			break;
		}
		$data = array(); $urls = array();
		foreach ( $hmap as $i => $key ) {
			$v = isset( $row[ $i ] ) ? trim( (string) $row[ $i ] ) : '';
			if ( '' === $v ) {
				continue;
			}
			if ( '__images' === $key ) {
				$urls = array_filter( array_map( 'trim', explode( ',', $v ) ) );
			} else {
				$data[ $key ] = $v;
			}
		}
		$res = vahpp_create_item( array( 'data' => $data, 'rep' => array(), 'custom' => array() ), array(), $urls );
		if ( is_wp_error( $res ) ) {
			$failed++;
			if ( count( $errors ) < 5 ) {
				$errors[] = '#' . $rows . ': ' . $res->get_error_message();
			}
			continue;
		}
		$created++;
		if ( ! vahpp_is_pro() ) {
			vahpp_inc( 1 );
		}
	}
	fclose( $fh );
	return array( 'created' => $created, 'failed' => $failed, 'errors' => $errors, 'stopped' => $stopped, 'left' => vahpp_is_pro() ? -1 : vahpp_remaining() );
}

add_action( 'wp_ajax_vahp_import', function () {
	vahpp_ajax_guard();
	if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
		wp_send_json_error( vahpp_t( 'Файл не отримано', 'No file received' ) );
	}
	if ( ! preg_match( '/\.csv$/i', (string) $_FILES['file']['name'] ) || (int) $_FILES['file']['size'] > 5 * MB_IN_BYTES ) {
		wp_send_json_error( vahpp_t( 'Потрібен файл .csv до 5 МБ', 'A .csv file up to 5 MB is required' ) );
	}
	$res = vahpp_import_csv_text( file_get_contents( $_FILES['file']['tmp_name'] ) );
	if ( is_wp_error( $res ) ) {
		wp_send_json_error( $res->get_error_message() );
	}
	wp_send_json_success( $res );
} );

add_action( 'wp_ajax_vahp_export', function () {
	if ( ! check_ajax_referer( 'vahp_nonce', 'nonce', false ) || ! current_user_can( 'vahp_use' ) ) {
		wp_die( 'Forbidden', 403 );
	}
	$sep  = ( isset( $_GET['sep'] ) && 'comma' === $_GET['sep'] ) ? ',' : ';';
	$tpl  = ! empty( $_GET['tpl'] );
	$cols = vahpp_csv_columns();
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="voice-add-hivepress-' . ( $tpl ? 'template' : 'export' ) . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" );
	fputcsv( $out, array_map( function ( $c ) { return $c[0]; }, $cols ), $sep );
	foreach ( $tpl ? vahpp_csv_example_rows( $cols ) : vahpp_csv_export_rows( $cols ) as $r ) {
		fputcsv( $out, $r, $sep );
	}
	fclose( $out );
	exit;
} );
