<?php

namespace Html\Ajax\Calendar;

use ExternalApi\NapilelkibatyuApi;

class Liturgicaldays extends \Html\Ajax\Calendar\CalendarApi {

    public function __construct() {
        try {
            // Get the date range parameters from the request
            $from = \Request::TextRequired('from');
            $until = \Request::TextRequired('until');

            // Validate ISO 8601 format (basic validation)
            if (!$this->isValidIso8601($from) || !$this->isValidIso8601($until)) {
                throw new \Exception("Invalid date format. Expected ISO 8601 format (e.g., 2026-05-17T00:00:00)");
            }

            // Initialize the NapilelkibatyuApi and fetch liturgical days
            $api = new NapilelkibatyuApi();
            $liturgicalDays = $api->getLiturgicalDaysInRange($from, $until);

            // Format the response: convert to the expected structure
            $result = [];
            foreach ($liturgicalDays as $date => $dayInfo) {
                $result[$date] = [
                    'name' => $dayInfo['name'] ?? '',
                    'short_name' => $this->getShortName($dayInfo['name'] ?? ''),
                    'level' => $dayInfo['level'] ?? 4,
                    'isSunday' => $dayInfo['isSunday'] ?? false
                ];
            }

            // Output as JSON
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result);

        } catch (\Exception $e) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode([
                'error' => true,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Validate ISO 8601 datetime format
     */
    private function isValidIso8601($dateString) {
        // Basic validation for ISO 8601 format
        $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/';
        return preg_match($pattern, $dateString) === 1;
    }

    /**
     * Generate a short name from the full liturgical day name
     * e.g., "Pünkösd" -> "Pünk", "Húsvét vasárnapja" -> "Húsv."
     */
    private function getShortName($fullName) {
        if (empty($fullName)) {
            return '';
        }

        // Hungarian liturgical day name shortening
        $shortNames = [
            'Pünkösd' => 'Pünk',
            'Húsvét' => 'Húsv',
            'Karácsony' => 'Kar',
            'Vízkereszt' => 'Víz',
            'Nagycsütörtök' => 'NCsü',
            'Nagypéntek' => 'NPén',
            'Nagyszombat' => 'NSzo',
            'Nagyvasárnap' => 'NV',
        ];

        // Check for exact matches
        foreach ($shortNames as $full => $short) {
            if (stripos($fullName, $full) !== false) {
                return $short;
            }
        }

        // Default: take first 4 characters and add a dot.
        // #374: mb_substr, nem substr — a byte-alapú substr félbevághat egy többbájtos
        // UTF-8 karaktert (pl. 'Á'), amitől érvénytelen UTF-8 keletkezik, a json_encode
        // FALSE-t ad, és az EGÉSZ liturgikus-nap endpoint üres választ ad vissza.
        return mb_substr($fullName, 0, 4, 'UTF-8') . '.';
    }
}
