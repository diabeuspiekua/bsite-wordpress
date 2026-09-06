<?php
/**
 * Przegląd witryny - liczby na kartę w apce.
 *
 * ═══ CO TO JEST I CZYM NIE JEST ═══
 *
 * To NIE jest moduł. Moduły (treść, zgłoszenia, opinie) dają dostęp do rzeczy;
 * przegląd odpowiada na jedno pytanie: CZY COŚ NA MNIE CZEKA. Apka pyta o to
 * wszystkie witryny naraz i układa z odpowiedzi pierwszy ekran - jedyną rzecz,
 * której panel WordPressa nie umie, bo panel zawsze dotyczy jednej strony.
 *
 * ═══ SAME LICZBY, ŻADNEJ TREŚCI ═══
 *
 * Nie wysyłamy tytułów, nazwisk ani adresów - wyłącznie ile czego jest. Powód jest
 * podwójny. Pierwszy: karta w apce i tak pokazuje liczbę, więc treść byłaby wysyłana
 * po nic. Drugi: przegląd jest odpytywany dla WSZYSTKICH witryn przy każdym otwarciu
 * apki, więc musi być tani po obu stronach łącza.
 *
 * ═══ LICZYMY TYLKO TO, CO WOLNO ZOBACZYĆ ═══
 *
 * Każda liczba ma własny warunek uprawnień. Redaktor bez `manage_options` nie dostaje
 * liczby zgłoszeń - dostaje `null`, a nie zero. To jest różnica, którą apka MUSI
 * widzieć: zero znaczy „sprawdziłem, nic nie ma", a `null` znaczy „nie wolno mi
 * sprawdzić". Zero w miejscu braku uprawnień byłoby cichym kłamstwem.
 *
 * @package bsite
 */

declare( strict_types=1 );

namespace BSite\Przeglad;

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'bsite/v1', '/przeglad', array(
		'methods' => 'GET',
		/* Zamknięte na głucho, w przeciwieństwie do manifestu. Manifest ma część
		   publiczną, bo apka musi rozpoznać witrynę PRZED zalogowaniem. Przegląd
		   nie ma nic do powiedzenia komuś z zewnątrz. */
		'permission_callback' => static fn (): bool => is_user_logged_in(),
		'callback'            => __NAMESPACE__ . '\\trasa',
	) );
} );

function trasa( \WP_REST_Request $zadanie ): \WP_REST_Response {
	$odpowiedz = new \WP_REST_Response( zbuduj() );
	$odpowiedz->header( 'Cache-Control', 'private, max-age=0, no-store' );
	return $odpowiedz;
}

function zbuduj(): array {
	return array(
		'wersja_umowy'  => BSITE_UMOWA,
		'policzono'     => gmdate( 'c' ),
		'szkice'        => szkice(),
		'zaplanowane'   => zaplanowane(),
		'komentarze'    => komentarze(),
		'zgloszenia'    => zgloszenia(),
		'ostatnia_publikacja' => ostatnia_publikacja(),
		'aktualizacje'  => aktualizacje(),
	);
}

/**
 * Szkice, które ktoś zaczął i zostawił.
 *
 * `wp_count_posts` liczy WSZYSTKIE szkice na witrynie, także cudze. Autor widzi
 * w panelu tylko swoje, więc pokazanie mu liczby obejmującej cudze byłoby liczbą,
 * której nie da się z niczym zestawić. Dlatego bez `edit_others_posts` liczymy
 * wyłącznie własne.
 */
function szkice(): ?int {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return null;
	}

	$argumenty = array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'draft',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => false,
	);
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		$argumenty['author'] = get_current_user_id();
	}

	$zapytanie = new \WP_Query( $argumenty );
	return (int) $zapytanie->found_posts;
}

/** Wpisy z datą w przyszłości - te opublikują się same i warto o nich wiedzieć. */
function zaplanowane(): ?int {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return null;
	}
	$zapytanie = new \WP_Query( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'future',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );
	return (int) $zapytanie->found_posts;
}

/** Komentarze czekające na zatwierdzenie. */
function komentarze(): ?int {
	if ( ! current_user_can( 'moderate_comments' ) ) {
		return null;
	}
	$liczby = wp_count_comments();
	return (int) ( $liczby->moderated ?? 0 );
}

/**
 * Nowe zgłoszenia z formularza.
 *
 * Typ `sm_zgloszenie` należy do motywu sitemanagera, nie do WordPressa - więc pytamy
 * o niego tylko wtedy, gdy istnieje. Na witrynie bez tego typu wynik to `null`, czyli
 * „nie ma czego liczyć", a nie zero.
 */
function zgloszenia(): ?int {
	if ( ! post_type_exists( 'sm_zgloszenie' ) || ! current_user_can( 'manage_options' ) ) {
		return null;
	}
	$zapytanie = new \WP_Query( array(
		'post_type'      => 'sm_zgloszenie',
		'post_status'    => array( 'publish', 'pending', 'draft' ),
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
			'relation' => 'OR',
			array( 'key' => 'sm_obsluzone', 'compare' => 'NOT EXISTS' ),
			array( 'key' => 'sm_obsluzone', 'value' => '', 'compare' => '=' ),
		),
	) );
	return (int) $zapytanie->found_posts;
}

/** Kiedy ostatnio coś wyszło. Sama data, bez tytułu. */
function ostatnia_publikacja(): ?string {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return null;
	}
	$ostatnie = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
	if ( empty( $ostatnie ) ) {
		return null;
	}
	return get_post_time( 'c', true, $ostatnie[0] ) ?: null;
}

/**
 * Aktualizacje: rdzeń, wtyczki, motywy.
 *
 * ═══ NIE WYMUSZAMY SPRAWDZENIA ═══
 *
 * `wp_update_plugins()` odpytuje wordpress.org - przy przeglądzie odpytywanym dla
 * każdej witryny przy każdym otwarciu apki to byłoby żądanie do wordpress.org za
 * każdym razem, z witryny klienta. Czytamy więc TO, CO WORDPRESS JUŻ WIE: wynik
 * własnego, cyklicznego sprawdzania. Liczba bywa o kilka godzin stara i to jest
 * właściwy kompromis.
 */
function aktualizacje(): ?array {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return null;
	}

	$wtyczki = get_site_transient( 'update_plugins' );
	$motywy  = get_site_transient( 'update_themes' );
	$rdzen   = get_site_transient( 'update_core' );

	$rdzen_czeka = false;
	if ( isset( $rdzen->updates ) && is_array( $rdzen->updates ) ) {
		foreach ( $rdzen->updates as $u ) {
			if ( isset( $u->response ) && 'upgrade' === $u->response ) {
				$rdzen_czeka = true;
				break;
			}
		}
	}

	return array(
		'rdzen'   => $rdzen_czeka,
		'wtyczki' => isset( $wtyczki->response ) ? count( (array) $wtyczki->response ) : 0,
		'motywy'  => isset( $motywy->response ) ? count( (array) $motywy->response ) : 0,
	);
}
