<?php

namespace Api;

use Illuminate\Database\Capsule\Manager as DB;

class Churchrelationships extends Api {

    public $title = 'Egy misézőhely hierarchikus kapcsolatai';
    public $format = 'json';
    public $requiredVersion = ['>=', 4];

    public $fields = [
        'id' => [
            'required' => true,
            'validation' => 'integer',
            'description' => 'A misézőhely azonosítója.',
            'example' => 7
        ]
    ];

    public function docs() {
        $docs = [];
        $docs['description'] = '<p>Egy misézőhely hierarchikus kapcsolatait adja vissza (ősök és leszármazottak).</p>';
        $docs['response'] = '<ul>
            <li>"ancestors": a fölötte álló misézőhelyek fája</li>
            <li>"descendants": az alá tartozó misézőhelyek fája</li>
        </ul>';
        return $docs;
    }

    public function run() {
        parent::run();
        $this->getInputJson();

        $church = \Eloquent\Church::find($this->input['id']);

        if (!$church) {
            $this->return = [
                'error' => 1,
                'text'  => 'Nem létezik misézőhely ezzel az azonosítóval.'
            ];
            return;
        }

        $this->return = [
            'ancestors'   => $this->serializeTree($church->ancestors),
            'descendants' => $this->serializeTree($church->descendants),
        ];
    }

    /**
     * Rekurzívan szerializálja a fa-struktúrát JSON-ba.
     * Az Eloquent Church objektumokat egyszerű tömbökké alakítja.
     */
    private function serializeTree(array $nodes): array {
        $result = [];
        foreach ($nodes as $node) {
            $c = $node['church'];
            $result[] = [
                'church' => [
                    'id'   => $c->id,
                    'name' => !empty($c->names) ? $c->names[0] : $c->nev,
                    'city' => $c->varos,
                    'lat'  => (float) $c->lat,
                    'lon'  => (float) $c->lon,
                    'rank' => $c->rank,
                ],
                // #391/#663: a hierarchia-struktúra (`['church' => …, 'children' => …]`)
                // már NEM tartalmaz `type`-ot — a kapcsolat-típus fogalma kivezetés
                // alatt van. A mezőt a válaszban meghagyjuk (a kliensek szerződése ne
                // változzon váratlanul), de nem olvassuk ellenőrzés nélkül.
                'type'     => $node['type'] ?? null,
                'children' => $this->serializeTree($node['children']),
            ];
        }
        return $result;
    }
}
