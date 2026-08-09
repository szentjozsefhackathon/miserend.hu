<?php
/**
 * #706: a séma-referencia újragenerálása a FUTÓ adatbázis szerkezetéből.
 *
 * Akkor kell futtatni, ha a `docker/mysql/initdb.d` alatt változik a szerkezet.
 * Enélkül a `SchemaReferenceTest` elbukik — szándékosan, hogy a referencia ne
 * tudjon észrevétlenül elavulni.
 *
 * FONTOS: FRISS, `docker compose down -v && up`-pal újrainicializált adatbázison
 * futtasd. Ha a saját dev-adatbázisodon kézzel is ALTER-eztél, azok a kézi
 * változtatások is bekerülnének a referenciába.
 *
 *   docker exec miserend-miserend-1 php /miserend/webapp/tools/schema-reference.php
 */

require __DIR__ . '/../load.php';

$schema = $argv[1] ?? \Illuminate\Database\Capsule\Manager::connection()->getDatabaseName();

$generatedAt = date('c');
$structure = \SchemaCheck::readStructure($schema);

if (!$structure['tables']) {
    fwrite(STDERR, "Az adatbázis („$schema\") üres vagy nem olvasható — nem írok referenciát.\n");
    exit(1);
}

/*
 * #706: az ujjlenyomat is bekerül, hogy az elavulás futásidőben is kiderüljön —
 * ne csak a CI-ban. A /health ezt veti össze a ténylegesen telepített
 * sémafájlokkal, és ha eltér, megmondja, hogy a referencia elavult.
 */
$structure['_meta'] = [
    'generated_at'       => $generatedAt,
    'source_schema'      => $schema,
    'initdb_fingerprint' => \SchemaCheck::initdbFingerprint(),
    // Fájlonként is, hogy elavuláskor meg tudjuk mondani, MI változott.
    'initdb_files'       => \SchemaCheck::initdbFileHashes(),
];

$json = json_encode(
    $structure,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

file_put_contents(\SchemaCheck::referenceFile(), $json . "\n");

printf("Referencia frissítve: %s\n", \SchemaCheck::referenceFile());
printf("  forrás adatbázis: %s\n", $schema);
printf("  táblák: %d\n", count($structure['tables']));
printf("  initdb ujjlenyomat: %s (%d fájlból)\n",
    substr((string) $structure['_meta']['initdb_fingerprint'], 0, 16),
    count((array) $structure['_meta']['initdb_files']));

/*
 * A leggyakoribb hiba nem a felejtés, hanem a ELAVULT KONTÉNER: az ujjlenyomat abból
 * az initdb.d-ből készül, ami a futó image-ben van, nem abból, ami a munkapéldányban.
 * Ha az image régebbi, a referencia olyan ujjlenyomattal születik, ami sehol nem áll
 * elő újra — élesben pedig ettől mond a /health örökös „elavult"-at. (Pontosan ez
 * történt: #706.)
 */
printf("\nFIGYELEM: az ujjlenyomat a FUTÓ konténer sémafájljaiból készül.\n");
printf("Ha az image nem a mostani kódból épült, előbb építsd újra, különben a\n");
printf("referencia élesben „elavultnak\" fog látszani:\n");
printf("  docker compose -f docker/compose.yml -f docker/compose.dev.yml up -d --build\n");
