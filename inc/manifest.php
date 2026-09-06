<?php
/**
 * Manifest - umowa między witryną a aplikacją.
 *
 * DWA POZIOMY ODPOWIEDZI, JEDNA TRASA.
 *
 * Bez uwierzytelnienia manifest oddaje CZĘŚĆ PUBLICZNĄ: wersję umowy, wersję
 * wtyczki, nazwę witryny i silnik. To jest potrzebne PRZED zalogowaniem: apka
 * ma powiedzieć „pod tym adresem jest bSite w wersji X", zanim poprosi kogoś
 * o hasło. Człowiek, który wpisał zły adres, dowiaduje się tego od razu,
 * a nie po wpisaniu loginu i hasła.
 *
 * Część publiczna NIE ZAWIERA niczego, czego nie widać na stronie: nazwa
 * witryny i wersja wtyczki. Żadnych modułów, żadnych uprawnień, żadnych
 * danych osobowych.
 *
 * Z uwierzytelnieniem dochodzą moduły i uprawnienia zalogowanego - czyli to,
 * z czego apka rysuje zakładki.
 *
 * @package bsite
 */

declare( strict_types=1 );

namespace BSite\Manifest;

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'bsite/v1', '/manifest', array(
		'methods'  => 'GET',
		/* Trasa jest otwarta, bo część publiczna ma być dostępna przed
		   zalogowaniem. Zawartość zależy od tego, czy żądanie niesie hasło
		   aplikacji - patrz `zbuduj()`. */
		'permission_callback' => '__return_true',
		'callback'            => __NAMESPACE__ . '\\trasa',
	) );
} );

function trasa( \WP_REST_Request $zadanie ): \WP_REST_Response {
	$odpowiedz = new \WP_REST_Response( zbuduj() );

	/* Manifest zmienia się rzadko, ale ZALEŻY OD ZALOGOWANEGO - więc nie wolno
	   go trzymać w pamięci podręcznej dzielonej między użytkownikami. */
	$odpowiedz->header( 'Cache-Control', 'private, max-age=0, no-store' );
	return $odpowiedz;
}

function zbuduj(): array {
	$zalogowany = is_user_logged_in();

	$manifest = array(
		'wersja_umowy'   => BSITE_UMOWA,
		/* CZY ŻĄDANIE ZOSTAŁO ROZPOZNANE. Bez tego pola apka nie odróżnia
		   „nie podałem hasła" od „podałem złe": WordPress przy błędnym haśle
		   aplikacji NIE odrzuca żądania - traktuje je jak niezalogowane,
		   a trasa z otwartym `permission_callback` oddaje część publiczną
		   z kodem 200. Klient, który wysłał dane logowania i dostał
		   `zalogowany: false`, wie, że hasło się nie zgadza. */
		'zalogowany'     => $zalogowany,
		'uzytkownik'     => $zalogowany ? wp_get_current_user()->display_name : null,
		'nazwa'          => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
		'adres'          => home_url( '/' ),
		'wersja_wtyczki' => BSITE_WERSJA,
		'silnik'         => 'wordpress',
		'wersja_silnika' => (string) get_bloginfo( 'version' ),
		'wyglad'         => wyglad(),
		/* Bez zalogowania listy są PUSTE, a nie nieobecne. Apka dekoduje jeden
		   kształt odpowiedzi zawsze; brakujące pole wywalałoby dekodowanie
		   i wyglądało jak zepsuta wtyczka. */
		'moduly'         => $zalogowany ? \BSite\Moduly\dla_manifestu() : array(),
		'uprawnienia'    => $zalogowany ? uprawnienia() : array(),
	);

	return $manifest;
}

/**
 * Wygląd: kolor akcentu i znak.
 *
 * To JEDYNE, co apka bierze z wyglądu witryny. Nie skórujemy całej oprawy pod
 * tenanta - aplikacja natywna ma wyglądać natywnie, a przemalowanie kontrolek
 * kosztuje spójność z systemem i gwarancje kontrastu. Akcent i znak wystarczą,
 * żeby ani przez sekundę nie było wątpliwości, na której witrynie się stoi.
 */
function wyglad(): array {
	$akcent = null;

	/* Kolor z palety motywu blokowego, jeśli motyw ją ma. Czytamy `theme.json`
	   przez API rdzenia, a nie z pliku - motyw potomny i dostosowania
	   użytkownika mają być uwzględnione. */
	if ( function_exists( 'wp_get_global_settings' ) ) {
		$paleta = wp_get_global_settings( array( 'color', 'palette' ) );
		$pozycje = $paleta['theme'] ?? ( $paleta['default'] ?? array() );
		foreach ( (array) $pozycje as $kolor ) {
			if ( in_array( $kolor['slug'] ?? '', array( 'akcent', 'accent', 'primary' ), true ) ) {
				$akcent = $kolor['color'] ?? null;
				break;
			}
		}
	}

	$znak = null;
	$logo = (int) get_theme_mod( 'custom_logo' );
	if ( $logo ) {
		$znak = wp_get_attachment_image_url( $logo, 'medium' ) ?: null;
	}
	if ( ! $znak ) {
		$znak = get_site_icon_url( 180 ) ?: null;
	}

	return array( 'akcent' => $akcent, 'znak' => $znak );
}

/**
 * Uprawnienia zalogowanego - tylko te, o które apka pyta.
 *
 * Nie wysyłamy CAŁEJ tablicy `allcaps`: to kilkadziesiąt pozycji, z których
 * apka używa kilku, a reszta jest zbędnym opisem konta wysyłanym przez sieć.
 */
function uprawnienia(): array {
	$interesujace = array(
		'edit_posts', 'publish_posts', 'delete_posts',
		'upload_files', 'manage_options', 'edit_theme_options',
	);
	return array_values( array_filter( $interesujace, 'current_user_can' ) );
}
