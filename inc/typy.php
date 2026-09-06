<?php
/**
 * Typy treści witryny - wykrywane, nie wpisane.
 *
 * ═══ DLACZEGO TO MUSI BYĆ WYKRYWANE ═══
 *
 * [BŁĄD, KTÓRY TO NAPRAWIA] `moduly.php` wymieniał `sm_zgloszenie`, `sm_opinia`,
 * `sm_realizacja` i `sm_oferta` z nazwy. To są typy JEDNEGO motywu, wpisane do wtyczki,
 * która idzie do klientów - u każdego innego wykryłaby się wyłącznie „Treść".
 *
 * Dowód, że to nie jest teoria: witryna, na której to powstawało, ma typ `sm_studium`.
 * Jest publiczny, jest w REST, ma tytuł, treść, zajawkę i obrazek - a aplikacja go nie
 * widzi, bo nikt nie dopisał go do katalogu. Dokładnie to działoby się u każdego klienta
 * przy każdym nowym typie.
 *
 * ═══ `show_in_rest` JEST TWARDĄ BRAMKĄ ═══
 *
 * Bez niego trasa `/wp/v2/<typ>` po prostu NIE ISTNIEJE - nie ma czego czytać ani zapisać.
 * Typ bez tej flagi nie jest więc „typem, którego jeszcze nie obsługujemy", tylko typem,
 * którego obsłużyć się nie da. Oddajemy go osobno, z powodem, żeby aplikacja umiała
 * powiedzieć „dopisz `show_in_rest`" zamiast milczeć.
 *
 * Tak jest właśnie z `sm_zgloszenie`: nie ma go w REST i dlatego ma własną trasę
 * w `zgloszenia.php`, a nie idzie torem ogólnym.
 *
 * @package bsite
 */

declare( strict_types=1 );

namespace BSite\Typy;

defined( 'ABSPATH' ) || exit;

/**
 * Typy WordPressa, które nie są treścią redakcyjną.
 *
 * Załączniki, wersje, elementy menu, wzorce bloków, szablony motywu blokowego. Każdy z nich
 * jest technicznie typem wpisu i część z nich jest nawet w REST - ale nikt nie „pisze
 * szablonu" z listy wpisów, a zakładka „Elementy menu" wyglądałaby jak usterka.
 */
const POMIJANE = array(
	'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
	'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part',
	'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face',
	'patterns_ai_data',
);

add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'bsite/v1', '/typy', array(
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
	if ( ! current_user_can( 'edit_posts' ) ) {
		/* Bez prawa do treści lista typów nie ma po co istnieć - a jej oddanie zdradzałoby
		   kształt witryny komuś, kto nie ma do niej wglądu. */
		return array( 'wersja_umowy' => BSITE_UMOWA, 'dostepna' => false );
	}

	$widoczne = array();
	$poza     = array();

	foreach ( get_post_types( array(), 'objects' ) as $typ ) {
		if ( in_array( $typ->name, POMIJANE, true ) ) {
			continue;
		}
		if ( ! $typ->show_in_rest ) {
			/* Typ istnieje, ale przez REST nieosiągalny. Mówimy o nim wprost, bo to jest
			   usterka do naprawienia jedną linijką w motywie - a przemilczany wygląda
			   jak brak funkcji w naszej aplikacji. */
			$poza[] = array(
				'nazwa'    => $typ->name,
				'etykieta' => (string) ( $typ->labels->name ?? $typ->name ),
				'powod'    => 'brak show_in_rest',
			);
			continue;
		}
		$widoczne[] = opisz( $typ );
	}

	return array(
		'wersja_umowy' => BSITE_UMOWA,
		'dostepna'     => true,
		'typy'         => $widoczne,
		'poza_zasiegiem' => $poza,
	);
}

/**
 * Opis jednego typu.
 *
 * ═══ UPRAWNIENIA LICZONE DLA TEGO CZŁOWIEKA, NIE OGÓLNIE ═══
 *
 * Ten sam typ wygląda inaczej dla redaktora i dla współpracownika: jeden publikuje, drugi
 * oddaje do sprawdzenia. Aplikacja musi wiedzieć, KTÓRE przyciski w ogóle pokazać, bo
 * przycisk „Opublikuj", który kończy się odmową serwera, jest gorszy niż jego brak.
 */
function opisz( \WP_Post_Type $typ ): array {
	$u = $typ->cap;

	return array(
		'nazwa'         => $typ->name,
		/* `rest_base` bywa inny niż nazwa (`post` → `posts`), a aplikacja buduje z niego
		   adres `/wp/v2/<baza>`. Domyślnie WordPress bierze nazwę typu. */
		'rest_base'     => (string) ( $typ->rest_base ?: $typ->name ),
		'etykieta'      => (string) ( $typ->labels->name ?? $typ->name ),
		'etykieta_poj'  => (string) ( $typ->labels->singular_name ?? $typ->name ),
		'hierarchiczny' => (bool) $typ->hierarchical,
		'publiczny'     => (bool) $typ->public,
		/* Dashicon albo adres obrazka. Przekład na symbole systemowe robi aplikacja -
		   SF Symbols to pojęcie Apple'a i wtyczka nie ma prawa o nich wiedzieć. */
		'ikona'         => is_string( $typ->menu_icon ) ? $typ->menu_icon : null,
		'wspiera'       => wspiera( $typ->name ),
		'taksonomie'    => array_values( get_object_taxonomies( $typ->name ) ),
		'moze'          => array(
			'czytac'      => current_user_can( $u->edit_posts ),
			'pisac'       => current_user_can( $u->edit_posts ),
			'publikowac'  => current_user_can( $u->publish_posts ),
			'cudze'       => current_user_can( $u->edit_others_posts ),
			'usuwac'      => current_user_can( $u->delete_posts ),
		),
		'ile'           => policz( $typ->name ),
	);
}

/**
 * Co ten typ w ogóle ma.
 *
 * Aplikacja rysuje pola według tej listy: typ bez `excerpt` nie dostaje pola zajawki,
 * a bez `thumbnail` - kafla obrazka. Pokazane mimo braku wsparcia zapisałyby się w nicość
 * i człowiek zobaczyłby, że jego praca znika po odświeżeniu.
 */
function wspiera( string $typ ): array {
	$interesujace = array( 'title', 'editor', 'excerpt', 'thumbnail', 'author',
	                       'comments', 'revisions', 'custom-fields', 'page-attributes' );
	$ma = get_all_post_type_supports( $typ );
	return array_values( array_filter( $interesujace, static fn( $s ): bool => isset( $ma[ $s ] ) ) );
}

/**
 * Ile czego jest - do plakietki przy zakładce.
 *
 * Bez `auto-draft`: WordPress zakłada je przy każdym kliknięciu „Dodaj nowy" i zostawia
 * po sobie setki pustych wierszy. Wliczone dawałyby liczbę, która rośnie od samego
 * otwierania edytora.
 */
function policz( string $typ ): array {
	$l = wp_count_posts( $typ );
	return array(
		'opublikowane' => (int) ( $l->publish ?? 0 ),
		'szkice'       => (int) ( $l->draft ?? 0 ),
		'oczekujace'   => (int) ( $l->pending ?? 0 ),
		'zaplanowane'  => (int) ( $l->future ?? 0 ),
	);
}
