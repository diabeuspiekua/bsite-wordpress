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
- **przegląd** - same liczby: ile szkiców, komentarzy do moderacji, zgłoszeń, aktualizacji

Sprawdzanie aktualizacji samej wtyczki to zapytanie o listę wydań w tym repozytorium -
**bez adresu witryny, bez wersji PHP, bez czegokolwiek o tobie**. Wtyczka zainstalowana
u klienta nie ma prawa opowiadać o nim nikomu, także autorowi.

## Czego ta wtyczka NIE robi

**Nie zapisuje plików na serwerze.** Nie ma w niej kodu, który by to potrafił - i to jest
inna gwarancja niż „jest, ale wyłączone". Wdrożenia PHP to osobne narzędzie, instalowane
wyłącznie tam, gdzie się wdraża.

## Uprawnienia

Każda liczba w przeglądzie ma własny warunek. Konto bez uprawnienia dostaje `null`,
a nie zero - aplikacja rysuje wtedy kreskę, nie „zero zgłoszeń". Redaktor nie ma widzieć,
że zgłoszeń jest zero, skoro nie ma prawa ich oglądać.

| liczba | wymaga |
| --- | --- |
| szkice, zaplanowane | `edit_posts` |
| komentarze do moderacji | `moderate_comments` |
| zgłoszenia | `manage_options` |
| aktualizacje | `update_plugins` |

Bez `edit_others_posts` liczba szkiców obejmuje **tylko własne** - żeby zgadzała się z tym,
co widać w panelu.

## Wymagania

- WordPress 6.4+
- PHP 8.1+

## Wersja umowy

`BSITE_UMOWA` to wersja **kształtu odpowiedzi**, nie wtyczki. Rośnie tylko wtedy, gdy
zmienia się to, czego aplikacja się spodziewa. Aplikacja zna zakres wersji, które rozumie,
i mówi wprost, co zaktualizować - dzięki temu klient na starszej wtyczce nie psuje niczego
pozostałym.

## Licencja

GPL-2.0-or-later. Ta sama, co WordPress.
