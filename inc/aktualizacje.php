<?php
/**
 * Aktualizacje wtyczki z publicznego repozytorium.
 *
 * ═══ DLACZEGO NIE wordpress.org ═══
 *
 * Umowa między wtyczką a apką jeszcze się rusza - każdy nowy moduł ją podnosi. W katalogu
 * wordpress.org każde wydanie przechodzi przez recenzję, więc poprawka umowy trafiałaby do
 * klientów po dniach albo tygodniach, a apka mówiłaby przez ten czas „zaktualizuj wtyczkę".
 * Wydanie z repozytorium jest tego samego dnia. Do katalogu wracamy, gdy umowa przestanie
 * się ruszać.
 *
 * ═══ DLACZEGO NIE WŁASNY SERWER AKTUALIZACJI ═══
 *
 * Serwer aktualizacji to rzecz, która musi stać. Gdy padnie, wtyczki u klientów nie tyle
 * się nie zaktualizują, co zaczną zgłaszać błędy przy każdym wejściu w panel. Repozytorium
 * publiczne jest cudzą infrastrukturą i to jest jego zaleta.
 *
 * ═══ CZEGO TA WTYCZKA NIE ROBI ═══
 *
 * Nie wysyła NICZEGO o witrynie. Sprawdzenie aktualizacji to zapytanie o listę wydań -
 * bez adresu witryny, bez wersji PHP, bez liczby użytkowników. Wtyczka zainstalowana
 * u klienta nie ma prawa opowiadać o nim nikomu, także nam.
 *
 * @package bsite
 */

declare( strict_types=1 );

namespace BSite\Aktualizacje;

defined( 'ABSPATH' ) || exit;

/** Repozytorium w postaci `wlasciciel/nazwa`. */
const REPO = 'diabeuspiekua/bsite-wordpress';

/** Jak długo trzymamy odpowiedź o wydaniach. */
const NA_ILE = 6 * HOUR_IN_SECONDS;

const PAMIEC = 'bsite_wydanie';

add_filter( 'site_transient_update_plugins', __NAMESPACE__ . '\\dopisz_aktualizacje' );
add_filter( 'plugins_api', __NAMESPACE__ . '\\szczegoly', 10, 3 );
add_filter( 'upgrader_source_selection', __NAMESPACE__ . '\\popraw_nazwe_katalogu', 10, 4 );

/** Ścieżka wtyczki w postaci, jakiej oczekuje WordPress: `bsite/bsite.php`. */
function uchwyt(): string {
	return plugin_basename( dirname( __DIR__ ) . '/bsite.php' );
}

/**
 * Najnowsze wydanie z repozytorium albo `null`.
 *
 * ODPOWIEDŹ TRZYMAMY NAWET, GDY JEST PUSTA. Bez tego witryna z zablokowanym wyjściem
 * na zewnątrz odpytywałaby serwis przy KAŻDYM wejściu w panel i za każdym razem czekała
 * na limit czasu - czyli panel wolniejszy o kilka sekund bez żadnego powodu.
 */
function wydanie(): ?array {
	/* ═══ „SPRAWDŹ PONOWNIE" MA NAPRAWDĘ SPRAWDZAĆ ═══
	 *
	 * [BŁĄD, KTÓRY TO NAPRAWIA] WordPress przy kliknięciu „Sprawdź ponownie" kasuje SWÓJ
	 * bufor aktualizacji i pyta wtyczki od nowa. Nasz własny sześciogodzinny bufor tego
	 * nie zauważał i oddawał odpowiedź sprzed godzin - więc człowiek klikał, widział
	 * „wszystko aktualne" i miał prawo sądzić, że nowego wydania nie ma.
	 *
	 * Wydanie opublikowane pięć minut wcześniej pojawiało się dopiero po sześciu godzinach,
	 * bez żadnego sposobu na przyspieszenie z panelu. To nie jest ostrożność, tylko
	 * przycisk, który kłamie.
	 *
	 * WordPress oznacza wymuszone sprawdzenie parametrem `force-check` na ekranie
	 * aktualizacji. Wtedy - i tylko wtedy - pytamy serwis od nowa. */
	// phpcs:ignore WordPress.Security.NonceVerification
	$wymuszone = isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' );

	$zapamietane = get_site_transient( PAMIEC );
	if ( false !== $zapamietane && ! $wymuszone ) {
		return is_array( $zapamietane ) ? $zapamietane : null;
	}

	$odpowiedz = wp_remote_get(
		'https://api.github.com/repos/' . REPO . '/releases/latest',
		array(
			'timeout' => 8,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				/* Serwis wymaga nagłówka przedstawiającego klienta. Podajemy nazwę wtyczki
				   i jej wersję - bez adresu witryny, patrz nagłówek pliku. */
				'User-Agent' => 'bSite/' . BSITE_WERSJA,
			),
		)
	);

	if ( is_wp_error( $odpowiedz ) || 200 !== (int) wp_remote_retrieve_response_code( $odpowiedz ) ) {
		set_site_transient( PAMIEC, 'brak', NA_ILE );
		return null;
	}

	$dane = json_decode( wp_remote_retrieve_body( $odpowiedz ), true );
	if ( ! is_array( $dane ) || empty( $dane['tag_name'] ) ) {
		set_site_transient( PAMIEC, 'brak', NA_ILE );
		return null;
	}

	/* Paczka to ZAŁĄCZNIK wydania, nie `zipball_url`. Archiwum tworzone przez serwis
	   niesie cały porządek repozytorium - katalog `.github`, testy, pliki narzędziowe -
	   a wtyczka u klienta ma zawierać wyłącznie to, co działa. */
	$paczka = '';
	foreach ( (array) ( $dane['assets'] ?? array() ) as $z ) {
		if ( isset( $z['name'] ) && str_ends_with( (string) $z['name'], '.zip' ) ) {
			$paczka = (string) ( $z['browser_download_url'] ?? '' );
			break;
		}
	}
	if ( '' === $paczka ) {
		set_site_transient( PAMIEC, 'brak', NA_ILE );
		return null;
	}

	$wynik = array(
		'wersja' => ltrim( (string) $dane['tag_name'], 'v' ),
		'paczka' => $paczka,
		'opis'   => (string) ( $dane['body'] ?? '' ),
		'kiedy'  => (string) ( $dane['published_at'] ?? '' ),
	);
	set_site_transient( PAMIEC, $wynik, NA_ILE );
	return $wynik;
}

/** Dopisuje naszą wtyczkę do listy tych z aktualizacją. */
function dopisz_aktualizacje( $stan ) {
	if ( ! is_object( $stan ) ) {
		return $stan;
	}
	$w = wydanie();
	if ( null === $w ) {
		return $stan;
	}

	$uchwyt = uchwyt();

	if ( version_compare( $w['wersja'], BSITE_WERSJA, '>' ) ) {
		$stan->response[ $uchwyt ] = (object) array(
			'slug'        => 'bsite',
			'plugin'      => $uchwyt,
			'new_version' => $w['wersja'],
			'package'     => $w['paczka'],
			'url'         => 'https://github.com/' . REPO,
			'tested'      => get_bloginfo( 'version' ),
		);
		return $stan;
	}

	/* Wersja aktualna też musi tu trafić - w `no_update`. Bez tego panel nie pokazuje
	   przy wtyczce ani „Wyświetl szczegóły", ani informacji o automatycznych
	   aktualizacjach, i wygląda ona na porzuconą. */
	$stan->no_update[ $uchwyt ] = (object) array(
		'slug'        => 'bsite',
		'plugin'      => $uchwyt,
		'new_version' => BSITE_WERSJA,
		'package'     => '',
		'url'         => 'https://github.com/' . REPO,
	);
	return $stan;
}

/** Okno „Wyświetl szczegóły" - inaczej WordPress pyta o naszą wtyczkę wordpress.org. */
function szczegoly( $wynik, $akcja, $argumenty ) {
	if ( 'plugin_information' !== $akcja || ( $argumenty->slug ?? '' ) !== 'bsite' ) {
		return $wynik;
	}
	$w = wydanie();
	if ( null === $w ) {
		return $wynik;
	}

	return (object) array(
		'name'          => 'bSite',
		'slug'          => 'bsite',
		'version'       => $w['wersja'],
		'author'        => '<a href="https://github.com/' . REPO . '">Marcin</a>',
		'homepage'      => 'https://github.com/' . REPO,
		'download_link' => $w['paczka'],
		'last_updated'  => $w['kiedy'],
		'sections'      => array(
			'description' => 'Łączy witrynę z aplikacją bSite.',
			'changelog'   => wpautop( wp_kses_post( $w['opis'] ) ),
		),
	);
}

/**
 * ═══ NAZWA KATALOGU PO ROZPAKOWANIU ═══
 *
 * [BŁĄD, KTÓRY TO NAPRAWIA] Archiwum z wydania rozpakowuje się do katalogu o nazwie
 * wziętej z pliku `.zip` - a ten nazywa się zwykle `bsite-1.2.0`. WordPress instaluje
 * wtedy wtyczkę pod nową ścieżką, stara zostaje na dysku, a WŁĄCZONA jest ciągle stara.
 * Z zewnątrz wygląda to tak, że aktualizacja „przeszła", ale nic się nie zmieniło.
 *
 * Dlatego po rozpakowaniu, a przed instalacją, przemianowujemy katalog na `bsite`.
 */
function popraw_nazwe_katalogu( $zrodlo, $zdalne, $ulepszacz, $dodatkowe = array() ) {
	global $wp_filesystem;

	if ( ! isset( $dodatkowe['plugin'] ) || uchwyt() !== $dodatkowe['plugin'] ) {
		return $zrodlo;
	}
	if ( ! $wp_filesystem ) {
		return $zrodlo;
	}

	$poprawne = trailingslashit( dirname( $zrodlo ) ) . 'bsite';
	if ( trailingslashit( $zrodlo ) === trailingslashit( $poprawne ) ) {
		return $zrodlo;
	}
	if ( ! $wp_filesystem->move( $zrodlo, $poprawne, true ) ) {
		return new \WP_Error( 'bsite_nazwa_katalogu', 'Nie udało się nazwać katalogu wtyczki.' );
	}
	return trailingslashit( $poprawne );
}
