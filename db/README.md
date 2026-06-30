# Database

## `schema.sql`

Full **structure-only** dump of the `magdyn` database (all tables, indexes,
foreign keys, views, routines and triggers — **no row data**). Use it to stand
up a fresh, empty database with the correct schema.

`AUTO_INCREMENT` counters are stripped so the file stays stable in version
control; the dump disables `FOREIGN_KEY_CHECKS` while loading, so table order
does not matter on import.

### Import (create a fresh database)

```bash
# 1. create an empty database
mysql -u root -e "CREATE DATABASE magdyn CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. load the structure
mysql -u root --default-character-set=utf8mb4 magdyn < db/schema.sql
```

On Windows / XAMPP, use the bundled client, e.g.
`C:\xampp74\mysql\bin\mysql.exe`.

### Seed / incremental changes

`schema.sql` contains **structure only**. Reference rows (modules, permissions,
roles, the employee directory, etc.) and any schema changes made after this dump
are delivered as ordered migration scripts under [`../sql/`](../sql) — apply them
after importing the schema:

```bash
mysql -u root --default-character-set=utf8mb4 magdyn < sql/migration_<timestamp>_IST.sql
```

### Refreshing this file

Regenerate after schema changes (run from the repo root):

```bash
mysqldump -u root --no-data --skip-dump-date --routines --triggers --events \
  --default-character-set=utf8mb4 magdyn --result-file=db/schema.sql
# then strip volatile AUTO_INCREMENT counters:
sed -i -E 's/ AUTO_INCREMENT=[0-9]+//g' db/schema.sql
```
