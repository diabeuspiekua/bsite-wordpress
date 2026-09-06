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
	return array_merge( pusty(), $wynik );
}
