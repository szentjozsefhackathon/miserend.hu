# Gondolatok a szerverrel kapcsolatban

Szükséges a caddy elindítása: `sudo caddy start --config /etc/caddy/Caddyfile`

Aztán a docker: `docker compose miserend up -d`

Ha teljes mysqldump másolásra van szükség: 

```
 docker cp [local sql file] mysql:/file.sql
 docker exec -it -u root mysql bash
 mysql -u user -p miserend < file.sql
```

A `user` fiók neve/jelszava a `MYSQL_USER` / `MYSQL_PASSWORD` env-változóból jön
(alapértelmezés: `user` / `pw`), a rootté a `MYSQL_ROOT_PASSWORD`-ből. Ha az `.env`-ben
egyedit állítasz be, azt az adatbázisban is át kell vezetni — részletek az
`.env.example`-ben (#668).

