<?php

namespace Html\Ajax\Calendar;

class CalendarApi extends \Html\Html {

    public $template = "layout_empty.twig";
    public $format = 'json';

    public function __construct($path) {
        // #391: nem tükrözzük vissza a kérést (l. Html\Ajax\Ajax). A naptár-végpontok
        // mind saját konstruktort hoznak, ez csak a „nincs ilyen végpont" eset.
        $this->content = json_encode(['error' => 'Nincs ilyen naptár végpont.']);
    }

    public function sendJsonError($message, $code): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => true,
            'message' => $message,
            'code' => $code,
        ]);
        exit;
    }
}