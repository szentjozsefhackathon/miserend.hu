<?php

class Token {
       
    static function create($forUserId, $type) {
        global $config;
                
        if(isset($_COOKIE['token'])) {
                \Eloquent\Token::deleteByName($_COOKIE['token']);
        }

        $timeout = date('Y-m-d H:i:s', strtotime("+" . $config['token'][$type]));        
        $token = Eloquent\Token::create([
            'name' => md5(uniqid(mt_rand(), true)),
            'type' => $type,
            'uid' => $forUserId,
            'timeout' => $timeout
            ]);        
        $token->save();

        // #523: HTTPS-en Secure süti (Caddy reverse-proxy mögött X-Forwarded-Proto alapján is);
        // http://localhost dev-en marad false, hogy ne törje a belépést.
        setcookie('token', $token->name, strtotime($timeout),"/","", self::isHttps(), true);
        $_COOKIE['token'] = $token->name;

        return $token->name;
    }

   
    static function delete() {
        if(isset($_COOKIE['token'])) {
            \Eloquent\Token::deleteByName($_COOKIE['token']);
            setcookie('token', "", strtotime("-1 year"),"/","", self::isHttps(), true);
            unset($_COOKIE['token']);
        }        
    }
     
    // #523: a kérés HTTPS-e. Caddy/nginx reverse-proxy mögött a backend http-t lát,
    // ezért az X-Forwarded-Proto fejlécet is nézzük. http://localhost dev-en false marad.
    static function isHttps() {
        return (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') == '443');
    }

    static function cleanOut() {
        \Eloquent\Token::where('timeout','<',date('Y-m-d H:i:s'))->delete();
    }
        
    
}
