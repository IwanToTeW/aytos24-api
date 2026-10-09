# Today's meals API — performance

Measurements for `GET /api/v1/meals/today` (BE-004), taken on 9 October 2026.

## Setup

- 50 active restaurants × 20 meals = **1,000 items** on today's published menus, 7 categories.
- Local Docker stack (PHP 8.4 FPM, MySQL 8.4) on a developer Mac; requests dispatched through the HTTP kernel, so routing, validation, rate limiting and JSON serialisation are included.
- 25 requests per case, `APP_DEBUG=false`.

## Results

| Request | Median | p95 | Queries |
| --- | --- | --- | --- |
| defaults (`page=1`, `per_page=20`) | 16.5 ms | 42.9 ms | 7 |
| `per_page=50` | 19.6 ms | 22.7 ms | 7 |
| `per_page=50&page=20` (last page) | 18.9 ms | 25.2 ms | 7 |
| `category=…` | 10.0 ms | 14.6 ms | 7 |
| `search=…` | 7.4 ms | 9.2 ms | 7 |

The query count is the same for every page size and page number, so there is no N+1:

1. Distinct time zones of active restaurants (to work out each restaurant's local "today").
2. Total count for pagination.
3. The requested page of menu items, filtered and in neutral order.
4. to 7. Eager loads of the page's meals, categories, daily menus and restaurants.

When nothing matches, only queries 1 and 2 run. Only the requested page of models is hydrated; this is covered by `tests/Feature/Api/V1/TodayMeals/PerformanceTest.php`.

## Query plan

`EXPLAIN` of the page query with 1,000 items:

| Table | Access | Index |
| --- | --- | --- |
| `meal_categories` | ref | `meal_categories_is_active_sort_order_index` |
| `meals` | ref | `meals_meal_category_id_is_active_index` |
| `daily_menu_items` | ref | `daily_menu_items_meal_id_restaurant_id_index` |
| `daily_menus` | eq_ref | `PRIMARY` |
| `restaurants` | eq_ref | `PRIMARY` |

Every table is reached through an index. The only sort (`Using temporary; Using filesort`) is the neutral ordering itself: the round-robin rank is a `ROW_NUMBER()` window over all of today's eligible items, so it has to be computed before the page can be cut. Its cost grows with the number of meals available today, not with history, and stays well under the times above for a town-sized dataset. If daily volumes ever grow by orders of magnitude, the rank and daily key could be precomputed when a menu is published.

## Rate limit

`throttle:api` applies to every route in `routes/api.php`: **60 requests per minute per client IP** (`AppServiceProvider`). One page view makes one request, so normal browsing stays far below the limit.
