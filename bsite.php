<?php
/**
 * Plugin Name:       bSite
 * Description:       Łączy witrynę z aplikacją bSite. Wystawia manifest - czym ta witryna jest, jakie ma moduły i co wolno zalogowanemu.
 * Version:           0.13.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Marcin
 * Plugin URI:        https://github.com/diabeuspiekua/bsite-wordpress
 * License:           GPL-2.0-or-later
 * Text Domain:       bsite
 *
 * CZEGO TU NIE MA I NIE BĘDZIE: zapisu plików na serwerze.
 *
 * Wdrożenia PHP to OSOBNE narzędzie, instalowane wyłącznie tam, gdzie się
 * wdraża. Klient nie dostaje wtyczki z wyłączonym zapisem plików - dostaje
 * wtyczkę, która tego kodu w ogóle nie zawiera. „Nie ma czego wyłączyć" to
 * zupełnie inna gwarancja niż „jest, ale wyłączone".
 *
 * UWIERZYTELNIANIE: hasła aplikacji WordPressa. Nie własny klucz, nie własny
 * token. Powód: hasło aplikacji jest wydawane PER UŻYTKOWNIK i PER URZĄDZENIE,
 * odwoływalne jednym kliknięciem w profilu, a żądanie biegnie JAKO ten
 * użytkownik - więc `current_user_can()` działa i nie trzeba pisać drugiego
 * systemu uprawnień obok WordPressowego.
 *
 * @package bsite
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const BSITE_WERSJA = '0.13.0';

/**
 * Wersja UMOWY, nie wtyczki.
 *
 * Apka zna zakres wersji, które rozumie. Klient aktualizuje wtyczkę wtedy,
 * kiedy chce, więc jeden klient na starszej nie może psuć apki pozostałym:
 * apka porównuje tę liczbę ze swoim zakresem i mówi wprost, co zaktualizować.
 * Rośnie TYLKO wtedy, gdy zmienia się kształt manifestu - poprawka w kodzie
 * bez zmiany umowy jej nie rusza.
 */
const BSITE_UMOWA = 1;

require_once __DIR__ . '/inc/moduly.php';
require_once __DIR__ . '/inc/manifest.php';
require_once __DIR__ . '/inc/przeglad.php';
require_once __DIR__ . '/inc/wymiary.php';
require_once __DIR__ . '/inc/statystyki.php';
require_once __DIR__ . '/inc/kondycja.php';
require_once __DIR__ . '/inc/utrzymanie.php';
require_once __DIR__ . '/inc/zgloszenia.php';
require_once __DIR__ . '/inc/typy.php';
require_once __DIR__ . '/inc/wejscie.php';
require_once __DIR__ . '/inc/aktualizacje.php';
