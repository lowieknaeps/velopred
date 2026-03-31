Plaats hier lokale profielfoto's van renners in hoge kwaliteit.

Bestandsnaam:
- `<pcs_slug>.webp`
- `<pcs_slug>.jpg`
- `<pcs_slug>.jpeg`
- `<pcs_slug>.png`

Voorbeeld:
- `wout-van-aert.jpg`
- `tadej-pogacar.webp`

De app gebruikt eerst deze lokale map en valt pas daarna terug op PCS-thumbnails.

Automatisch laten ophalen:
- `php artisan photos:sync-riders --limit=40`
- `php artisan photos:sync-riders --all --limit=120`
