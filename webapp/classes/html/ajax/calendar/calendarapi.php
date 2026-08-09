<?php

namespace Html\Ajax\Calendar;

class CalendarApi extends \Html\Html {

    public $template = "layout_empty.twig";
    public $format = 'json';

    public function __construct($path) {
        $this->content = json_encode($_REQUEST);
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