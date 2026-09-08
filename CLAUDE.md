# ITECKA Website

B2B-Website für ITECKA GmbH (Graz) — LED-Beleuchtung für Büro, Industrie und Außenbereich.

## Struktur

- `index.html` — eine einzige, große Single-Page-App (Vanilla JS, kein Framework). Markup, CSS und JS inline, Produktfotos als eingebettete Base64-JPEGs. Routing client-seitig über `go(page, slug)` und die History-API.
- PHP-Skripte im selben Verzeichnis (`anfrage.php`, `review.php`, `reviews.php`) — schreiben/lesen Formulardaten über die Notion-API. Secrets liegen in `anfrage-config.php`, **nicht im Repo**.
- `PRODUCTS`-Array (JS) mit `slug/cat/catName/name/desc/badges/variants[]`. Preise als Komma-String (z. B. `"81,43"`).
- `sitemap.xml` und `robots.txt` im selben Verzeichnis, manuell gepflegt.

## Deployment

GitHub Actions pusht bei jedem Push auf `main` per FTPS zu easyname-Hosting. Kein Build-Schritt. Deploy-Status immer über die GitHub-Actions-API prüfen, nie annehmen.

## Lokal testen

```
python3 -m http.server <port> --directory /home/user/cloud-website
```

Vor jedem Deploy alle inline `<script>`-Blöcke (außer `application/ld+json`) mit `new Function(code)` auf Syntaxfehler prüfen.

## Hinweis

Ein separater, wöchentlich laufender SEO-Agent für `/stromsparrechner` hat seine eigenen, ausführlichen Rollenanweisungen direkt in seinem Routine-Prompt (nicht hier) — damit sie nicht in jede Session dieses Repos einfließen, die an etwas anderem arbeitet.
