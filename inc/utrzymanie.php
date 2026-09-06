<?php
/**
 * Kopie zapasowe i poczta - dwie rzeczy, które psują się najciszej.
 *
 * ═══ DLACZEGO TO JEST W WTYCZCE, A NIE W MOTYWIE ═══
 *
 * Pierwsza wersja pytała o jedno i drugie filtrem, licząc, że odpowie motyw. Skutek:
 * `bsite_kopia_wiek` czytał opcję, której nikt nie zapisywał, a `bsite_poczta_bledy` nie
 * miał ani jednej implementacji - więc kolumna „kopia" pokazywała kreskę na każdej
 * witrynie, a alarm o poczcie nie mógł zadziałać NIGDY. To był widok bez danych, czyli
 * gorzej niż brak widoku: wyglądał na działający.
 *
 * Obie rzeczy da się rozpoznać bez wiedzy o motywie, więc rozpoznajemy je tutaj - dzięki
 * temu działają u każdego klienta, nie tylko na naszych stronach.
 *
 * Filtry zostają jako NADPISANIE: witryna, która wie lepiej, może odpowiedzieć po swojemu.
 *
 * @package bsite
 */

declare( strict_types=1 );

namespace BSite\Utrzymanie;

defined( 'ABSPATH' ) || exit;

/** Ile nieudanych wysyłek pamiętamy i jak długo. */
const POCZTA_OPCJA = 'bsite_poczta_bledy';
const POCZTA_OKNO  = DAY_IN_SECONDS;

/* ─────────────────────────── Poczta ─────────────────────────── */

/**
 * ═══ LICZYMY BŁĘDY, NIE TREŚĆ ═══
 *
 * `wp_mail_failed` niesie pełny komunikat wraz z adresami odbiorców. Zapisujemy WYŁĄCZNIE
 * znacznik czasu: apce wystarczy „ile nie doszło w ciągu doby", a lista czyichś adresów
 * w opcji witryny to dane, których nikt tam nie zamawiał.
 */
add_action( 'wp_mail_failed', static function (): void {
	$teraz = time();
	$bledy = (array) get_option( POCZTA_OPCJA, array() );

	// Wypadają starsze niż okno - bez tego opcja rosłaby w nieskończoność.
	$bledy   = array_values( array_filter( $bledy, static fn( $t ): bool => ( $teraz - (int) $t ) < POCZTA_OKNO ) );
	$bledy[] = $teraz;

	/* Sufit na wypadek witryny, która wysyła setki maili i wszystkie się wywalają -
	   apce i tak wystarczy wiedzieć, że jest źle. */
	if ( count( $bledy ) > 500 ) {
		$bledy = array_slice( $bledy, -500 );
	}

	update_option( POCZTA_OPCJA, $bledy, false );
} );

add_filter( 'bsite_poczta_bledy', static function ( $puste ) {
	if ( null !== $puste ) {
		return $puste;   // witryna odpowiedziała po swojemu
	}
	$teraz = time();
	$bledy = (array) get_option( POCZTA_OPCJA, array() );
	return count( array_filter( $bledy, static fn( $t ): bool => ( $teraz - (int) $t ) < POCZTA_OKNO ) );
} );

/* ─────────────────────────── Kopie ─────────────────────────── */

/**
 * Wiek najnowszej kopii w sekundach - rozpoznawany po katalogach znanych wtyczek.
 *
 * ═══ PO PLIKACH, NIE PO OPCJACH ═══
 *
 * Każda wtyczka do kopii trzyma swój stan inaczej i zmienia to między wydaniami. Plik
 * archiwum jest natomiast tym samym u wszystkich: istnieje albo nie, i ma datę. Czytamy
 * więc NAJNOWSZY plik archiwum, bo to jedyna rzecz, która naprawdę znaczy „kopia jest".
 *
 * Wtyczka włączona, ale nierobiąca kopii, wygląda w opcjach dobrze - a katalog jest pusty
 * i to jest właśnie ten przypadek, który trzeba złapać.
 */
add_filter( 'bsite_kopia_wiek', static function ( $puste ) {
	if ( null !== $puste ) {
		return $puste;
	}

	$wysylki = wp_get_upload_dir();
	$baza    = dirname( (string) ( $wysylki['basedir'] ?? '' ) );

	/* Katalogi trzech najczęstszych wtyczek plus nasz własny. Rozszerzenia sprawdzamy,
	   bo w tych katalogach leżą też pliki pomocnicze - `.htaccess`, indeksy, dzienniki. */
	$miejsca = array(
		$baza . '/ai1wm-backups' => array( 'wpress' ),
		$baza . '/updraft'       => array( 'zip', 'gz' ),
		$baza . '/backwpup'      => array( 'zip', 'gz', 'tar' ),
		$baza . '/backups'       => array( 'zip', 'gz', 'sql' ),
	);

	$najnowszy = 0;
	foreach ( $miejsca as $katalog => $rozszerzenia ) {
		if ( ! is_dir( $katalog ) ) {
			continue;
		}
		foreach ( (array) glob( $katalog . '/*' ) as $plik ) {
			if ( ! is_file( $plik ) ) {
				continue;
			}
			$r = strtolower( (string) pathinfo( $plik, PATHINFO_EXTENSION ) );
			if ( ! in_array( $r, $rozszerzenia, true ) ) {
				continue;
			}
			$czas = (int) filemtime( $plik );
			if ( $czas > $najnowszy ) {
				$najnowszy = $czas;
			}
		}
	}

	/* Brak katalogów znanych wtyczek to `null`, nie „bardzo stara kopia". Witryna może
	   mieć kopie robione poza WordPressem - po stronie hostingu - a wtedy alarm byłby
	   fałszywy i nauczyłby go ignorować. */
	return $najnowszy > 0 ? max( 0, time() - $najnowszy ) : null;
} );
