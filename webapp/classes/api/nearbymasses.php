<?php

namespace Api;

use Carbon\Carbon;

class NearbyMasses extends Api
{
    public $title = 'Közeli misék időtartományban';
    public $format = 'json';
    public $requiredVersion = ['>=', 4];

    public $fields = [
        'lat' => ['required' => true, 'validation' => ['float' => ['minimum' => -90, 'maximum' => 90]]],
        'lon' => ['required' => true, 'validation' => ['float' => ['minimum' => -180, 'maximum' => 180]]],
        'radius' => ['required' => true, 'validation' => ['float' => ['minimum' => 0.1, 'maximum' => 200]]],
        'from' => ['required' => true, 'validation' => 'string'],
        'until' => ['required' => true, 'validation' => 'string'],
        'limit' => ['validation' => ['integer' => ['minimum' => 1, 'maximum' => 100]], 'default' => 50],
    ];

    public function docs(): array
    {
        return [
            'description' => '<p>Adott pont körüli, sugáron és időtartományon belüli miséket adja vissza távolság szerint.</p>',
            'response' => '<p>A „misek” lista elemei tartalmazzák a mise, a templom és a kilométerben mért távolság adatait.</p>',
        ];
    }

    public function run(): void
    {
        parent::run();
        $this->getInputJson();

        $from = Carbon::parse($this->input['from']);
        $until = Carbon::parse($this->input['until']);
        if ($until->lessThan($from)) {
            throw new \Exception('Field until must not be earlier than from.');
        }

        $search = new \Search('masses');
        $search->timezone = $from->getTimezone()->getName();
        $search->timeRange($from->format('c'), $until->format('c'));
        $search->nearby((float) $this->input['lat'], (float) $this->input['lon'], (float) $this->input['radius']);
        $results = $search->getResults(0, (int) ($this->input['limit'] ?? 50));

        $masses = [];
        foreach ($results as $result) {
            $church = \Eloquent\Church::find($result->church_id);
            if (!$church) continue;
            $masses[] = [
                'id' => (int) $result->mass_id,
                'start_date' => $result->start_date,
                'title' => $result->title,
                'distance_km' => $result->distance_km,
                'church' => [
                    'id' => (int) $church->id,
                    'name' => $church->names[0] ?? '',
                    // #497: a település a boundary-ból, visszaeséssel a régi oszlopra.
                    'city' => $church->locationCityName(),
                    'lat' => (float) $church->lat,
                    'lon' => (float) $church->lon,
                ],
            ];
        }

        $this->return = ['error' => $search->searchFailed ? 1 : 0, 'sum' => $search->total, 'misek' => $masses];
        if ($search->searchFailed) $this->return['text'] = 'A keresőmotor nem érhető el.';
    }
}
