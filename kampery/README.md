# Łowca Kamperów

Prywatny panel do gromadzenia ofert kamperów i przyczep oraz szacowania wyniku zakupu, remontu i odsprzedaży. Kwoty z kalkulatora są scenariuszami przed podatkami i kosztami stałymi. Obniżka ceny, stan pojazdu i cena odsprzedaży wymagają weryfikacji.

## Wymagania

- PHP 8.1+ z PDO SQLite, cURL i mbstring.
- Hosting HTTPS.
- Katalog `data/` zapisywalny przez PHP.

## Instalacja na cPanel

1. Skopiuj pliki do katalogu wybranej domeny lub subdomeny.
2. Skopiuj `config.example.php` do `config.php`.
3. Wygeneruj hash hasła poleceniem `php -r 'echo password_hash("TWOJE_HASLO", PASSWORD_DEFAULT), PHP_EOL;'` i wklej go jako `password_hash` w `config.php`. Nie zapisuj jawnego hasła w pliku.
4. Ustaw losowy, długi `app_key` w `config.php` (np. `bin2hex(random_bytes(32))` uruchomione w PHP).
5. Ustaw ręcznie kurs EUR/PLN w panelu dla każdej oferty wycenionej w euro. Nie jest pobierany automatycznie.
6. Opcjonalnie dodaj klucz SerpAPI w `config.php`, aby użyć wyszukiwania. Każde wyszukanie może zużywać limit usługi.
7. Wejdź na adres panelu przez HTTPS i zaloguj się.

Plik `config.php` i katalog `data/` są blokowane przez reguły `.htaccess`. Przy hostingu bez Apache umieść je poza katalogiem publicznym i dostosuj ścieżki w `app.php`.

## Zakres

- Ręczne zapisywanie ofert z usuwaniem duplikatów po adresie URL.
- Wyszukiwanie publicznych wyników Google przez SerpAPI w PL, DE, NL i BE, osobno na portalach ogłoszeniowych i w publicznych wynikach Facebooka. Wyniki są tropami do sprawdzenia; nie potwierdzają dostępności ani ceny.
- Kalkulacja przy cenie ofertowej i przy zakładanej negocjacji, z kosztami naprawy, transportu i rezerwą.
- Oznaczenie scenariusza jako „do sprawdzenia”, gdy szacowana nadwyżka wynosi co najmniej 15 tys. zł; priorytet od 25 tys. zł.

Panel nie przeszukuje prywatnych grup Facebooka i nie pobiera automatycznie ich treści. Link z takiej grupy można zapisać ręcznie. Nie ma jeszcze harmonogramu, powiadomień ani integracji z cPanel; to kolejne etapy po sprawdzeniu hostingu i źródeł ofert.
