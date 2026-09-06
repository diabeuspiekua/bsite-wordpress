<?php
/**
 * Zgłoszenia z formularzy - wszystkie witryny na jednym ekranie.
 *
 * ═══ ŹRÓDŁO, NIE JEDNA WTYCZKA ═══
 *
 * Formularze na WordPressie robi kilkanaście wtyczek i każda trzyma zgłoszenia gdzie
 * indziej: jedne we własnej tabeli, drugie w typach wpisów, trzecie nigdzie - wysyłają
 * mailem i tyle. Wpisanie tu ich wszystkich znaczyłoby czytanie cudzych tabel wprost,
 * czyli zależność od kształtu, którego nikt nam nie obiecał i który zmienia się między
 * wydaniami.
 *
 * Dlatego jest rejestr źródeł: wtyczka zna WordPressowy typ wpisu (`feedback` -
 * kształt, którego używa Jetpack i kilka innych), a wszystko poza tym dokłada motyw
 * albo wtyczka klienta jednym filtrem.
 *
 * ═══ CZEGO TU NIE MA I DLACZEGO ═══
 *
 * Nie ma treści zgłoszenia, adresu e-mail ani numeru telefonu.
 *
 * Apka pokazuje, ŻE coś przyszło, kiedy i z której witryny - do przeczytania prowadzi
 * odnośnik do panelu. Wysyłanie treści na telefon znaczyłoby, że czyjeś nazwisko
 * i sprawa lądują w pamięci urządzenia, w kopii tego urządzenia i w każdym miejscu,
 * przez które ta kopia przechodzi. Nikt nas o to nie prosił, a zgoda była na kontakt
 * z firmą, nie na to.
 *
 * Ta sama zasada, co przy `wp_mail_failed` w `utrzymanie.php`: liczymy zdarzenia,
 * nie przenosimy treści.
 *
 * @package bsite
 */

declare( strict_types=1 );

namespace BSite\Zgloszenia;

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'bsite/v1', '/zgloszenia', array(
		'methods'             => 'GET',
		'permission_callback' => static fn (): bool => is_user_logged_in(),
		'callback'            => __NAMESPACE__ . '\\trasa',
		'args'                => array(
			'ile' => array( 'default' => 20, 'sanitize_callback' => 'absint' ),
		),
	) );
} );

function trasa( \WP_REST_Request $zadanie ): \WP_REST_Response {
	$ile = min( 50, max( 1, (int) $zadanie->get_param( 'ile' ) ) );
	$odpowiedz = new \WP_REST_Response( zbuduj( $ile ) );
	$odpowiedz->header( 'Cache-Control', 'private, max-age=0, no-store' );
	return $odpowiedz;
}

function zbuduj( int $ile ): array {
	/* Zgłoszenie to korespondencja z firmą, nie treść witryny. Redaktor, który pisze
	   teksty, nie ma powodu czytać, kto się z kim umawia - próg jest więc taki sam jak
	   przy kondycji, a nie taki jak przy wpisach. */
	if ( ! current_user_can( 'manage_options' ) ) {
		return array( 'wersja_umowy' => BSITE_UMOWA, 'dostepna' => false );
	}

	$zrodlo = zrodlo();
	if ( null === $zrodlo ) {
		/* `dostepna: true` przy pustym źródle to NIE to samo, co brak uprawnień.
		   Apka ma powiedzieć „ta witryna nie zbiera zgłoszeń", a nie „nie wolno ci
		   ich zobaczyć" - to dwa różne komunikaty i dwie różne naprawy. */
		return array(
			'wersja_umowy' => BSITE_UMOWA,
			'dostepna'     => true,
			'zrodlo'       => null,
			'nowe'         => null,
			'pozycje'      => array(),
		);
	}

	$pozycje = pozycje( $zrodlo, $ile );

	return array(
		'wersja_umowy' => BSITE_UMOWA,
		'dostepna'     => true,
		'zrodlo'       => $zrodlo['nazwa'],
		'nowe'         => count( array_filter( $pozycje, static fn( $p ): bool => (bool) $p['nowe'] ) ),
		'pozycje'      => $pozycje,
	);
}

/**
 * Skąd bierzemy zgłoszenia na TEJ witrynie.
 *
 * Kształt: `typ` (nazwa typu wpisu), `nazwa` (co pokazać człowiekowi), `meta_obsluzone`
 * (klucz pola oznaczającego załatwione - `null`, gdy witryna tego nie prowadzi).
 *
 * Pierwsze pasujące wygrywa, a filtr idzie PRZED wykrywaniem: witryna, która wie o sobie
 * lepiej niż my, ma mieć ostatnie słowo, nie pierwsze do odwołania.
 */
function zrodlo(): ?array {
	$wlasne = apply_filters( 'bsite_zgloszenia_zrodlo', null );
	if ( is_array( $wlasne ) && ! empty( $wlasne['typ'] ) && post_type_exists( $wlasne['typ'] ) ) {
		return array(
			'typ'            => (string) $wlasne['typ'],
			'nazwa'          => (string) ( $wlasne['nazwa'] ?? $wlasne['typ'] ),
			'meta_obsluzone' => isset( $wlasne['meta_obsluzone'] ) ? (string) $wlasne['meta_obsluzone'] : null,
			'statusy'        => (array) ( $wlasne['statusy'] ?? array( 'publish', 'pending', 'draft', 'private' ) ),
		);
	}

	/* `feedback` to kształt Jetpacka, a przez niego najczęstszy sposób trzymania zgłoszeń
	   na WordPressie bez własnej tabeli. Zgłoszenie „nowe" poznaje się po statusie
	   oczekującym - Jetpack tak właśnie oznacza nieprzeczytane. */
	if ( post_type_exists( 'feedback' ) ) {
		return array(
			'typ'            => 'feedback',
			'nazwa'          => 'formularz kontaktowy',
			'meta_obsluzone' => null,
			'statusy'        => array( 'publish', 'draft' ),
		);
	}

	return null;
}

/**
 * Lista zgłoszeń - sam nagłówek, data i to, czy ktoś już się nimi zajął.
 *
 * ═══ TYTUŁ PRZYCINANY, NIE PEŁNY ═══
 *
 * Część wtyczek wkłada w tytuł zgłoszenia pierwsze zdanie wiadomości razem z adresem
 * nadawcy. Przycięcie do stu znaków nie jest kosmetyką - to granica między „widzę, że
 * przyszło pytanie o wycenę" a przeniesieniem cudzej korespondencji na telefon.
 */
function pozycje( array $zrodlo, int $ile ): array {
	$wpisy = get_posts( array(
		'post_type'        => $zrodlo['typ'],
		'post_status'      => $zrodlo['statusy'],
		'posts_per_page'   => $ile,
		'orderby'          => 'date',
		'order'            => 'DESC',
		'suppress_filters' => false,
	) );

	$lista = array();
	foreach ( $wpisy as $w ) {
		$lista[] = array(
			'id'     => (int) $w->ID,
			'tytul'  => tytul( $w ),
			'kiedy'  => get_post_time( 'c', true, $w ) ?: null,
			'nowe'   => nowe( $w, $zrodlo ),
			'panel'  => get_edit_post_link( $w->ID, 'raw' ) ?: null,
		);
	}
	return $lista;
}

function tytul( \WP_Post $w ): string {
	$tekst = trim( wp_strip_all_tags( $w->post_title ) );
	if ( '' === $tekst ) {
		$tekst = __( 'Zgłoszenie bez tytułu', 'bsite' );
	}
	return mb_substr( $tekst, 0, 100 );
}

function nowe( \WP_Post $w, array $zrodlo ): bool {
	if ( null !== $zrodlo['meta_obsluzone'] ) {
		return '' === (string) get_post_meta( $w->ID, $zrodlo['meta_obsluzone'], true );
	}
	/* Bez pola „obsłużone" zostaje status. `publish` u Jetpacka znaczy przeczytane,
	   `draft` - jeszcze nie. */
	return 'publish' !== $w->post_status;
}

/**
 * Liczba nieobsłużonych - dla przeglądu, który nie pobiera całej listy.
 *
 * Osobno od trasy, bo `przeglad.php` woła to przy każdym odświeżeniu i nie ma po co
 * budować wtedy stu tablic tylko po to, żeby je policzyć.
 */
function ile_nowych(): ?int {
	$zrodlo = zrodlo();
	if ( null === $zrodlo || ! current_user_can( 'manage_options' ) ) {
		return null;
	}

	$argumenty = array(
		'post_type'      => $zrodlo['typ'],
		'post_status'    => $zrodlo['statusy'],
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => false,
	);

	if ( null !== $zrodlo['meta_obsluzone'] ) {
		$argumenty['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
			'relation' => 'OR',
			array( 'key' => $zrodlo['meta_obsluzone'], 'compare' => 'NOT EXISTS' ),
			array( 'key' => $zrodlo['meta_obsluzone'], 'value' => '', 'compare' => '=' ),
		);
	} else {
		$argumenty['post_status'] = array_values( array_diff( $zrodlo['statusy'], array( 'publish' ) ) );
		if ( empty( $argumenty['post_status'] ) ) {
			return 0;
		}
	}

	$zapytanie = new \WP_Query( $argumenty );
	return (int) $zapytanie->found_posts;
}
