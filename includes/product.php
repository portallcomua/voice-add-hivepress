<?php
/**
 * Voice ADD for HivePress — частина, що працює з оголошеннями HivePress.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_notices', function () {
	if ( ! post_type_exists( 'hp_listing' ) && current_user_can( 'manage_options' ) && did_action( 'init' ) ) {
		echo '<div class="notice notice-error"><p><strong>Voice ADD for HivePress:</strong> ' . esc_html( vahpp_t( 'потрібен активний плагін HivePress.', 'the HivePress plugin must be active.' ) ) . '</p></div>';
	}
} );

function vahpp_role_caps() {
	return array(
		'read'                  => true,
		'upload_files'          => true,
		'edit_posts'            => true,
		'edit_published_posts'  => true,
		'publish_posts'         => true,
	);
}

/* ---------------------------- ПОЛЯ ---------------------------- */
function vahpp_base_fields() {
	return array(
		array( 'key' => 'title', 'ua' => 'Заголовок', 'en' => 'Title', 'aliases' => array( 'назва', 'name', 'название', 'заголовок оголошення' ), 'kind' => 'text', 'tpl' => true,
			'ex' => array( 'Продаю велосипед', 'Selling a bike' ), 'desc' => array( 'Заголовок оголошення', 'Listing title' ) ),
		array( 'key' => 'category', 'ua' => 'Категорія', 'en' => 'Category', 'aliases' => array( 'категорії', 'categories', 'категория' ), 'kind' => 'list', 'join' => ', ', 'tpl' => true,
			'ex' => array( 'Транспорт > Велосипеди', 'Transport > Bikes' ), 'desc' => array( 'Категорія оголошення (вкладену — через «>»)', 'Listing category (nested with “>”)' ) ),
		array( 'key' => 'description', 'ua' => 'Опис', 'en' => 'Description', 'aliases' => array( 'описание', 'content', 'текст' ), 'kind' => 'text', 'tpl' => true,
			'ex' => array( 'Майже новий, мало їздили', 'Almost new, barely used' ), 'desc' => array( 'Текст оголошення', 'Listing text' ) ),
		array( 'key' => 'attribute', 'ua' => 'Атрибут', 'en' => 'Attribute', 'aliases' => array( 'атрибути', 'attributes' ), 'kind' => 'attr', 'tpl' => true,
			'ex' => array( 'Атрибут місто значення Київ', 'Attribute city value Kyiv' ), 'desc' => array( 'Скажіть «Атрибут», назву поля HivePress, слово «значення» і значення; можна повторювати', 'Say “Attribute”, the HivePress field name, the word “value” and the value; repeatable' ) ),
	);
}

/**
 * Атрибути, створені в HivePress (Оголошення → Атрибути).
 * Беремо готовий список із HivePress; якщо API недоступне — читаємо записи hp_listing_attribute.
 * Повертає: [ slug, label, tax (таксономія варіантів або ''), meta (ключ мета-поля) ].
 */
function vahpp_attribute_list() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$cache = array();
	if ( function_exists( 'hivepress' ) && isset( hivepress()->attribute ) && method_exists( hivepress()->attribute, 'get_attributes' ) ) {
		foreach ( (array) hivepress()->attribute->get_attributes( 'listing' ) as $name => $a ) {
			$tax     = taxonomy_exists( 'hp_listing_' . $name ) ? 'hp_listing_' . $name : '';
			$cache[] = array( 'slug' => $name, 'label' => isset( $a['label'] ) ? $a['label'] : $name, 'tax' => $tax, 'meta' => 'hp_' . $name );
		}
		if ( $cache ) {
			return $cache;
		}
	}
	if ( post_type_exists( 'hp_listing_attribute' ) ) {
		$posts = get_posts( array( 'post_type' => 'hp_listing_attribute', 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'menu_order', 'order' => 'ASC' ) );
		foreach ( $posts as $post ) {
			$base    = str_replace( '-', '_', sanitize_title( urldecode( $post->post_name ) ) );
			$tname   = substr( $base, 0, 21 );
			$tax     = taxonomy_exists( 'hp_listing_' . $tname ) ? 'hp_listing_' . $tname : '';
			$cache[] = array( 'slug' => $tax ? $tname : substr( $base, 0, 29 ), 'label' => $post->post_title, 'tax' => $tax, 'meta' => 'hp_' . substr( $base, 0, 29 ) );
		}
	}
	return $cache;
}

function vahpp_dynamic_fields() {
	return array(); // атрибути диктуються єдиним маяком «Атрибут»
}

/** Атрибути HivePress у форматі, спільному з ядром плагіна. */
function vahpp_attr_defs() {
	$out = array();
	foreach ( vahpp_attribute_list() as $a ) {
		$out[] = array( 'key' => 'attr:' . $a['slug'], 'slug' => $a['slug'], 'label' => $a['label'], 'tax' => $a['tax'], 'meta' => $a['meta'] );
	}
	return $out;
}

function vahpp_attr_by_key( $key ) {
	foreach ( vahpp_attr_defs() as $a ) {
		if ( $a['key'] === $key ) {
			return $a;
		}
	}
	return null;
}

/* ---------------------------- ІНСТРУКЦІЇ ---------------------------- */
function vahpp_extra_instructions() {
	$blocks = array();

	ob_start();
	$list = vahpp_attribute_list();
	if ( $list ) {
		echo '<table class="va-table"><thead><tr><th>' . esc_html( vahpp_t( 'Скажіть', 'Say' ) ) . '</th><th>' . esc_html( vahpp_t( 'Тип', 'Type' ) ) . '</th><th>' . esc_html( vahpp_t( 'Варіанти у вашому HivePress', 'Options in your HivePress' ) ) . '</th></tr></thead><tbody>';
		foreach ( $list as $a ) {
			$t = $a['tax'] ? get_terms( array( 'taxonomy' => $a['tax'], 'hide_empty' => false, 'number' => 30, 'fields' => 'names' ) ) : array();
			$t = is_wp_error( $t ) ? array() : $t;
			echo '<tr><td><b>' . esc_html( vahpp_t( 'Атрибут ', 'Attribute ' ) . $a['label'] ) . '</b><br><span class="va-muted">' . esc_html( vahpp_t( 'значення ', 'value ' ) ) . '…</span></td><td>' . esc_html( $a['tax'] ? vahpp_t( 'вибір', 'choice' ) : vahpp_t( 'текст / число', 'text / number' ) ) . '</td><td>' . ( $t ? esc_html( implode( ', ', $t ) ) : '<span class="va-muted">—</span>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p>' . esc_html( vahpp_t( 'Атрибути HivePress не знайдено (Оголошення → Атрибути). Доступні лише базові поля.', 'No HivePress attributes found (Listings → Attributes). Only the basic fields are available.' ) ) . '</p>';
	}
	echo '<p>' . esc_html( vahpp_t( 'Атрибути спочатку створює адміністратор у HivePress (Оголошення → Атрибути) — вони автоматично з’являються в цій інструкції та працюють як маяки: «Місто Київ» = «Атрибут місто значення Київ». Нові значення (варіанти вибору), категорії створюються автоматично.', 'Attributes are first created by the administrator in HivePress (Listings → Attributes) — they appear in these instructions automatically and work as beacons: “City Kyiv” = “Attribute city value Kyiv”. New option values and categories are created automatically.' ) ) . '</p><p>' . esc_html( vahpp_t( 'Як диктувати:', 'How to dictate:' ) ) . '</p><pre class="va-pre">' . esc_html( vahpp_t( 'Атрибут місто значення Київ Атрибут стан значення новий', 'Attribute city value Kyiv Attribute condition value new' ) ) . '</pre>';
	$blocks[] = array( 'id' => 'va_i_attrs', 'title' => vahpp_t( 'Атрибути оголошень (з HivePress)', 'Listing attributes (from HivePress)' ), 'html' => ob_get_clean() );

	ob_start();
	$cats = taxonomy_exists( 'hp_listing_category' ) ? get_terms( array( 'taxonomy' => 'hp_listing_category', 'hide_empty' => false, 'number' => 60, 'fields' => 'names' ) ) : array();
	if ( $cats && ! is_wp_error( $cats ) ) {
		echo '<p>' . esc_html( implode( ' · ', $cats ) ) . '</p>';
	} else {
		echo '<p>' . esc_html( vahpp_t( 'Категорій ще немає — нові створюються автоматично.', 'No categories yet — new ones are created automatically.' ) ) . '</p>';
	}
	$blocks[] = array( 'id' => 'va_i_cats', 'title' => vahpp_t( 'Ваші категорії', 'Your categories' ), 'html' => ob_get_clean() );

	return $blocks;
}

/* ---------------------------- CSV ---------------------------- */
function vahpp_csv_columns() {
	$cols = array();
	foreach ( array( 'title', 'category', 'description' ) as $k ) {
		$cols[] = array( vahpp_label( vahpp_field_def( $k ) ), $k );
	}
	foreach ( vahpp_attr_defs() as $a ) {
		$cols[] = array( vahpp_t( 'Атрибут ', 'Attribute ' ) . $a['label'], $a['key'] );
	}
	$cols[] = array( 'Images', '__images' );
	return $cols;
}
function vahpp_csv_extra_aliases() {
	$m = array( 'images' => '__images', 'image' => '__images', 'фото' => '__images', 'зображення' => '__images', 'картинки' => '__images', 'pictures' => '__images' );
	foreach ( vahpp_attr_defs() as $a ) {
		$m[ vahpp_norm( 'Атрибут ' . $a['label'] ) ]   = $a['key'];
		$m[ vahpp_norm( 'Attribute ' . $a['label'] ) ] = $a['key'];
	}
	return $m;
}
function vahpp_csv_example_rows( $cols ) {
	$ex  = array( 'title' => 'Продаю велосипед', 'category' => 'Транспорт > Велосипеди', 'description' => 'Майже новий', '__images' => 'https://example.com/photo1.jpg' );
	$row = array();
	foreach ( $cols as $c ) {
		$row[] = isset( $ex[ $c[1] ] ) ? $ex[ $c[1] ] : '';
	}
	return array( $row );
}
function vahpp_csv_export_rows( $cols ) {
	$rows  = array();
	$posts = get_posts( array( 'post_type' => 'hp_listing', 'post_status' => array( 'publish', 'draft', 'pending' ), 'numberposts' => 2000, 'orderby' => 'date', 'order' => 'DESC' ) );
	foreach ( $posts as $post ) {
		$imgs = array();
		if ( has_post_thumbnail( $post ) ) {
			$imgs[] = get_the_post_thumbnail_url( $post, 'full' );
		}
		$vals = array(
			'title'       => $post->post_title,
			'category'    => implode( ', ', (array) wp_get_object_terms( $post->ID, 'hp_listing_category', array( 'fields' => 'names' ) ) ),
			'description' => $post->post_content,
			'__images'    => implode( ', ', array_filter( $imgs ) ),
		);
		foreach ( vahpp_attr_defs() as $a ) {
			$vals[ $a['key'] ] = $a['tax'] ? implode( ', ', (array) wp_get_object_terms( $post->ID, $a['tax'], array( 'fields' => 'names' ) ) ) : (string) get_post_meta( $post->ID, $a['meta'], true );
		}
		$row = array();
		foreach ( $cols as $c ) {
			$v     = isset( $vals[ $c[1] ] ) ? $vals[ $c[1] ] : '';
			$row[] = is_string( $v ) && preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v;
		}
		$rows[] = $row;
	}
	return $rows;
}

/* ---------------------------- СТВОРЕННЯ ОГОЛОШЕННЯ ---------------------------- */
function vahpp_create_item( $p, $imgs = array(), $urls = array() ) {
	if ( ! post_type_exists( 'hp_listing' ) ) {
		return new WP_Error( 'hp', vahpp_t( 'HivePress не активний', 'HivePress is not active' ) );
	}
	$d    = $p['data'];
	$warn = array();
	if ( empty( $d['title'] ) ) {
		return new WP_Error( 'title', vahpp_t( 'Вкажіть заголовок (маяк «Заголовок»)', 'Enter the title (beacon “Title”)' ) );
	}
	$pid = wp_insert_post( array(
		'post_type'    => 'hp_listing',
		'post_title'   => sanitize_text_field( $d['title'] ),
		'post_content' => isset( $d['description'] ) ? wp_kses_post( $d['description'] ) : '',
		'post_status'  => '1' === get_option( 'vahp_draft', '0' ) ? 'draft' : 'publish',
		'post_author'  => get_current_user_id(),
	), true );
	if ( is_wp_error( $pid ) || ! $pid ) {
		return new WP_Error( 'save', vahpp_t( 'Не вдалося створити оголошення', 'Could not create the listing' ) );
	}

	// Категорії
	if ( ! empty( $d['category'] ) && taxonomy_exists( 'hp_listing_category' ) ) {
		$tids = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $d['category'] ) ) ) as $path ) {
			$t = vahpp_term_path( $path, 'hp_listing_category' );
			if ( $t ) {
				$tids[] = $t;
			}
		}
		if ( $tids ) {
			wp_set_object_terms( $pid, $tids, 'hp_listing_category' );
		}
	}

	// Атрибути зі слів диктовки → поля HivePress
	foreach ( (array) $p['custom'] as $cname => $cval ) {
		$ck = vahpp_resolve_attr( $cname );
		if ( $ck ) {
			$d[ $ck ] = isset( $d[ $ck ] ) ? $d[ $ck ] . ', ' . $cval : $cval;
		} else {
			$warn[] = vahpp_t( 'Атрибут не знайдено в HivePress: ', 'Attribute not found in HivePress: ' ) . $cname;
		}
	}

	// Атрибути HivePress
	foreach ( $d as $k => $v ) {
		if ( 0 !== strpos( $k, 'attr:' ) ) {
			continue;
		}
		$a = vahpp_attr_by_key( $k );
		if ( ! $a ) {
			continue;
		}
		if ( $a['tax'] ) {
			$names = array_filter( array_map( 'trim', preg_split( '/[,;]+/u', $v ) ) );
			$tids  = vahpp_term_ids( $names, $a['tax'] );
			if ( $tids ) {
				wp_set_object_terms( $pid, $tids, $a['tax'] );
			}
		} else {
			update_post_meta( $pid, $a['meta'], sanitize_text_field( $v ) );
		}
	}

	// Фото
	$aids = array_merge( vahpp_save_images( $pid, $imgs ), vahpp_sideload_images( $pid, $urls ) );
	if ( $aids ) {
		set_post_thumbnail( $pid, $aids[0] );
		foreach ( $aids as $i => $aid ) {
			update_post_meta( $aid, 'hp_sort_order', $i + 1 );
		}
	}

	return array( 'id' => $pid, 'title' => get_the_title( $pid ), 'edit' => get_edit_post_link( $pid, 'raw' ), 'variations' => 0, 'warnings' => $warn );
}
