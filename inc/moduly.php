<?php
/**
 * Rejestr modułów witryny.
 *
 * MODUŁ TO JEDNOCZEŚNIE ZAKRES UMOWY I BRAMKA. Klient dostaje tyle modułów,
 * ile mu przydzielono - i to samo ustawienie decyduje, czy apka pokaże
 * zakładkę ORAZ czy serwer w ogóle odpowie na dane tego modułu.
 *
 * UKRYCIE ZAKŁADKI NIE JEST ZABEZPIECZENIEM. Gdyby apka chowała moduł, a trasa
 * REST dalej odpowiadała, pierwszy ciekawy klient z konsolą przeglądarki
 * znalazłby ją w kwadrans - i miałby rację, że mu się należała, skoro serwer ją
 * oddał. Dlatego `wlaczony()` jest wołane W KAŻDEJ trasie modułu, a manifest
 * jest tylko odbiciem tego, co serwer i tak wymusi.
 *
 * @package bsite
 */

declare( strict_types=1 );

namespace BSite\Moduly;

defined( 'ABSPATH' ) || exit;

const OPCJA = 'bsite_moduly';

/**
 * Katalog wszystkich modułów, jakie produkt zna.
 *
 * `wymaga` to uprawnienie WordPressa, bez którego moduł nie ma sensu nawet
 * przy włączonym pakiecie. `wykryj` mówi, czy witryna w ogóle ma czym ten
 * moduł obsłużyć - moduł opinii bez typu treści `sm_opinia` byłby pustą
 * zakładką, a pusta zakładka wygląda jak awaria.
 */
function katalog(): array {
	return array(
		'tresc' => array(
			'nazwa'  => 'Treść',
			'ikona'  => 'doc.text',
			'wymaga' => 'edit_posts',
			'wykryj' => static fn(): bool => true,
		),
		'zgloszenia' => array(
			'nazwa'  => 'Zgłoszenia',
			'ikona'  => 'tray.full',
			'wymaga' => 'manage_options',
			'wykryj' => static fn(): bool => post_type_exists( 'sm_zgloszenie' ),
		),
		'opinie' => array(
			'nazwa'  => 'Opinie',
			'ikona'  => 'quote.bubble',
			'wymaga' => 'edit_posts',
			'wykryj' => static fn(): bool => post_type_exists( 'sm_opinia' ),
		),
		'realizacje' => array(
			'nazwa'  => 'Realizacje',
			'ikona'  => 'hammer',
			'wymaga' => 'edit_posts',
			'wykryj' => static fn(): bool => post_type_exists( 'sm_realizacja' ),
		),
		'oferta' => array(
			'nazwa'  => 'Oferta',
			'ikona'  => 'tag',
			'wymaga' => 'edit_posts',
			'wykryj' => static fn(): bool => post_type_exists( 'sm_oferta' ),
		),
		'statystyki' => array(
			'nazwa'  => 'Statystyki',
			'ikona'  => 'chart.bar',
			'wymaga' => 'manage_options',
			'wykryj' => static fn(): bool => true,
		),
	);
}

/**
 * Które moduły są przydzielone tej witrynie.
 *
 * Pusta opcja znaczy „wszystkie wykryte" - świeżo wgrana wtyczka ma działać
 * bez konfigurowania czegokolwiek. Przydział zawęża się dopiero wtedy, gdy
 * ktoś go świadomie ustawi.
 */
function przydzielone(): array {
	$zapisane = get_option( OPCJA, null );
	if ( ! is_array( $zapisane ) ) {
		return array_keys( katalog() );
	}
	return array_values( array_intersect( $zapisane, array_keys( katalog() ) ) );
}

/**
 * Czy moduł wolno w tej chwili obsłużyć.
 *
 * Trzy warunki naraz - przydział, obecność w witrynie i uprawnienie
 * zalogowanego. To jest funkcja, którą woła KAŻDA trasa modułu.
 */
function wlaczony( string $klucz ): bool {
	$katalog = katalog();
	if ( ! isset( $katalog[ $klucz ] ) ) {
		return false;
	}
	if ( ! in_array( $klucz, przydzielone(), true ) ) {
		return false;
	}
	if ( ! ( $katalog[ $klucz ]['wykryj'] )() ) {
		return false;
	}
	return current_user_can( $katalog[ $klucz ]['wymaga'] );
}

/** Moduły do wypisania w manifeście - już przefiltrowane. */
function dla_manifestu(): array {
	$wynik = array();
	$i     = 0;
	foreach ( katalog() as $klucz => $modul ) {
		if ( ! wlaczony( $klucz ) ) {
			continue;
		}
		$wynik[] = array(
			'klucz'     => $klucz,
			'nazwa'     => $modul['nazwa'],
			'ikona'     => $modul['ikona'],
			'kolejnosc' => $i++,
		);
	}
	return $wynik;
}

/**
 * Bramka do wołania na początku trasy modułu.
 *
 * Zwraca `WP_Error` z kodem 403 albo `true`. Osobna funkcja, żeby każda trasa
 * odpowiadała tak samo - a apka umiała ten jeden kształt rozpoznać.
 */
function bramka( string $klucz ) {
	if ( wlaczony( $klucz ) ) {
		return true;
	}
	return new \WP_Error(
		'bsite_modul_wylaczony',
		sprintf( 'Moduł „%s” nie jest dostępny na tej witrynie.', $klucz ),
		array( 'status' => 403, 'modul' => $klucz )
	);
}
