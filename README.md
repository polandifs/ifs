# Łowca Kamperów — moduł inwestycyjny (MVP)

Panel do selekcji ogłoszeń kamperów pod zakup, naprawę i sezonową odsprzedaż w Polsce.

## Uruchomienie
Otwórz `index.html` w przeglądarce lub opublikuj przez GitHub Pages (Settings → Pages → Deploy from a branch → main / root). Nie wymaga serwera do działania lokalnego.

## Funkcje MVP
- dodawanie ogłoszeń z linkiem do konkretnej oferty, źródłem, ceną i notatkami;
- kalkulacja zakupu po negocjacji, transportu, remontu, pozostałych kosztów, ceny odsprzedaży, marży kwotowej i procentowej;
- filtrowanie według minimalnego zysku, sortowanie i statusy;
- przechowywanie danych lokalnie w przeglądarce (localStorage);
- eksport i import JSON, by nie utracić listy podczas migracji.

## Ważne ograniczenia
- **Nie ma automatycznego skanowania portali ani Facebooka** w tej wersji. Przyciski źródeł otwierają ich strony wyszukiwania, a ogłoszenia dodaje się ręcznie. Wyszukiwanie automatyczne wymaga backendu i zgodnych z regulaminem integracji lub dostawcy danych.
- **Nie ma gwarancji zysku**. Cena odsprzedaży jest prognozą użytkownika, a wynik nie uwzględnia podatków ani finansowania.
- Dane przechowywane w przeglądarce mogą zostać utracone po wyczyszczeniu pamięci; regularnie eksportuj JSON.

## Dalsze etapy
1. Backend, baza PostgreSQL, uwierzytelnianie i harmonogram.
2. Adaptery źródeł z uprawnionym dostępem, deduplikacja i kontrola aktualności ogłoszeń.
3. Analiza porównywalnych transakcji, szacowanie cen odsprzedaży i ryzyka remontu.
4. Powiadomienia e-mail / Telegram o zweryfikowanych okazjach.
