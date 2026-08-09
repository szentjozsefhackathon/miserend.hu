<?php

namespace Html\Ajax\Calendar;


header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

class caluser extends \Html\Ajax\Calendar\CalendarApi {

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
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'OPTIONS':
                http_response_code(200);
                exit;

            case 'GET':
                $this->getUser();
                break;

            default:
                $this->sendJsonError('Method not allowed', 405);
        }
    }

    private function getUser(): void {
        global $user;

        // Visszatérés a szükséges adatokkal
        $this->content = json_encode([
            'uid' => $user->uid,
            'username' => $user->username,
            'nickname' => $user->nickname,
            'name' => $user->name ?? null,
            'email' => $user->email ?? null,
        ]);
    }

}
