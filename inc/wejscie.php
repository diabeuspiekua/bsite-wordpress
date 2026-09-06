<?php
/**
 * MOSTEK WEJŚCIA - jednorazowy żeton zamieniany na sesję panelu.
 *
 * ═══ PO CO TO ISTNIEJE ═══
 *
 * Aplikacja ma osadzić PRAWDZIWY edytor blokowy, a nie własną namiastkę. Widok sieciowy
 * w aplikacji nie ma jednak sesji WordPressa: hasło aplikacji uwierzytelnia REST, a NIE
 * strony panelu - i jest to celowe ograniczenie WordPressa, nie niedopatrzenie.
 *
 * Ten plik jest jedynym mostem między jednym a drugim.
 *
 * ═══ CO TO ZNACZY DLA BEZPIECZEŃSTWA - WPROST ═══
 *
 * Po włączeniu tego mostka hasło aplikacji przestaje być słabsze od hasła: kto je ma,
 * ten może wejść do panelu. Zabezpieczenia niżej ograniczają skutki, ale NIE zmieniają
 * tego faktu i nie udajemy, że zmieniają.
 *
 * Żeton nie podnosi uprawnień: loguje wyłącznie tego użytkownika, który go zamówił
 * uwierzytelnionym żądaniem. Zamienia dostęp REST na dostęp do panelu dla tej samej osoby.
 *
 * ═══ SIEDEM WARUNKÓW, Z KTÓRYCH ŻADEN NIE JEST OZDOBĄ ═══
 *
 * 1. Żeton wydaje się wyłącznie na uwierzytelnione żądanie REST.
 * 2. Wiąże się z identyfikatorem TEGO użytkownika - nigdy z podanym w żądaniu.
 * 3. W bazie leży SKRÓT, nie żeton: wyciek bazy nie daje żywych wejść.
 * 4. Żyje 60 sekund.
 * 5. Jest jednorazowy - kasowany, ZANIM ustawimy ciasteczko.
 * 6. Adres docelowy musi być w `wp-admin` tej witryny.
 * 7. Uprawnienie sprawdzane PONOWNIE przy wymianie, nie tylko przy wydaniu.
 *
 * ═══ CZEGO TEN MOSTEK NIE ZROBI ═══
 *
 * Nie zarejestruje się na witrynie z uwierzytelnianiem dwuskładnikowym. Żeton omijałby
 * drugi składnik, a ciche obchodzenie czyjegoś zabezpieczenia jest gorsze niż brak funkcji.
 *
 * @package bsite
 */

declare( strict_types=1 );

namespace BSite\Wejscie;

defined( 'ABSPATH' ) || exit;

const PRZEDROSTEK = 'bsite_wejscie_';
const WAZNOSC     = 60;
const PARAMETR    = 'bsite_wejscie';

/**
 * Czy witryna używa uwierzytelniania dwuskładnikowego.
 *
 * Rozpoznajemy po klasach najczęstszych dodatków. Lista jest z natury niepełna i to jest
 * przyjęte: fałszywe rozpoznanie wyłącza funkcję, czyli myli się w BEZPIECZNĄ stronę.
 * Pominięcie nieznanego dodatku zostawia natomiast dziurę, więc dokładamy jeszcze filtr,
 * żeby witryna mogła powiedzieć o sobie sama.
 */
function dwuskladnikowe(): bool {
	$znane = array(
		'Two_Factor_Core',            // Two Factor (zespół WordPressa)
		'WP2FA\\WP2FA',               // WP 2FA
		'WordfenceLS\\Controller_Users', // Wordfence Login Security
		'miniOrange_2_Factor_Settings',
	);
	foreach ( $znane as $klasa ) {
		if ( class_exists( $klasa ) ) {
			return true;
		}
	}
	return (bool) apply_filters( 'bsite_wymaga_drugiego_skladnika', false );
}

/** Czy mostek wolno w ogóle uruchomić. */
function czynny(): bool {
	if ( dwuskladnikowe() ) {
		return false;
	}
	/* Wyłącznik dla witryny, która nie chce tej funkcji mimo braku 2FA. Domyślnie włączone,
	   bo bez tego edytor w aplikacji nie działa wcale - a to jest powód, dla którego wtyczka
	   w ogóle powstała. */
	return (bool) apply_filters( 'bsite_mostek_wejscia', true );
}

add_action( 'rest_api_init', static function (): void {
	if ( ! czynny() ) {
		return;
	}
	register_rest_route( 'bsite/v1', '/wejscie', array(
		'methods'             => 'POST',
		'permission_callback' => static fn (): bool => is_user_logged_in(),
		'callback'            => __NAMESPACE__ . '\\trasa',
		'args'                => array(
			'wpis' => array( 'sanitize_callback' => 'absint' ),
			'typ'  => array( 'sanitize_callback' => 'sanitize_key' ),
		),
	) );
} );

function trasa( \WP_REST_Request $zadanie ) {
	$wpis = (int) $zadanie->get_param( 'wpis' );

	/* Cel składamy TUTAJ, z identyfikatora - nie przyjmujemy adresu z żądania. Adres podany
	   z zewnątrz trzeba by sprawdzać, a każde sprawdzanie adresu URL jest wyścigiem
	   z pomysłowością; złożony u siebie nie wymaga zaufania do niczego. */
	if ( $wpis > 0 ) {
		if ( ! current_user_can( 'edit_post', $wpis ) ) {
			return new \WP_Error( 'bsite_brak_prawa', 'Nie masz prawa edytować tego wpisu.', array( 'status' => 403 ) );
		}
		$cel = admin_url( 'post.php?post=' . $wpis . '&action=edit' );
	} else {
		$typ = (string) $zadanie->get_param( 'typ' );
		if ( '' === $typ || ! post_type_exists( $typ ) ) {
			return new \WP_Error( 'bsite_zly_typ', 'Nieznany typ treści.', array( 'status' => 400 ) );
		}
		$obiekt = get_post_type_object( $typ );
		if ( ! current_user_can( $obiekt->cap->edit_posts ) ) {
			return new \WP_Error( 'bsite_brak_prawa', 'Nie masz prawa pisać w tym typie.', array( 'status' => 403 ) );
		}
		$cel = admin_url( 'post-new.php?post_type=' . rawurlencode( $typ ) );
	}

	$zeton = bin2hex( random_bytes( 32 ) );
	set_transient( PRZEDROSTEK . skrot( $zeton ), array(
		'uzytkownik' => get_current_user_id(),
		'cel'        => $cel,
		'wydano'     => time(),
	), WAZNOSC );

	dziennik( 'wydanie', get_current_user_id(), $cel );

	return new \WP_REST_Response( array(
		'wersja_umowy' => BSITE_UMOWA,
		'adres'        => add_query_arg( PARAMETR, $zeton, home_url( '/' ) ),
		'wazny_do'     => gmdate( 'c', time() + WAZNOSC ),
	) );
}

/**
 * Wymiana żetonu na sesję.
 *
 * ═══ `init`, A NIE `template_redirect` ═══
 *
 * Ciasteczko trzeba ustawić, ZANIM cokolwiek pójdzie na wyjście - później PHP już go nie
 * przyjmie. `init` jest pierwszym zaczepem, w którym WordPress jest gotowy, a nic jeszcze
 * nie wypisał.
 */
add_action( 'init', static function (): void {
	if ( ! czynny() || ! isset( $_GET[ PARAMETR ] ) ) {
		return;
	}

	$zeton = (string) $_GET[ PARAMETR ];   // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $zeton ) ) {
		odmowa( 'zły kształt żetonu' );
	}

	$klucz = PRZEDROSTEK . skrot( $zeton );
	$wpis  = get_transient( $klucz );

	/* Kasujemy PRZED sprawdzaniem czegokolwiek innego. Żeton przechwycony po drodze ma być
	   bezużyteczny od chwili pierwszego użycia - także wtedy, gdy to użycie się nie powiodło. */
	delete_transient( $klucz );

	if ( ! is_array( $wpis ) || empty( $wpis['uzytkownik'] ) ) {
		odmowa( 'żeton nieznany albo już użyty' );
	}
	if ( time() - (int) $wpis['wydano'] > WAZNOSC ) {
		odmowa( 'żeton wygasł' );
	}

	$uzytkownik = get_user_by( 'id', (int) $wpis['uzytkownik'] );
	if ( ! $uzytkownik ) {
		odmowa( 'konto już nie istnieje' );
	}

	/* ═══ UPRAWNIENIE SPRAWDZANE PONOWNIE ═══
	   Między wydaniem a wymianą mija do minuty - ale w tej minucie komuś można odebrać
	   dostęp, a żeton wydany wcześniej nadal by działał. */
	if ( ! user_can( $uzytkownik, 'edit_posts' ) ) {
		odmowa( 'konto straciło prawo do edycji' );
	}

	$cel = (string) $wpis['cel'];
	if ( 0 !== strpos( $cel, admin_url() ) ) {
		odmowa( 'cel poza panelem tej witryny' );
	}

	/* `false` - ciasteczko SESYJNE, bez „zapamiętaj mnie". Sesja ginie razem z zamknięciem
	   widoku sieciowego, a aplikacja i tak używa magazynu nietrwałego. */
	wp_set_auth_cookie( $uzytkownik->ID, false );
	wp_set_current_user( $uzytkownik->ID );

	dziennik( 'wymiana', $uzytkownik->ID, $cel );

	wp_safe_redirect( $cel );
	exit;
}, 1 );

/**
 * Skrót żetonu. W bazie NIGDY nie leży sam żeton - wyciek bazy nie może dawać
 * żywych wejść do panelu.
 */
function skrot( string $zeton ): string {
	return hash( 'sha256', $zeton );
}

/**
 * Odmowa kończy żądanie stroną z powodem, a nie przekierowaniem na stronę główną.
 * Ciche przekierowanie wyglądałoby na usterkę aplikacji, a nie na odrzucone wejście.
 */
function odmowa( string $powod ): void {
	dziennik( 'odmowa', get_current_user_id(), $powod );
	wp_die(
		esc_html( 'Wejście odrzucone: ' . $powod . '.' ),
		'bSite',
		array( 'response' => 403, 'back_link' => false )
	);
}

/**
 * Ślad po każdym wydaniu, wymianie i odmowie.
 *
 * Bez dziennika nie da się odpowiedzieć na pytanie „kto i kiedy wszedł tą drogą" - a przy
 * funkcji, która zakłada sesję panelu, to jest pierwsze pytanie po każdym incydencie.
 * Trzymamy sto ostatnich wpisów: to jest ślad, a nie archiwum.
 */
function dziennik( string $co, int $kto, string $szczegol ): void {
	$wpisy = (array) get_option( 'bsite_dziennik_wejsc', array() );
	$wpisy[] = array(
		'co'    => $co,
		'kto'   => $kto,
		'kiedy' => time(),
		'skad'  => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
		'co_do' => mb_substr( $szczegol, 0, 200 ),
	);
	update_option( 'bsite_dziennik_wejsc', array_slice( $wpisy, -100 ), false );
}
