/*
 * #334: több nyelven bemutatott mise.
 *
 * Van szlovák-latin (3315, 3318), szlovák-magyar és német-magyar (2567) mise is, de a
 * `lang` oszlopba egyetlen kétbetűs kód fért. Vesszővel elválasztva több is elfér benne
 * ("sk,la"); a meglévő, egynyelvű sorok változatlanok maradnak.
 *
 * Miért nem külön kapcsolótábla: a `lang` értéket az Elasticsearch index, a sqlite
 * export és a naptár-alkalmazás is egyszerű mezőként olvassa. Vesszős listával ezek
 * mind működnek tovább, és az ES-be tömbként indexelve a nyelv-szűrő is helyesen
 * találja meg a többnyelvű miséket.
 *
 * 50 karakter bőven elég: ez ~16 nyelv egy misén.
 */

USE miserend;

ALTER TABLE `cal_masses`
    MODIFY COLUMN `lang` varchar(50) NOT NULL;
