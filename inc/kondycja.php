<?php
/**
 * Kondycja witryny - to, co da się zepsuć po cichu.
 *
 * ═══ CZEGO TU SZUKAMY ═══
 *
 * Rzeczy, które nie zgłaszają się same. Wyłączona widoczność dla wyszukiwarek nie wywala
 * błędu, kopia zapasowa sprzed czterdziestu dni nie wywala błędu, autoładowane opcje ważące
 * trzy megabajty nie wywalają błędu - po prostu witryna od miesięcy nie robi tego, po co
 * powstała. To jest jedyny rodzaj usterki, którego nie da się zauważyć inaczej niż
 * przypadkiem, i dlatego ma własną trasę.
 *
 * ═══ ODCZYTY, NIGDY ZMIANY ═══
 *
 * W tym pliku nie ma ani jednego `update_option`. Aplikacja może pokazać, że widoczność
 * jest wyłączona; włączenie jej to decyzja, a decyzje nie zapadają w trasie diagnostycznej.
 *
 * ═══ CO POCHODZI Z FILTRA ═══
 *
 * Kopie zapasowe i błędy poczty zależą od tego, czym witryna je robi - nie ma dwóch
 * jednakowych. Pytamy filtrem, tak samo jak o odsłony.
 *
 * @package bsite
 */

declare( strict_types=1 );

namespace BSite\Kondycja;

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'bsite/v1', '/kondycja', array(
		'methods'             => 'GET',
		'permission_callback' => static fn (): bool => is_user_logged_in(),
		'callback'            => __NAMESPACE__ . '\\trasa',
	) );
} );

function trasa(): \WP_REST_Response {
	$odpowiedz = new \WP_REST_Response( zbuduj() );
	$odpowiedz->header( 'Cache-Control', 'private, max-age=0, no-store' );
	return $odpowiedz;
}

function zbuduj(): array {
	/* Kondycja mówi o technicznym stanie witryny, więc próg jest wyższy niż przy treści.
	   Redaktor nie ma po co wiedzieć, ile waży baza. */
	if ( ! current_user_can( 'manage_options' ) ) {
		return array( 'wersja_umowy' => BSITE_UMOWA, 'dostepna' => false );
	}

	return array(
		'wersja_umowy' => BSITE_UMOWA,
		'dostepna'     => true,
		'widocznosc'   => widocznosc(),
		'wersje'       => wersje(),
		'waga'         => waga(),
		'zadania'      => zadania(),
		'kopia'        => apply_filters( 'bsite_kopia_wiek', null ),
		'poczta'       => apply_filters( 'bsite_poczta_bledy', null ),
		'braki'        => braki(),
	);
}

/**
 * Czy witryna prosi wyszukiwarki, żeby jej nie indeksowały.
 *
 * To jest najdroższa cicha usterka, jaka istnieje w WordPressie: `blog_public = 0` zostaje
 * po przenosinach z instalacji roboczej i cała praca nad treścią stoi za wyłącznikiem.
 * Dokładamy stan mapy witryny, bo idą w parze - jedno bez drugiego niewiele mówi.
 */
function widocznosc(): array {
	$publiczna = (bool) get_option( 'blog_public' );

	/* Mapa sprawdzana przez `wp_sitemaps_get_server()`, nie żądaniem HTTP do siebie:
	   witryna za logowaniem albo za zaporą odpowiedziałaby na własne żądanie inaczej niż
	   robotowi, a to dawałoby fałszywy spokój. */
	$mapa = null;
	if ( function_exists( 'wp_sitemaps_get_server' ) ) {
		$serwer = wp_sitemaps_get_server();
		$mapa   = $serwer && $serwer->sitemaps_enabled();
	}

	return array(
		'publiczna'  => $publiczna,
		'mapa'       => $mapa,
		'srodowisko' => wp_get_environment_type(),
	);
}

function wersje(): array {
	return array(
		'wordpress' => get_bloginfo( 'version' ),
		'php'       => PHP_VERSION,
		'wtyczka'   => BSITE_WERSJA,
		'motyw'     => wp_get_theme()->get( 'Version' ) ?: null,
	);
}

/**
 * Waga: media, baza i autoładowane opcje.
 *
 * ═══ AUTOLOAD JEST TU NAJWAŻNIEJSZY ═══
 *
 * Opcje oznaczone jako autoładowane wczytują się przy KAŻDYM żądaniu, także przy zwykłej
 * odsłonie strony. Wtyczka, która zostawi tam kilka megabajtów, spowalnia całą witrynę
 * w sposób, którego nie widać w żadnym profilu - bo to nie jest wolne zapytanie, tylko
 * jedno duże na starcie.
 *
 * Media liczymy z bazy, nie z dysku: przejście po katalogu wysyłek na stronie z dziesięcioma
 * tysiącami plików potrafi trwać dłużej niż limit czasu żądania.
 */
function waga(): array {
	global $wpdb;

	$autoload = (int) $wpdb->get_var(
		"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto')"
	);

	$baza = (float) $wpdb->get_var( $wpdb->prepare(
		'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s',
		DB_NAME
	) );

	$media = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
	);

	return array(
		'autoload_bajty' => $autoload,
		'baza_bajty'     => (int) $baza,
		'media_sztuk'    => $media,
	);
}

/**
 * Zadania cykliczne, które miały się wykonać i się nie wykonały.
 *
 * WordPress odpala je przy odsłonach, więc na witrynie bez ruchu potrafią stać godzinami -
 * i to jest normalne. Zaległość liczona w DNIACH znaczy już, że kopie się nie robią,
 * powiadomienia nie chodzą, a sprzątanie nie sprząta.
 */
function zadania(): array {
	$teraz    = time();
	$zalegle  = 0;
	$najstarsze = null;

	foreach ( (array) _get_cron_array() as $czas => $haki ) {
		if ( ! is_int( $czas ) || $czas >= $teraz ) {
			continue;
		}
		$opoznienie = $teraz - $czas;
		/* Godzina zapasu: zadanie sprzed dziesięciu minut to witryna bez ruchu,
		   nie usterka. */
		if ( $opoznienie < HOUR_IN_SECONDS ) {
			continue;
		}
		$zalegle += is_array( $haki ) ? count( $haki ) : 0;
		if ( null === $najstarsze || $opoznienie > $najstarsze ) {
			$najstarsze = $opoznienie;
		}
	}

	return array(
		'zalegle'          => $zalegle,
		'najstarsze_sekund' => $najstarsze,
		/* Wyłączone zadania to osobna sprawa: witryna z `DISABLE_WP_CRON` odpala je
		   z zewnątrz i brak zaległości nic wtedy nie znaczy. */
		'wewnetrzny'       => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
	);
}

/**
 * Braki w treści - higiena, która przekłada się na wyniki wyszukiwania.
 *
 * Wpis bez zajawki dostaje w wyniku wyszukiwania pierwsze zdanie tekstu, a to rzadko jest
 * zdanie, które ma kogoś przekonać. Wpis bez obrazka nie ma czego pokazać w podglądzie
 * linku. Liczymy tylko OPUBLIKOWANE - szkic bez zajawki to szkic, nie usterka.
 */
function braki(): array {
	global $wpdb;

	$bez_zajawki = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts}
		  WHERE post_status = 'publish' AND post_type IN ('post','page')
		    AND ( post_excerpt = '' OR post_excerpt IS NULL )"
	);

	$bez_obrazka = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		   LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_thumbnail_id'
		  WHERE p.post_status = 'publish' AND p.post_type IN ('post','page') AND m.meta_id IS NULL"
	);

	/* ═══ BEZ TAGU LICZYMY TYLKO WPISY, NIE STRONY ═══
	   Strona „Kontakt" nie potrzebuje tagów i nigdy ich nie będzie miała - wliczona
	   podbijałaby liczbę o rzecz, której nikt nie zamierza naprawiać, a po kilku takich
	   pozycjach kolumna przestaje cokolwiek znaczyć. Tagi są narzędziem wpisów.

	   ═══ `NOT EXISTS`, NIE `LEFT JOIN ... IS NULL` ═══

	   [BŁĄD, KTÓRY TO NAPRAWIA] Pierwsza wersja szła przez `LEFT JOIN` do relacji terminów
	   i sprawdzała `IS NULL`. Wpis ma jednak zwykle KILKA relacji - co najmniej kategorię -
	   a każda z nich daje osobny wiersz. Wiersz kategorii nie ma dopasowania w `post_tag`,
	   więc `IS NULL` łapał go i wpis z tagiem liczył się jako pozbawiony tagów. Próba na
	   dwóch wpisach, z których JEDEN miał tag, dała +2 zamiast +1.

	   `NOT EXISTS` pyta o istnienie choćby jednej relacji z tagiem i nie zwielokrotnia
	   wierszy - jest odporne na to, ile innych taksonomii wisi przy wpisie. */
	$bez_tagu = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		  WHERE p.post_status = 'publish' AND p.post_type = 'post'
		    AND NOT EXISTS (
		        SELECT 1 FROM {$wpdb->term_relationships} tr
		          JOIN {$wpdb->term_taxonomy} tt
		            ON tt.term_taxonomy_id = tr.term_taxonomy_id
		         WHERE tr.object_id = p.ID AND tt.taxonomy = 'post_tag'
		    )"
	);

	return array(
		'bez_zajawki' => $bez_zajawki,
		'bez_obrazka' => $bez_obrazka,
		'bez_tagu'    => $bez_tagu,
	);
}
