<?php
/**
 * Odsłony w czasie - szereg dzienny na wykres w aplikacji.
 *
 * ═══ WTYCZKA NIE ZNA ŹRÓDŁA I NIE MA GO ZNAĆ ═══
 *
 * Odsłony liczy analityka witryny: nasz motyw ma własną tabelę, cudza strona może mieć
 * wtyczkę statystyk, a jeszcze inna nie mieć nic. Wpisanie tu nazwy JEDNEJ tabeli
 * zamieniłoby publiczną wtyczkę w dodatek do naszego motywu.
 *
 * Dlatego pytamy filtrem. Kto ma dane, ten odpowiada; kto nie ma, ten milczy i wtyczka
 * oddaje `null` - a `null` znaczy „nie ma czego pokazać", nie „zero odsłon".
 *
 *     add_filter( 'bsite_odslony_dzienne', function ( $puste, $od, $do ) {
 *         return [ [ 'dzien' => '2026-09-01', 'odslony' => 120, 'goscie' => 84 ], … ];
 *     }, 10, 3 );
 *
 * ═══ DNI BEZ RUCHU MUSZĄ BYĆ W SZEREGU ═══
 *
 * Analityka zwraca zwykle tylko dni, w których coś się wydarzyło. Wykres złożony z takich
 * punktów KŁAMIE: dwa sąsiednie punkty odległe o tydzień rysują się obok siebie, więc
 * przerwa w ruchu wygląda jak ciągłość. Uzupełniamy zerami tutaj, żeby aplikacja dostała
 * szereg, który da się narysować wprost.
 *
 * @package bsite
 */

declare( strict_types=1 );

namespace BSite\Statystyki;

defined( 'ABSPATH' ) || exit;

/** Ile dni wstecz domyślnie. Trzydzieści mieści się na wykresie i w pamięci telefonu. */
const DNI = 30;

/** Sufit, żeby jedno żądanie nie kazało witrynie liczyć dwóch lat. */
const DNI_MAX = 365;

add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'bsite/v1', '/statystyki', array(
		'methods'             => 'GET',
		'permission_callback' => static fn (): bool => is_user_logged_in(),
		'callback'            => __NAMESPACE__ . '\\trasa',
		'args'                => array(
			'dni' => array(
				'type'    => 'integer',
				'default' => DNI,
			),
		),
	) );
} );

function trasa( \WP_REST_Request $zadanie ): \WP_REST_Response {
	/* Odsłony to nie są dane publiczne witryny - to informacja o tym, jak jej idzie.
	   Ten sam próg, co przy zgłoszeniach. */
	if ( ! current_user_can( 'edit_posts' ) ) {
		return new \WP_REST_Response( array( 'wersja_umowy' => BSITE_UMOWA, 'dni' => null ), 200 );
	}

	$dni = max( 1, min( DNI_MAX, (int) $zadanie->get_param( 'dni' ) ) );

	$odpowiedz = new \WP_REST_Response( zbuduj( $dni ) );
	$odpowiedz->header( 'Cache-Control', 'private, max-age=0, no-store' );
	return $odpowiedz;
}

function zbuduj( int $dni ): array {
	/* Strefa WITRYNY, nie serwera. „Wczoraj" na stronie z ruchem z Polski kończy się
	   o północy w Warszawie, a serwer bywa ustawiony na UTC - bez tego ostatni słupek
	   wykresu obejmowałby dwie różne doby. */
	$dzis = new \DateTimeImmutable( 'now', wp_timezone() );
	$od   = $dzis->modify( '-' . ( $dni - 1 ) . ' days' );

	$surowe = apply_filters(
		'bsite_odslony_dzienne',
		null,
		$od->format( 'Y-m-d' ),
		$dzis->format( 'Y-m-d' )
	);

	if ( ! is_array( $surowe ) ) {
		/* Nikt nie odpowiedział - witryna nie ma analityki. `null`, nie pusta tablica:
		   pusta tablica znaczyłaby „mierzymy i wyszło zero". */
		return array(
			'wersja_umowy' => BSITE_UMOWA,
			'od'           => $od->format( 'Y-m-d' ),
			'do'           => $dzis->format( 'Y-m-d' ),
			'dni'          => null,
			'zrodlo'       => null,
			/* Wymiary pytamy MIMO braku szeregu: witryna może liczyć jedno bez drugiego,
			   a widget urządzeń nie musi czekać na wykres. */
			'wymiary'      => \BSite\Wymiary\zbierz( $od->format( 'Y-m-d' ), $dzis->format( 'Y-m-d' ) ),
		);
	}

	return array(
		'wersja_umowy' => BSITE_UMOWA,
		'od'           => $od->format( 'Y-m-d' ),
		'do'           => $dzis->format( 'Y-m-d' ),
		'dni'          => uzupelnij( $surowe, $od, $dzis ),
		'zrodlo'       => (string) apply_filters( 'bsite_odslony_zrodlo', 'analityka witryny' ),
		/* Wymiary tym samym żądaniem. Osobna trasa znaczyłaby drugie połączenie po to,
		   żeby zapytać tę samą tabelę o ten sam zakres dat. */
		'wymiary'      => \BSite\Wymiary\zbierz( $od->format( 'Y-m-d' ), $dzis->format( 'Y-m-d' ) ),
	);
}

/**
 * Szereg ciągły: każdy dzień od `$od` do `$do`, także te bez ruchu.
 *
 * Patrz nagłówek - wykres z dziurami rysuje przerwę jako ciągłość.
 *
 * @param array<int, array<string, mixed>> $surowe
 * @return array<int, array{dzien: string, odslony: int, goscie: int}>
 */
function uzupelnij( array $surowe, \DateTimeImmutable $od, \DateTimeImmutable $do ): array {
	$wg_dnia = array();
	foreach ( $surowe as $w ) {
		$d = (string) ( $w['dzien'] ?? '' );
		if ( '' === $d ) {
			continue;
		}
		$wg_dnia[ $d ] = array(
			'odslony' => (int) ( $w['odslony'] ?? 0 ),
			'goscie'  => (int) ( $w['goscie'] ?? 0 ),
		);
	}

	$szereg = array();
	$biezacy = $od;
	while ( $biezacy <= $do ) {
		$d = $biezacy->format( 'Y-m-d' );
		$szereg[] = array(
			'dzien'   => $d,
			'odslony' => $wg_dnia[ $d ]['odslony'] ?? 0,
			'goscie'  => $wg_dnia[ $d ]['goscie'] ?? 0,
		);
		$biezacy = $biezacy->modify( '+1 day' );
	}
	return $szereg;
}
