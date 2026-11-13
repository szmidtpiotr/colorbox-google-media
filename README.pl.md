# Colorbox Google Media — filmy z Dysku i Zdjęcia Google w WordPressie

*[English version → README.md](README.md)*

Wtyczka WordPressa, dzięki której filmy nie zajmują miejsca na serwerze, a zdjęcia są o jedno kliknięcie:

- **Wysyłasz film z edytora bloków prosto na swój Dysk Google** i osadzasz go z okładką, którą sam wybierasz.
- **Wybierasz film wysłany wcześniej** i wstawiasz go ponownie — okładka jest zapamiętana.
- **Importujesz zdjęcia ze Zdjęć Google** do biblioteki mediów i wstawiasz je jako galerię.
- **Wklejasz dowolny link do filmu z Dysku Google** w osobnej linii i robi się z niego odtwarzacz.

**Po co:** pliki wideo są duże jak na mały hosting WordPressa, a YouTube nie zawsze jest dla nich dobrym miejscem.
Dysk i tak trzyma Twoje filmy, ale jego odtwarzacz pokazuje czarną pierwszą klatkę i łączy każdego odwiedzającego
z Google. Ta wtyczka wysyła film z przeglądarki prosto na Dysk (bez limitu wysyłania PHP), pokazuje Twoją okładkę
i ładuje odtwarzacz Dysku dopiero, gdy ktoś kliknie „odtwórz”.

## Co potrafi

| Funkcja | Jak |
|---|---|
| Blok **Film z Dysku** | Wybierasz plik → przewijasz do klatki → *Wyślij — ta klatka to okładka*. Przeglądarka wysyła film prosto do Google (wysyłanie wznawialne z paskiem postępu), a klatka trafia do biblioteki mediów jako JPEG. Blok zamienia się w zwykły blok „Osadzenie”. |
| Filmy wysłane wcześniej | *Wybierz film z Dysku* pokazuje folder wtyczki na Dysku. *Wstaw* używa zapamiętanej okładki, *Inna klatka* pobiera film do przeglądarki, żeby wybrać jeszcze raz. |
| Odtwarzacz po kliknięciu | Najpierw okładka z przyciskiem, ramka Dysku (i jakiekolwiek połączenie z Google) dopiero po kliknięciu. Pionowe filmy z telefonu same dostają ramkę 9:16. |
| Blok **Zdjęcia Google** | Otwiera w nowej karcie okno wyboru zdjęć od Google. Wybrane zdjęcia trafiają jako kopie do biblioteki mediów (najwyżej 2560 px, JPEG — także HEIC z iPhone'a), bez lokalizacji i danych aparatu, i wstawiają się jako galeria. |
| Wklejone linki z Dysku | `drive.google.com/file/d/ID/view` (też `/preview`, `/edit`, `?usp=sharing`, `open?id=ID`) w osobnej linii staje się tym samym odtwarzaczem. Opcje po `#`: `vertical` (pionowo), `cover=<ID załącznika>` (okładka), np. `…/view#vertical&cover=123`. |
| Języki | Polski i angielski, zgodnie z językiem WordPressa. |

## Wymagania

- WordPress 6.5+, PHP 8.1+, bezpośrednie odnośniki inne niż „Prosty”, HTTPS.
- Konto Google i darmowy projekt w Google Cloud z własnym klientem OAuth — zobacz
  [konfigurację Google Cloud krok po kroku](docs/pl/konfiguracja-google-cloud.md) (około 15 minut, jeden raz).

## Najkrótsza droga

1. Pobierz ZIP z **Releases** (albo zbuduj: `bin/build-zip.sh`), potem **Wtyczki → Dodaj wtyczkę → Wyślij wtyczkę
   na serwer → Włącz**.
2. Zrób [konfigurację Google Cloud](docs/pl/konfiguracja-google-cloud.md): włącz *Google Drive API* i *Photos Picker
   API*, opublikuj ekran zgody, utwórz klienta OAuth typu *Aplikacja internetowa* z adresem przekierowania pokazanym
   w **Ustawienia → Media Google**.
3. **Ustawienia → Media Google**: wklej identyfikator i tajny klucz klienta, **Zapisz**, **Połącz z Google**.
4. W edytorze dodaj blok **Film z Dysku** albo **Zdjęcia Google**.

## Prywatność i bezpieczeństwo

- **Zakresy dostępu:** `drive.file` — wtyczka widzi tylko pliki, które sama utworzyła, nigdy reszty Dysku;
  `photospicker.mediaitems.readonly` — tylko zdjęcia, które wskażesz w oknie Google, przez 60 minut.
- **Tylko administrator** może wysyłać i importować (filtr `colorbox_gm_capability`). Krótko ważny token dostępu
  trafia do przeglądarki administratora na czas wysyłania; redaktorzy — także konta agentów AI — go nie dostają.
- Tajny klucz klienta OAuth i klucz odświeżania leżą w bazie WordPressa (opcja `colorbox_gm_settings`), nigdy
  w plikach. **Rozłącz** cofa dostęp po stronie Google.
- Odwiedzający nie łączą się z Google, dopóki nie klikną „odtwórz”. Wspomnij o filmach z Dysku w polityce prywatności.
- Importowane zdjęcia tracą EXIF/XMP/IPTC (GPS, aparat, daty); zostaje tylko profil kolorów. Jeśli się nie uda,
  zdjęcie jest odrzucane.

## Ograniczenia

- **Dysk to nie serwis do streamingu.** Plik oglądany przez wiele osób w krótkim czasie dostaje chwilową blokadę
  „Zbyt wielu użytkowników…”. Na osobisty blog wystarczy; na film, który ma się rozejść, użyj YouTube albo CDN wideo.
- Zaraz po wysłaniu Dysk potrzebuje kilku minut na przetworzenie filmu; do tego czasu odtwarzacz o tym informuje.
- Lista filmów pokazuje tylko filmy wysłane przez wtyczkę (tyle pozwala `drive.file`). Inne filmy z Dysku działają
  jako wklejone linki, jeśli mają dostęp „Każdy, kto ma link”.
- Filmy wybrane w Zdjęciach Google są pomijane.
- Niezweryfikowana aplikacja Google pokazuje przy łączeniu ostrzeżenie; na własnym koncie przechodzisz dalej przez
  *Zaawansowane*. Udostępnienie aplikacji cudzym kontom wymagałoby weryfikacji przez Google.

## Haki

| Hak | Do czego |
|---|---|
| `colorbox_gm_capability` (filtr) | Uprawnienie wymagane do wysyłania i importu. Domyślnie `manage_options`. |
| `colorbox_gm_folder_name` (filtr) | Nazwa folderu na Dysku tworzonego przy pierwszym wysłaniu. |
| `colorbox_gm_embed_html` (filtr) | Końcowy HTML odtwarzacza. |
| `colorbox_mcp_instructions` (filtr) | Gdy działa [Colorbox MCP](https://github.com/szmidtpiotr/colorbox-mcp), jego agent AI dowiaduje się, jak osadzać filmy z Dysku. |

## Układ repozytorium

```
colorbox-google-media.php   punkt wejścia wtyczki
includes/                   google.php (OAuth, API), settings.php, drive.php, embed.php, photos.php
assets/js/                  dwa bloki edytora (czysty JS, bez budowania)
languages/                  tłumaczenie polskie (.po/.mo/.json/.l10n.php) i szablon .pot
docs/                       konfiguracja Google Cloud (en/, pl/)
bin/build-zip.sh            buduje ZIP do instalacji
```

## Stan projektu

Wersja 0.1.0, wyrosła z wtyczki „zawsze włączonej” na żywej stronie (WordPress 7.1, PHP 8.3), gdzie wysyłanie
i osadzanie filmów z Dysku jest w codziennym użyciu. Jeszcze nie ma: zrzutów ekranu w dokumentacji, sprzątania
ustawień przy odinstalowaniu, wyboru istniejącego folderu na Dysku (wymagałoby okna wyboru folderów od Google).

## Licencja

[GPL-2.0-or-later](LICENSE), tak jak sam WordPress.
