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
];

$json = json_encode(
    $structure,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

file_put_contents(\SchemaCheck::referenceFile(), $json . "\n");

printf("Referencia frissítve: %s\n", \SchemaCheck::referenceFile());
printf("  forrás adatbázis: %s\n", $schema);
printf("  táblák: %d\n", count($structure['tables']));
printf("  initdb ujjlenyomat: %s\n", substr((string) $structure['_meta']['initdb_fingerprint'], 0, 16));
