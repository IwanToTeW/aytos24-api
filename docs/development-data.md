# Development data

`DevelopmentFoodSeeder` fills the database with fictional restaurants and daily lunch menus so the API and the frontend can be built without a restaurant dashboard.

## Running it

With the Docker stack running:

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class=DevelopmentFoodSeeder
```

- **Safe to re-run.** Records are matched on stable keys (slugs, restaurant + meal name, restaurant + date, menu + meal), so running it again the same day changes nothing. Running it on a later day adds that day's menus and leaves older ones in place.
- **Never deletes anything** and never resets the database.
- **Development only.** It throws an exception outside the `local`, `development` and `testing` environments, and it is not called by `DatabaseSeeder`, so `php artisan db:seed` does not run it.

## What it creates

All dates are calculated in `Europe/Sofia`, relative to when the seeder runs.

| Data | Details |
| --- | --- |
| Restaurants | 6, all named `Демо …`: 5 active, 1 inactive (`Демо Ресторант Пауза`). Phone numbers use the unassigned `+359 000` prefix and addresses are on the fictional `ул. Примерна`. |
| Categories | 8: soups, salads, main dishes, meatless dishes, grill, pasta, desserts, plus the inactive `seasonal` category. Existing categories with the same slug are not modified. |
| Today | 6 published menus, 50 items, 37 publicly discoverable. |
| Tomorrow | 3 draft menus (`published_at` is null), 5 items each. |
| Yesterday | 4 published menus, 6 items each. Some prices differ from today's. Existing past menus are never overwritten. |

### Items that must not appear in public discovery

These are deliberate, so BE-004 can be tested against them:

| Case | Example |
| --- | --- |
| Sold out today (`is_available = false`) | `Пиле с ориз` at Демо Бистро Център (6 items in total) |
| Inactive restaurant with a published menu | All of `Демо Ресторант Пауза` |
| Inactive meal on a published menu | `Свинско с праз`, `Макарони на фурна` |
| Meal in an inactive category | `Тиква на фурна` (category `seasonal`) |

Prices are illustrative, not real local prices. They are stored as integer euro cents in `daily_menu_items.price_cents`.
