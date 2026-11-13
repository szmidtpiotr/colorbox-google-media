# Konfiguracja Google Cloud (jeden raz, około 15 minut)

*[English version → ../en/google-cloud-setup.md](../en/google-cloud-setup.md)*

Wtyczka łączy się z Google przez **Twojego własnego** klienta OAuth, więc żadna cudza aplikacja nie dotyka Twojego
Dysku ani Zdjęć. Google Cloud jest do tego darmowy. Nazwy menu poniżej pochodzą z konsoli Google Cloud z końca 2026
roku; Google czasem je zmienia, więc szukaj najbliższego odpowiednika.

## 1. Projekt i interfejsy API

1. Otwórz [console.cloud.google.com](https://console.cloud.google.com/) i utwórz projekt (dowolna nazwa, np.
   „media-mojej-strony”).
2. **Interfejsy API i usługi → Biblioteka**: wyszukaj i **Włącz** oba:
   - **Google Drive API** (wysyłanie filmów i lista filmów),
   - **Photos Picker API** (import ze Zdjęć Google).

## 2. Ekran zgody (Platforma uwierzytelniania Google)

Otwórz **Platformę uwierzytelniania Google** (prowadzi tam też „Interfejsy API i usługi → Ekran zgody OAuth”).

1. **Elementy marki**: nazwa aplikacji (np. nazwa strony), e-mail pomocy dla użytkowników, e-mail kontaktowy
   dewelopera. W *Autoryzowanych domenach* dodaj swoją stronę, podaj też link do polityki prywatności.
   **Nie wgrywaj logo** — logo uruchamia weryfikację marki przez Google, która trwa dniami.
2. **Odbiorcy**: typ użytkowników **Z zewnątrz**. Potem **Opublikuj aplikację** (stan *W produkcji*).
   - Dlaczego: w trybie *Testowanie* Google wpuszcza tylko wpisanych testerów **i kasuje połączenie po 7 dniach**.
   - Przycisk *Opublikuj aplikację* jest wyszarzony? Brakuje czegoś w Elementach marki.
   - Chcesz najpierw spróbować? Dopisz swój adres w *Użytkownicy testowi*; trzeba będzie łączyć się co tydzień.
3. **Dostęp do danych** (zalecane): *Dodaj lub usuń zakresy* → dodaj
   `.../auth/drive.file` i `.../auth/photospicker.mediaitems.readonly`.

## 3. Klient OAuth

1. **Klienty → Utwórz klienta**, typ aplikacji **Aplikacja internetowa**, dowolna nazwa.
2. *Autoryzowane źródła JavaScriptu*: zostaw puste.
3. *Autoryzowane identyfikatory URI przekierowania* → **Dodaj URI** → wklej adres pokazany w WordPressie w
   **Ustawienia → Media Google** (wygląda jak `https://twoja-strona/wp-json/colorbox-google-media/v1/oauth-callback`).
   Musi się zgadzać co do znaku — bez ukośnika na końcu.
4. Pole „Używaj tego klienta w przypadku agenta opartego na AI” zostaw puste. **Utwórz**.
5. Od razu skopiuj **Identyfikator klienta** i **Tajny klucz klienta** (albo pobierz JSON) — konsola pokazuje cały
   klucz tylko przy tworzeniu.

## 4. Połączenie w WordPressie

1. **Ustawienia → Media Google**: wklej identyfikator i tajny klucz, **Zapisz**.
2. **Połącz z Google**, wybierz konto.
3. „Ta aplikacja nie została zweryfikowana przez Google”: kliknij **Zaawansowane → Przejdź do … (niebezpieczne)**.
   To Twoja własna aplikacja; adres dewelopera na tym ekranie jest Twój.
4. Na ekranie zgody **zaznacz oba pola** (pliki na Dysku i Zdjęcia Google) — Google czasem zostawia je puste —
   i **Kontynuuj**.
5. W WordPressie powinno być: *Połączono*, *Dysk Google: dostęp jest*, *Zdjęcia Google: dostęp jest*.

## Problemy

| Objaw | Przyczyna | Co zrobić |
|---|---|---|
| `Błąd 403: access_denied`, „aplikacja jest testowana” | Ekran zgody w trybie *Testowanie*, a Ty nie jesteś testerem | Opublikuj aplikację (krok 2.2) albo dopisz się do testerów. |
| `Błąd 500` na stronie Google zaraz po konfiguracji | Google jeszcze rozsyła świeże ustawienia („od 5 minut do kilku godzin”) | Odczekaj ok. 10 minut i kliknij jeszcze raz **Połącz z Google** w WordPressie (nie odświeżaj strony błędu Google). |
| `redirect_uri_mismatch` | Adres przekierowania różni się od tego w WordPressie albo zmiana jeszcze nie dotarła | Skopiuj jeszcze raz adres z Ustawienia → Media Google; odczekaj kilka minut. |
| „Powrót z Google bez ważnej sesji” | Minęło ponad 15 minut albo strona została odświeżona | Kliknij jeszcze raz **Połącz z Google**. |
| Działało tydzień, potem „Google nie wydał tokenu (invalid_grant)” | Aplikacja została w trybie *Testowanie* | Opublikuj aplikację i połącz ponownie. |
| *Zdjęcia Google: brak dostępu* | Photos Picker API niewłączone albo pole Zdjęć zostało odznaczone | Włącz API, potem **Połącz ponownie** i zaznacz oba pola. |
| „Google nie oddał klucza odświeżania” | Istnieje stara zgoda | Usuń aplikację na [myaccount.google.com/permissions](https://myaccount.google.com/permissions) i połącz ponownie. |
