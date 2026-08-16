<?php

namespace Html;

class Api extends Html {

    public function __construct() {
        ini_set('memory_limit', '256M');
        //printr($_SERVER['REQUEST_URI']);
        //printr($this);

        $uri = $_SERVER['REQUEST_URI'];

        /*
         * #56: az útvonal BÁRMILYEN verziószámot elfogad, a listát nem itt tartjuk.
         *
         * Korábban `v[1-4]` állt itt, három regexben — az v5 bevezetésekor mindhármat
         * módosítani kellett volna, és ha egy kimarad, a hívó néma átirányítást kap az
         * /apidocs-ra a "hibás verzió" üzenet helyett. Azt, hogy melyik verzió él, az
         * \Api\Api::validateVersionMain() mondja meg — egy helyen.
         */
        if (!preg_match('#^/api/(v[0-9]+)(/|$)#', $uri)) {
            $this->error = "Invalid API endpoint: " . $uri;
            \Message::add($this->error, 'danger');
            $this->redirect('/apidocs');
            exit;
        }

        // API v1 és v2 már nem elérhető
        if (preg_match('#^/api/(v1|v2)(/|$)#', $uri)) {
            echo 2;
            exit;
        }

        // Determine the action from the URL if not provided in the query string
        $endpoints = \Api\Api::collectApiEndpoints();                        
        if (preg_match('#^/api/(v[0-9]+)/?([^/?]*)#i', $uri, $matches)) {
            // #391: az API-osztályok a $_REQUEST['v']-ből olvassák a verziót, ezért azt
            // továbbra is beállítjuk — de amit MI használunk lentebb, azt lokális
            // változóból vesszük, ne a szuperglobális kerülőúton.
            $version = preg_replace('/\D/', '', $matches[1]);
            $_REQUEST['v'] = $version;

            // Simple uri - endpoint mapping
            $remaining = strtolower($matches[2]);
            foreach ($endpoints as $endpoint) {
                if (strtolower($endpoint) === $remaining) {

                    if($remaining == 'updated') {
                        if (preg_match('#/api/v(\d+)/updated/(\d{4}-\d{2}-\d{2})#', $_SERVER['REQUEST_URI'], $m)) {                         
                            $_REQUEST['date'] = $m[2];                            
                        }
                    }

                    if($remaining == 'sqlite') {
                        $this->redirect(DOMAIN . '/fajlok/sqlite/miserend_v' . $version . '.sqlite3');
                        exit;   
                    }

                    $this->api = new ('\\Api\\' . $endpoint)();                    
                    break;
                }
            }                    
        }

        if(!isset($this->api)) {
            \Message::add('Invalid API endpoint:'.$uri, 'danger');
            $this->redirect('/apidocs');
            exit;
        }
        

        try {
            $this->api->run();
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
        }
    }

    function render() {
        //Because of the Report::factoryCreate();

        if (!isset($this->api)) {
            $this->html = json_encode(['error' => 1, 'text' => isset($this->error) ? $this->error : 'Unknown error']);
            return;
        } elseif (isset($this->error)) {
            $this->api->return = ['error' => '1', 'text' => $this->error];
        }

        if (!$this->api->format)
            $this->api->format = 'text';
        $renderfunction = 'render' . ucfirst($this->api->format);
        if (method_exists($this->api, $renderfunction)) {
            $this->api->$renderfunction();
        } else {
            $this->$renderfunction();
        }
    }

    public function renderText() {
        if (is_array($this->api->return)) {
            $this->html = '';
            foreach ($this->api->return as $key => $value) {
                $this->html .= $key . ": \"" . $value . "\";\n";
            }
        } else {
            $this->html = $this->api->return;
        }
    }

    public function renderJson() {
        if (!isset($this->api->return['error'])) {
            $this->api->return['error'] = 0;
        }
        $this->html = json_encode($this->api->return);
    }

    public function renderCsv() {
        if (is_array($this->api->return)) {

            //TODO: a szöveg nem tartalmazhatja az elválasztó karaktert, különben gond van.
            $columnNames = array_keys($this->api->return[$this->api->tableName][0]);
            $this->html = implode($this->api->delimiter, $columnNames) . ";\n";
            foreach ($this->api->return[$this->api->tableName] as $row) {
                $this->html .= implode($this->api->delimiter, $row) . ";\n";
            }
        } else {
            $this->html = $this->api->return;
        }
    }

}
