# Łowca Kamperów

Prywatny panel do gromadzenia ofert kamperów i przyczep oraz szacowania wyniku zakupu, remontu i odsprzedaży. Kwoty z kalkulatora są scenariuszami przed podatkami i kosztami stałymi. Obniżka ceny, stan pojazdu i cena odsprzedaży wymagają weryfikacji.

## Wymagania

- PHP 7.4+ z PDO SQLite, cURL i mbstring.
- Hosting HTTPS.
- Katalog `data/` zapisywalny przez PHP.

## Instalacja na cPanel

1. Skopiuj pliki do katalogu wybranej domeny lub subdomeny.
2. Skopiuj `config.example.php` do `config.php`.
3. Wygeneruj hash hasła poleceniem `php -r 'echo password_hash("TWOJE_HASLO", PASSWORD_DEFAULT), PHP_EOL;'` i wklej go jako `password_hash` w `config.php`. Nie zapisuj jawnego hasła w pliku.
4. Ustaw losowy, długi `app_key` w `config.php` (np. `bin2hex(random_bytes(32))` uruchomione w PHP).
5. Ustaw ręcznie kurs EUR/PLN w panelu dla każdej oferty wycenionej w euro. Nie jest pobierany automatycznie.
6. Uruchom `php discover.php` z katalogu aplikacji. W cPanel ustaw harmonogram co 3 godziny: `0 */3 * * * /usr/local/bin/php /home/ifs/public_html/ifs-poland.com/ifskampery/discover.php >/dev/null 2>&1` (ścieżkę PHP dopasuj do serwera).
7. Wejdź na adres panelu przez HTTPS i zaloguj się.

Plik `config.php` i katalog `data/` są blokowane przez reguły `.htaccess`. Przy hostingu bez Apache umieść je poza katalogiem publicznym i dostosuj ścieżki w `app.php`.

## Zakres

- Ręczne zapisywanie ofert z usuwaniem duplikatów po adresie URL.
- Cykliczne sprawdzanie publicznych stron wyszukiwania Otomoto, Kleinanzeigen, Marktplaats i 2dehands w PL, DE, NL i BE. Dodatkowo najwyżej raz na 20 godzin analizowany jest publiczny eksport CSV WybierzKampera: oferty używane co najmniej 30% poniżej mediany tego samego typu, pięcioletniej grupy roczników i zbliżonego przebiegu są tropami do sprawdzenia. Skan zapisuje nowe linki, datę wykrycia, sygnał uszkodzenia i obniżkę ceny, gdy da się porównać odczyty. Wyniki nie potwierdzają dostępności, stanu, ceny ani zysku.
- Grupa Facebook „Camping i Caravaning Polska” jest podłączona jako źródło do ręcznego dodawania postów sprzedażowych. Serwer otrzymuje stronę logowania zamiast wpisów grupy, dlatego nie ma automatycznego odświeżania ofert Facebooka. Posty są oznaczane jako dodane ręcznie, osobno od wyników skanu.
- Kalkulacja przy cenie ofertowej i przy zakładanej negocjacji, z kosztami naprawy, transportu i rezerwą.
- Oznaczenie scenariusza jako „do sprawdzenia”, gdy szacowana nadwyżka wynosi co najmniej 15 tys. zł; priorytet od 25 tys. zł.

Skan działa w harmonogramie serwera i pokazuje ostatni wynik każdego źródła. Portale mogą zmieniać układ stron lub blokować automatyczne pobieranie; błąd źródła jest wtedy widoczny w panelu. Link z prywatnej grupy można zapisać ręcznie.

## Mieszkania Kwidzyn: ceny transakcyjne RCN

`mieszkania/rcn_sync.php` pobiera lokale z oficjalnej usługi WFS GUGiK `https://mapy.geoportal.gov.pl/wss/service/rcn`. Wybiera lokale mieszkalne z miasta Kwidzyn (TERYT gminy `220701_1`), transakcje wolnorynkowe i odrzuca wpisy bez wiarygodnej ceny pojedynczego lokalu. Dotychczasowe dane zostają zachowane, jeśli pobranie się nie powiedzie. Na serwerze IFS zadanie działa codziennie: `17 3 * * * /usr/local/bin/php /home/ifs/public_html/ifs-poland.com/ifskampery/mieszkania/rcn_sync.php >> /home/ifs/ifs-rcn-kwidzyn.log 2>&1`.

W `mieszkania/watch.php` porównanie RCN używa mediany cen za m² transakcji z rynku wtórnego z ostatnich 24 miesięcy i lokali o powierzchni ±20%. Wynik jest scenariuszem dla miasta, a nie wyceną konkretnego lokalu: nie uwzględnia standardu, kondygnacji, budynku, praw do nieruchomości ani opóźnienia publikacji RCN. Panel pokazuje liczbę i daty transakcji oraz oddzielnie porównanie cen ofertowych. Historia ogłoszenia ignoruje dawne pozorne zmiany metrażu wynikające z zapisu `45.0` i `45`.
