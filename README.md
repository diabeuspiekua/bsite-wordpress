# bSite

Wtyczka WordPressa, która łączy witrynę z aplikacją **bSite** na iOS i macOS.

Aplikacja pokazuje wszystkie twoje strony naraz: co czeka na publikację, gdzie przyszły
nowe zgłoszenia, co wymaga aktualizacji. Panel WordPressa zawsze dotyczy jednej witryny -
kto prowadzi cztery, loguje się cztery razy. To jest cała rzecz, którą bSite rozwiązuje.

## Instalacja

1. Pobierz `bsite.zip` z [ostatniego wydania](../../releases/latest).
2. W panelu: **Wtyczki → Dodaj wtyczkę → Wyślij wtyczkę na serwer**.
3. Włącz.

Od tej chwili wtyczka **aktualizuje się sama** - kolejne wydania pojawiają się w panelu
jak każda inna aktualizacja.

## Podłączenie aplikacji

Aplikacja loguje się **hasłem aplikacji WordPressa**, nie hasłem do konta.

1. W panelu: **Użytkownicy → Profil → Hasła aplikacji**.
2. Utwórz nowe, nazwij je tak, żebyś wiedział, które to urządzenie.
3. W aplikacji wpisz adres witryny, potem login i to hasło.

Hasło aplikacji jest wydawane **per urządzenie** i odwołuje się je jednym kliknięciem
w tym samym miejscu. Zgubiony telefon to jedno kliknięcie, nie zmiana hasła do konta.

## Co ta wtyczka wysyła

Wyłącznie na żądanie zalogowanego, wyłącznie do jego aplikacji:

- **manifest** - nazwa witryny, wersja wtyczki, dostępne moduły i uprawnienia konta
- **przegląd** - liczby: szkice, komentarze do moderacji, zgłoszenia, aktualizacje,
  plus najbliższe zaplanowane wpisy z tytułami i datami
- **statystyki** - odsłony i goście dzień po dniu
- **wymiary ruchu** - źródła, urządzenia, kraje, strony wejścia, najczęściej czytane,
  godziny odwiedzin, średni czas wizyty
- **kondycja** - widoczność dla wyszukiwarek, wersje WordPressa i PHP, waga bazy,
  zaległe zadania cykliczne, wiek ostatniej kopii, liczba nieudanych wysyłek poczty
- **zgłoszenia** - nagłówek, data i to, czy ktoś się już nimi zajął

Sprawdzanie aktualizacji samej wtyczki to zapytanie o listę wydań w tym repozytorium -
**bez adresu witryny, bez wersji PHP, bez czegokolwiek o tobie**. Wtyczka zainstalowana
u klienta nie ma prawa opowiadać o nim nikomu, także autorowi.

### Czego NIE ma w zgłoszeniach i w poczcie

Ani treści wiadomości, ani adresu e-mail, ani numeru telefonu.

Zgoda, którą ktoś daje, wysyłając formularz, jest zgodą na kontakt z firmą. Nie obejmuje
tego, żeby jego sprawa trafiła do pamięci cudzego telefonu, do kopii zapasowej tego
telefonu i do wszystkiego, przez co ta kopia przechodzi. Aplikacja mówi więc, ŻE coś
przyszło i kiedy - a do przeczytania prowadzi odnośnik do panelu, gdzie ta korespondencja
i tak leży.

Tak samo z pocztą: zaczep `wp_mail_failed` niesie pełny komunikat wraz z adresami
odbiorców, a wtyczka zapisuje z niego **sam znacznik czasu**. Liczy zdarzenia, nie
przenosi treści.

### Skąd wtyczka wie o kopiach zapasowych

Czyta datę najnowszego pliku archiwum w katalogach czterech znanych wtyczek do kopii
(All-in-One WP Migration, UpdraftPlus, BackWPup, `wp-content/backups`). Po plikach,
nie po ich opcjach: każda wtyczka trzyma swój stan inaczej i zmienia to między wydaniami,
a plik archiwum jest tym samym u wszystkich.

Brak takich katalogów daje `null`, a nie „bardzo stara kopia" - kopie bywają robione po
stronie hostingu, a fałszywy alarm nauczyłby ignorować prawdziwe.

## Czego ta wtyczka NIE robi

**Nie zapisuje plików na serwerze.** Nie ma w niej kodu, który by to potrafił - i to jest
inna gwarancja niż „jest, ale wyłączone". Wdrożenia PHP to osobne narzędzie, instalowane
wyłącznie tam, gdzie się wdraża.

## Uprawnienia

Każda liczba w przeglądzie ma własny warunek. Konto bez uprawnienia dostaje `null`,
a nie zero - aplikacja rysuje wtedy kreskę, nie „zero zgłoszeń". Redaktor nie ma widzieć,
że zgłoszeń jest zero, skoro nie ma prawa ich oglądać.

| dane | wymaga |
| --- | --- |
| szkice, zaplanowane, kalendarz publikacji | `edit_posts` |
| komentarze do moderacji | `moderate_comments` |
| statystyki i wymiary ruchu | `edit_posts` |
| zgłoszenia | `manage_options` |
| kondycja, kopie, poczta | `manage_options` |
| aktualizacje | `update_plugins` |

Zgłoszenia i kondycja mają wyższy próg niż treść. Zgłoszenie to korespondencja z firmą,
a nie materiał redakcyjny - redaktor piszący teksty nie ma powodu czytać, kto się z kim
umawia. Kondycja z tego samego powodu: ile waży baza, nie jest jego sprawą.

Bez `edit_others_posts` liczba szkiców obejmuje **tylko własne** - żeby zgadzała się z tym,
co widać w panelu.

## Wymagania

- WordPress 6.4+
- PHP 8.1+

## Skąd wtyczka bierze zgłoszenia

Formularze na WordPressie robi kilkanaście wtyczek i każda trzyma zgłoszenia gdzie indziej.
Wpisanie ich tu wszystkich znaczyłoby czytanie cudzych tabel wprost - czyli zależność od
kształtu, którego nikt nie obiecał i który zmienia się między wydaniami.

Wtyczka zna więc kształt WordPressowy (typ wpisu `feedback`, używany przez Jetpacka
i kilka innych), a wszystko poza tym dokłada motyw albo wtyczka witryny jednym filtrem:

```php
add_filter( 'bsite_zgloszenia_zrodlo', function ( $puste ) {
    return array(
        'typ'            => 'moje_zgloszenie',   // typ wpisu
        'nazwa'          => 'formularz kontaktowy',
        'meta_obsluzone' => 'moje_obsluzone',    // pole „załatwione"; null, gdy witryna go nie prowadzi
        'statusy'        => array( 'publish', 'pending' ),
    );
} );
```

Bez pola „załatwione" wtyczka rozpoznaje nowe zgłoszenia po statusie wpisu.

## Wersja umowy

`BSITE_UMOWA` to wersja **kształtu odpowiedzi**, nie wtyczki. Rośnie tylko wtedy, gdy
zmienia się to, czego aplikacja się spodziewa. Aplikacja zna zakres wersji, które rozumie,
i mówi wprost, co zaktualizować - dzięki temu klient na starszej wtyczce nie psuje niczego
pozostałym.

## Licencja

GPL-2.0-or-later. Ta sama, co WordPress.
