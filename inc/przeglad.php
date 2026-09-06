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
		'kalendarz'     => kalendarz(),
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
 * [BŁĄD, KTÓRY TO NAPRAWIA] Pierwsza wersja pytała wprost o typ `sm_zgloszenie` i pole
 * `sm_obsluzone` - czyli o nazwy z NASZEGO motywu, wpisane do wtyczki, która ma trafić
 * do klientów. Na cudzej witrynie ten typ nie istnieje, więc liczba zgłoszeń była tam
 * pusta zawsze i z definicji, choć formularz działał.
 *
 * Teraz źródło rozpoznaje `zgloszenia.php`: zna kształt WordPressowy, a nasze nazwy
 * dokłada motyw filtrem - tam, gdzie ich miejsce.
 */
function zgloszenia(): ?int {
	return \BSite\Zgloszenia\ile_nowych();
}

/**
 * Co i kiedy WYJDZIE - najbliższe zaplanowane wpisy.
 *
 * ═══ LISTA, NIE SAMA LICZBA ═══
 *
 * Liczba zaplanowanych odpowiada na pytanie „czy coś jest przygotowane". Nie odpowiada
 * na to, po które się naprawdę sięga: czy w przyszłym tygodniu jest luka, i czy dwa teksty
 * nie wychodzą przypadkiem tego samego dnia. Do tego trzeba dat, więc daty tu są.
 *
 * ═══ TYTUŁ WOLNO, W ODRÓŻNIENIU OD ZGŁOSZEŃ ═══
 *
 * To jest własna treść witryny przygotowana do publikacji, nie czyjaś korespondencja.
 * Za tydzień i tak będzie publiczna. Porównaj `zgloszenia.php`, gdzie z tego samego
 * powodu treści NIE ma.
 *
 * Dziesięć pozycji, bo to jest podgląd na ekranie telefonu, a nie plan redakcyjny -
 * ten jest w panelu i tam prowadzi odnośnik.
 */
function kalendarz(): ?array {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return null;
	}

	$wpisy = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'future',
		'posts_per_page' => 10,
		'orderby'        => 'date',
		/* Rosnąco: najbliższe pierwsze. Malejąco - czyli tak, jak zwykle sortuje się
		   wpisy - dałoby na górze rzecz zaplanowaną najdalej w przyszłość, czyli tę,
		   którą trzeba zająć się najpóźniej. */
		'order'          => 'ASC',
	) );

	$lista = array();
	foreach ( $wpisy as $w ) {
		$lista[] = array(
			'id'    => (int) $w->ID,
			'tytul' => mb_substr( trim( wp_strip_all_tags( get_the_title( $w ) ) ) ?: 'Bez tytułu', 0, 120 ),
			'kiedy' => get_post_time( 'c', true, $w ) ?: null,
			'typ'   => (string) $w->post_type,
			'panel' => get_edit_post_link( $w->ID, 'raw' ) ?: null,
		);
	}
	return $lista;
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
