---
paths:
    - "database/**"
---

# Database

## PostgreSQL only - no SQLite anywhere

Production is a shared `postgres:18-alpine` cluster on Coolify, and the dashboard aggregates in SQL only PostgreSQL speaks (`generate_series`, `jsonb` `->>` operators, `::int` casts in `Dashboard::buckets()`). Development and the test suite therefore run on the same image via `docker-compose.yml` (`docker compose up -d`, port 5432, databases `weather_station` and `weather_station_test` - the test one is created by `docker/pgsql/init.sql` on first start). CI starts the same image as a service. Do not add an SQLite path back for tests or local use: it would pass locally and mislead, because the bucket query cannot run on it. Timescale was considered and rejected - the shared cluster has no extension, and 52k rows a year do not need one.
