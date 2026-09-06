<?php
/**
 * Wymiary ruchu - z czego składa się liczba odsłon.
 *
 * ═══ OSOBNY FILTR, NIE ROZSZERZONY STARY ═══
 *
 * `bsite_odslony_dzienne` oddaje szereg czasowy i tak zostaje - witryny, które go już
 * obsługują, mają dalej działać bez zmiany ani jednej linii. Wymiary przychodzą osobnym
 * filtrem, więc analityka może odpowiadać na jedno, na drugie albo na oba.
 *
 * ═══ WSZYSTKO W JEDNYM ZAPYTANIU PO STRONIE MOTYWU ═══
 *
 * Dziewięć wymiarów liczonych dziewięcioma zapytaniami to dziewięć przebiegów po tej samej
 * tabeli przy każdym otwarciu aplikacji. Filtr dostaje zakres dat RAZ i oddaje komplet -
 * jak to zrobi, jest jego sprawą, ale kontrakt zachęca do jednego przebiegu.
 *
 * ═══ `null` TO NIE PUSTA TABLICA ═══
 *
 * Ta sama zasada, co przy liczbach przeglądu. `null` znaczy „nie mam skąd wziąć", pusta
 * tablica znaczy „liczyłem i nic nie ma". Aplikacja rysuje to inaczej: pierwsze wycisza
 * widget z podpisem, drugie pokazuje pustkę, która jest prawdą.
 *
 * @package bsite
 */

declare( strict_types=1 );

namespace BSite\Wymiary;

defined( 'ABSPATH' ) || exit;

/**
 * Kształt, którego aplikacja się spodziewa.
 *
 * Każdy wymiar to lista par `klucz` → `ile`, posortowana malejąco i już przycięta.
 * Przycinanie po stronie witryny, nie aplikacji: lista dwustu odsyłających przesłana
 * po to, żeby pokazać cztery, to dwieście razy więcej danych w sieci niż trzeba.
 */
function pusty(): array {
	return array(
		'goscie'      => null,   // int   - unikalni w całym zakresie
		'nowi'        => null,   // int   - pierwszy raz widziani w zakresie
		'na_goscia'   => null,   // float - odsłony / goście
		'zrodla'      => null,   // [ ['klucz'=>'wyszukiwarki','ile'=>1240], … ]
		'urzadzenia'  => null,
		'kraje'       => null,
		'wejscia'     => null,   // strony, na których ruch WCHODZI
		'odsylajace'  => null,
		'kampanie'    => null,
		'czytane'     => null,   // najczęściej otwierane
		'godziny'     => null,   // [ ['dzien'=>1..7,'godzina'=>0..23,'ile'=>int], … ]
		/* Stare, a nadal czytane - liczone TUTAJ, nie przez analitykę.
		   [ ['klucz'=>'/adres','ile'=>420,'tytul'=>'…','odswiezony'=>'2024-03-01T…'], … ] */
		'stare_czytane' => null,
	);
}

/**
 * Wymiary dla zakresu dat.
 *
 * @param string $od  YYYY-MM-DD
 * @param string $do  YYYY-MM-DD
 */
function zbierz( string $od, string $do ): array {
	$wynik = apply_filters( 'bsite_ruch_wymiary', null, $od, $do );

	if ( ! is_array( $wynik ) ) {
		return pusty();
	}

	/* Scalamy z pustym kształtem, żeby brak jednego wymiaru nie znaczył braku klucza.
	   Aplikacja ma dostać komplet pól - inaczej musiałaby zgadywać, czy `null` to brak
	   danych, czy starsza wtyczka, która o tym polu nie słyszała. */
	$wynik = array_merge( pusty(), $wynik );

	$wynik['stare_czytane'] = stare_czytane( $wynik['czytane'] );

	return $wynik;
}

/**
 * STARE, A NADAL CZYTANE - teksty, które pracują mimo wieku.
 *
 * ═══ DLACZEGO LICZY TO WTYCZKA, A NIE ANALITYKA ═══
 *
 * Analityka wie, ile razy otwarto adres. Nie wie, kiedy ten tekst powstał ani kiedy
 * ostatnio go poprawiano - to jest wiedza WordPressa. Połączenie jednego z drugim musi
 * się więc odbyć po tej stronie; przerzucanie dat publikacji do analityki znaczyłoby
 * dublowanie tego, co i tak stoi w bazie.
 *
 * ═══ PO DACIE ZMIANY, NIE PUBLIKACJI ═══
 *
 * Tekst sprzed trzech lat poprawiony w zeszłym miesiącu nie jest zaległością - ktoś się
 * nim właśnie zajął. Zaległością jest ten, którego nikt nie tknął od dawna, a ludzie
 * wciąż na niego wchodzą: to jest dokładnie ta strona, na której nieaktualna informacja
 * robi najwięcej szkody.
 *
 * @param array|null $czytane Lista par `klucz` → `ile` z analityki.
 */
function stare_czytane( ?array $czytane ): ?array {
	if ( empty( $czytane ) || ! current_user_can( 'edit_posts' ) ) {
		return null;
	}

	/* Rok od ostatniej zmiany. Poniżej tego progu lista zapełniłaby się tekstami sprzed
	   paru miesięcy, czyli takimi, o których wszyscy jeszcze pamiętają - i przestałaby
	   pokazywać to jedno, o czym nikt nie pamięta. */
	$prog = (int) apply_filters( 'bsite_stare_czytane_dni', 365 );
	$teraz = time();
	$lista = array();

	foreach ( $czytane as $pozycja ) {
		$klucz = (string) ( $pozycja['klucz'] ?? '' );
		if ( '' === $klucz ) {
			continue;
		}

		/* ═══ IDENTYFIKATOR PRZED ADRESEM ═══
		   [BŁĄD, KTÓRY TO NAPRAWIA] Pierwsza wersja rozwiązywała `klucz` przez
		   `url_to_postid()`, zakładając, że to ścieżka. Nasza analityka wstawia tam
		   jednak TYTUŁ wpisu, więc dopasowanie nie udawało się ani razu i lista
		   wychodziła pusta zawsze - widget bez danych, wyglądający na działający.

		   Analityka, która zna numer wpisu, podaje go teraz wprost. Adres zostaje jako
		   droga zapasowa dla liczników operujących samymi ścieżkami. */
		$id = (int) ( $pozycja['wpis'] ?? 0 );
		if ( 0 === $id && str_starts_with( $klucz, '/' ) ) {
			$id = url_to_postid( home_url( $klucz ) );
		}
		if ( 0 === $id || 'publish' !== get_post_status( $id ) ) {
			continue;   // adres spoza treści albo wpis, którego już nie ma
		}

		$zmieniony = get_post_modified_time( 'U', true, $id );
		if ( ! $zmieniony || ( $teraz - (int) $zmieniony ) < $prog * DAY_IN_SECONDS ) {
			continue;
		}

		$lista[] = array(
			'klucz'      => $klucz,
			'ile'        => (int) ( $pozycja['ile'] ?? 0 ),
			'tytul'      => mb_substr( (string) get_the_title( $id ), 0, 120 ),
			'odswiezony' => (string) get_post_modified_time( 'c', true, $id ),
			'panel'      => get_edit_post_link( $id, 'raw' ) ?: null,
		);
	}

	/* Kolejność po ruchu, nie po wieku. Najstarszy tekst, który nikogo nie interesuje,
	   nie jest problemem - problemem jest ten, na który wchodzi najwięcej ludzi. */
	usort( $lista, static fn( $a, $b ): int => $b['ile'] <=> $a['ile'] );

	return array_slice( $lista, 0, 6 );
}
