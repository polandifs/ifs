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
- `facebook_groups.php` pozwala dodawać adresy grup, włączać i wyłączać ich sprawdzanie oraz zapisywać bezpośrednie linki do postów. Na początek są zapisane trzy grupy: Camping i Caravaning Polska, Kampery PL oraz Kampery i Kampervany na Sprzedaż. `facebook_group_scan.php` jest wywoływany przez obecny skan kamperów co 3 godziny. Odczytuje wyłącznie posty sprzedażowe dostępne w publicznym HTML, bez ciasteczek logowania. Zapisuje status każdego źródła; jeśli Facebook kieruje serwer do logowania, nie zapisuje żadnych domniemanych ofert. Test serwera 1 listopada 2026 dla dwóch nowych grup skończył się przekierowaniem do logowania, więc ich automatyczny import jest obecnie zablokowany przez Facebooka. Ręcznie dodane posty są oznaczone osobno od wyników skanu.
- Kalkulacja przy cenie ofertowej i przy zakładanej negocjacji, z kosztami naprawy, transportu i rezerwą.
- Oznaczenie scenariusza jako „do sprawdzenia”, gdy szacowana nadwyżka wynosi co najmniej 15 tys. zł; priorytet od 25 tys. zł.

Skan działa w harmonogramie serwera i pokazuje ostatni wynik każdego źródła. Portale mogą zmieniać układ stron lub blokować automatyczne pobieranie; błąd źródła jest wtedy widoczny w panelu. Link do posta z grupy niedostępnej dla serwera można zapisać ręcznie.

## Mieszkania Kwidzyn: ceny transakcyjne RCN

Grupa Facebook [KWIDZYN Sprzedam Wynajme mieszkanie Okolice](https://www.facebook.com/groups/1572854416367362/) jest dostępna z `mieszkania/facebook.php`. Publiczna odpowiedź grupy nie zawiera postów do wiarygodnego automatycznego importu. Użytkownik może wkleić bezpośredni link do posta o sprzedaży mieszkania w Kwidzynie i wpisać cenę, metraż, pokoje oraz ulicę. Taki post trafia do obserwowanych, opcjonalnie do ulubionych, ale nie uruchamia powiadomienia o nowej ofercie. Ręczne posty Facebooka nie są próbką do porównania cen ofertowych.

`mieszkania/rcn_sync.php` pobiera lokale z oficjalnej usługi WFS GUGiK `https://mapy.geoportal.gov.pl/wss/service/rcn`. Wybiera lokale mieszkalne z miasta Kwidzyn (TERYT gminy `220701_1`), transakcje wolnorynkowe i odrzuca wpisy bez wiarygodnej ceny pojedynczego lokalu. Dotychczasowe dane zostają zachowane, jeśli pobranie się nie powiedzie. Na serwerze IFS zadanie działa codziennie: `17 3 * * * /usr/local/bin/php /home/ifs/public_html/ifs-poland.com/ifskampery/mieszkania/rcn_sync.php >> /home/ifs/ifs-rcn-kwidzyn.log 2>&1`.

Wycena porównuje transakcje z rynku wtórnego z ostatnich 24 miesięcy, o powierzchni ±20% i odległości do 500 m od wskazanej ulicy. Przy minimum 10 transakcjach wyłącza z mediany ceny za m², które leżą poza granicą 1,5 IQR i jednocześnie co najmniej 25% od mediany. Wyłączone wpisy nadal widać w `mieszkania/rcn_market.php`. Co najmniej 5 pozostałych transakcji i 5 aktualnie widzianych ofert jest potrzebnych do porównania. Ceny sprzedaży i ceny ofertowe są pokazywane osobno. Gdy ich zakresy się nie pokrywają albo pokrywają się tylko wąsko przy znacznie różnych medianach, panel nie wyznacza jednej kwoty ani propozycji dla sprzedawcy. Stan, piętro, budynek i prawa do nieruchomości wymagają osobnego sprawdzenia. Historia ogłoszenia ignoruje dawne pozorne zmiany metrażu wynikające z zapisu `45.0` i `45`.

## Prywatne archiwum alertów PDF

`mieszkania/import_email_pdfs.py` tworzy lokalną bazę SQLite z PDF-ów alertów „Mieszkania Kwidzyn”. Przykład: `python3 mieszkania/import_email_pdfs.py '/Users/piotr/Downloads/wycena ' data/adradar_email_history.sqlite`. Baza zapisuje datę maila, cenę ofertową, metraż, pokoje, ulicę, zmianę ceny i identyfikator grupy Adradar. Tabela `email_offer_events` zachowuje alerty, a widok `email_price_history` układa je według ogłoszenia i daty. Pole `quality` oznacza brak ulicy, brak ceny lub typ lokalu do ręcznej kontroli. Nie są importowane maile z innych kategorii ani linki do wypisania się. Historyczne ceny ofertowe nie są cenami transakcyjnymi i nie wchodzą do bieżącej automatycznej wyceny.
