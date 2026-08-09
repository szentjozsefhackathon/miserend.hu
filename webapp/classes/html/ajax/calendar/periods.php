<?php

namespace Html\Ajax\Calendar;

use Carbon\Carbon;

use Eloquent\CalPeriod;
use Eloquent\CalPeriodYear;
use Eloquent\CalGeneratedPeriod;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

class Periods extends \Html\Ajax\Calendar\CalendarApi {

    private array $years;

    public function __construct($path) {
        // #392: váratlan kivétel -> tiszta JSON hiba (nem HTML).
        try {
            $this->handle($path);
        } catch (\Throwable $e) {
            error_log('[calendar] ' . static::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->sendJsonError('Váratlan hiba a naptár-műveletben.', 500);
        }
    }

    private function handle($path) {
        global $user;

        $this->years = [
            Carbon::now()->year - 1,
            Carbon::now()->year,
            Carbon::now()->year + 1
        ];

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'OPTIONS':
                http_response_code(200);
                exit();
            case 'GET':                
                //ha szerkeszteni is akarjuk, lekérjük az időszak éveket +- 1 évre
                if (!empty($path[0]) && $path[0] === 'edit') {

                    if (!$user->checkRole('miserend')) {
                        $this->sendJsonError('Nincs jogosultságod megnézni a periódusok listáját.', 405);
                        exit();
                    }

                    $existing = CalPeriodYear::whereIn('start_year', $this->years)
                        ->get()
                        ->map(fn($py) => $py->period_id . '-' . $py->start_year)
                        ->toArray();

                    // 2. Lekérjük azokat a periodusokat, amik nem kapcsolódnak másikhoz, és nem fixek minden évben
                    $independentPeriods = CalPeriod::whereNull('start_period_id')
                        ->whereNull('end_period_id')
                        ->whereNull('start_month_day')
                        ->whereNull('end_month_day')
                        ->get();

                    $now = Carbon::now();
                    $toInsert = [];

                    foreach ($this->years as $year) {
                        foreach ($independentPeriods as $period) {
                            $key = $period->id . '-' . $year;
                            if (!in_array($key, $existing)) {
                                $toInsert[] = [
                                    'period_id' => $period->id,
                                    'start_year' => $year,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ];
                            }
                        }
                    }

                    if (!empty($toInsert)) {
                        CalPeriodYear::insert($toInsert);
                    }

                    $periodsYear = CalPeriodYear::whereIn('start_year', $this->years)->get();

                    $this->content = json_encode($periodsYear->toArray());
                } else {
                    //ha csak simán lekérjük a periódusokat
                    
                    $periods = CalPeriod::orderBy('weight', 'asc')->get();
                    $generatedPeriods = CalGeneratedPeriod::all();

                    $result = [
                        'periods' => $periods->toArray(),
                        'generatedPeriods' => $generatedPeriods->toArray()
                    ];

                    $this->content = json_encode($result);
                }
                break;
            case 'POST':
                if (!$user->checkRole('miserend')) {
                    $this->sendJsonError('Nincs jogosultságod megnézni a periódusok listáját.', 405);
                    exit();
                }

                if (!empty($path[0]) && $path[0] === 'generate') {
                    //legeneráljuk az időszakokat
                    $this->generatePeriods();
                } else {
                    //időszak éveket mentünk
                    $this->savePeriodYears();
                }

                break;

            default:
                $this->sendJsonError('Method not allowed', 405);
                exit();
        }
    }

    private function generatePeriods(): void {
        foreach ($this->years as $year) {
            try {
                CalPeriod::generateCalGeneratedPeriods($year);
            } catch (\Exception $e) {
                $this->sendJsonError("$year: " . $e->getMessage(), 422);
            }
        }

        $this->content = json_encode([
            'message' => "Sikeresen generált évek: " . implode(", ", $this->years)
        ]);
    }

    private function savePeriodYears(): void {
        $input = json_decode(file_get_contents('php://input'), true);

        if ($input === null) {
            $this->sendJsonError('Invalid JSON data received.', 400);
        }

        $now = Carbon::now();
        $upsert = [];

        foreach ($input as $index => $item) {
            if (!isset($item['periodId'], $item['startYear'])) {
                $this->sendJsonError("Missing required fields at index $index", 422);
            }

            // Ha minden rendben van, összegyűjtjük a beszúrandó adatokat
            $upsert[] = [
                'id' => $item['id'],
                'period_id' => $item['periodId'],
                'start_year' => $item['startYear'],
                'start_date' => $item['startDate'] ?? null,
                'end_date' => $item['endDate'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($upsert)) {
            CalPeriodYear::upsert($upsert, ['id'],
                ['period_id', 'start_year', 'start_date', 'end_date', 'created_at', 'updated_at']);
        }

        $this->content = json_encode([
            'message' => 'Sikeres mentés! Ne felejts el generálni se! :)',
            'count' => count($upsert),
        ]);
    }

}
