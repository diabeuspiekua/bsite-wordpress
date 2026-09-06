<?php
/**
 * Dane JEDNEGO wpisu - do pulpitu wpisu.
 *
 * ═══ PO CO OSOBNA TRASA ═══
 *
 * Ekran wpisu pokazywał surowe znaczniki bloków. To jest przydatne raz na jakiś czas -
 * i dlatego zostaje, ale schowane. Pytanie, które człowiek naprawdę ma, otwierając wpis,
 * brzmi inaczej: czy ten tekst pracuje. Ile go czytają, skąd przychodzą, czy nie brakuje
 * mu zajawki albo obrazka, jak wypada w pomiarze.
 *
 * Rdzeń WordPressa nie wie nic o ruchu, a analityka nie wie nic o brakach w treści.
 * Ta trasa jest miejscem, w którym jedno spotyka się z drugim - po stronie witryny,
 * w jednym żądaniu zamiast czterech.
 *
 * @package bsite
 */

declare( strict_types=1 );

namespace BSite\WpisDane;

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'bsite/v1', '/wpis-dane', array(
		'methods'             => 'GET',
		'permission_callback' => static fn (): bool => is_user_logged_in(),
		'callback'            => __NAMESPACE__ . '\\trasa',
		'args'                => array(
			'wpis' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			'dni'  => array( 'default' => 30, 'sanitize_callback' => 'absint' ),
		),
	) );
} );

function trasa( \WP_REST_Request $zadanie ) {
	$id  = (int) $zadanie->get_param( 'wpis' );
	$dni = min( 365, max( 7, (int) $zadanie->get_param( 'dni' ) ) );

	$wpis = get_post( $id );
	if ( ! $wpis ) {
		return new \WP_Error( 'bsite_brak_wpisu', 'Nie ma takiego wpisu.', array( 'status' => 404 ) );
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return new \WP_Error( 'bsite_brak_prawa', 'Brak prawa do tego wpisu.', array( 'status' => 403 ) );
	}

	$odpowiedz = new \WP_REST_Response( array(
		'wersja_umowy' => BSITE_UMOWA,
		'ruch'         => ruch( $id, $dni ),
		'braki'        => braki( $wpis ),
		'tresc'        => tresc( $wpis ),
		'adres'        => get_permalink( $id ) ?: null,
	) );
	$odpowiedz->header( 'Cache-Control', 'private, max-age=0, no-store' );
	return $odpowiedz;
}

/**
 * Ruch na tym wpisie.
 *
 * ═══ FILTREM, JAK CAŁA ANALITYKA ═══
 *
 * Wtyczka nie ma własnego licznika i mieć nie powinna - witryna klienta może liczyć ruch
 * czymkolwiek. Kształt jest ten sam, co przy `bsite_odslony_dzienne`, tylko zawężony do
 * jednego wpisu. Brak implementacji to `null`, czyli „ta witryna nie umie tego powiedzieć",
 * a nie „zero odsłon".
 */
function ruch( int $id, int $dni ): ?array {
	$od = gmdate( 'Y-m-d', time() - $dni * DAY_IN_SECONDS );
	$do = gmdate( 'Y-m-d' );

	$dane = apply_filters( 'bsite_ruch_wpisu', null, $id, $od, $do );
	if ( ! is_array( $dane ) ) {
		return null;
	}

	return array(
		'od'    => $od,
		'do'    => $do,
		'dni'   => array_values( (array) ( $dane['dni'] ?? array() ) ),
		'razem' => isset( $dane['razem'] ) ? (int) $dane['razem'] : null,
		'goscie' => isset( $dane['goscie'] ) ? (int) $dane['goscie'] : null,
		/* Ile wizyt ZACZĘŁO się od tego wpisu. Przy tekście poradnikowym to jest miara
		   tego, czy przyciąga z zewnątrz, czy tylko domyka ścieżkę kogoś, kto już był. */
		'wejscia' => isset( $dane['wejscia'] ) ? (int) $dane['wejscia'] : null,
		'zrodla'  => array_values( (array) ( $dane['zrodla'] ?? array() ) ),
	);
}

/**
 * Czego temu wpisowi brakuje.
 *
 * ═══ TYLKO TO, CO TEN TYP W OGÓLE MA ═══
 *
 * Typ bez wsparcia zajawki nie może jej „brakować" - a wypisana jako brak byłaby zadaniem,
 * którego nie da się wykonać. Każdy punkt jest więc sprawdzany dopiero po upewnieniu się,
 * że typ tego w ogóle używa.
 */
function braki( \WP_Post $wpis ): array {
	$typ    = $wpis->post_type;
	$wynik  = array();

	if ( post_type_supports( $typ, 'excerpt' ) ) {
		$wynik['zajawka'] = '' === trim( (string) $wpis->post_excerpt );
	}
	if ( post_type_supports( $typ, 'thumbnail' ) ) {
		$wynik['obrazek'] = ! has_post_thumbnail( $wpis );
	}

	/* Tagi tylko tam, gdzie typ ma taksonomię nie-hierarchiczną. Kategorie pomijamy:
	   WordPress przypisuje domyślną, więc „brak kategorii" praktycznie nie występuje
	   i punkt, który zawsze jest zielony, nie niesie informacji. */
	foreach ( get_object_taxonomies( $typ, 'objects' ) as $tax ) {
		if ( $tax->hierarchical || ! $tax->public ) {
			continue;
		}
		$wynik['tagi'] = empty( wp_get_object_terms( $wpis->ID, $tax->name, array( 'fields' => 'ids' ) ) );
		break;
	}

	return $wynik;
}

/** Miary samej treści - ile jej jest i jak długo się to czyta. */
function tresc( \WP_Post $wpis ): array {
	$goly = wp_strip_all_tags( (string) $wpis->post_content );
	$slow = count( preg_split( '/\s+/u', trim( $goly ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() );

	return array(
		'slow'   => $slow,
		'blokow' => substr_count( (string) $wpis->post_content, '<!-- wp:' ),
		/* Dwieście słów na minutę - przyjęta miara dla tekstu ciągłego po polsku.
		   Zaokrąglamy w górę, bo „0 minut" przy krótkiej notce wygląda na usterkę.

		   Ale wpis PUSTY dostaje `null`, nie jedną minutę: strona główna złożona
		   z szablonu ma zero słów we własnej treści i „1 min czytania" byłoby przy niej
		   liczbą wziętą znikąd. */
		'minut'  => $slow > 0 ? max( 1, (int) ceil( $slow / 200 ) ) : null,
		'wersji' => count( wp_get_post_revisions( $wpis->ID ) ),
	);
}
