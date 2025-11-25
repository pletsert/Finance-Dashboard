# Finance Dashboard (standalone)

Egyszerű pénzügyi áttekintő, időszakos bontással. Futhat SQLite-tal önállóan vagy MySQL-lel környezeti változókon keresztül.

## Futtatás
1. PHP 7.4+ szükséges.
2. `php -S 127.0.0.1:8080`
3. Első indítás: `http://127.0.0.1:8080/setup.php`

## Konfiguráció
`.env.example` alapján hozd létre a `.env`-et. Alapértelmezetten SQLite-ot használ (data/finance.sqlite).

## Mappák
- `tools/finance_dashboard/config_finance.php` – PDO kapcsolat, SQLite fallback
- `database/schema.sql` – sémadefiníció
- `setup.php` – alap adatok felvétele
