<?php

namespace Eloquent;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Email extends \Illuminate\Database\Eloquent\Model {

    public const SMTP_ACCEPTED = 'Az SMTP-kiszolgáló elfogadta a tesztlevelet. Ez nem igazolja a címzetti kézbesítést; ellenőrizd a levelet és a relay naplóját.';
    
    public $debug;
    public $debugger;
	
    
    
    function addToQueue($to = false) {
		if($to) $this->to = $to;
        $this->status = 'queued';
        return $this->save();
    }

    /**
     * #290: A queue-ba tett ('queued') emailek kiküldése. Eddig NEM volt queue-drainer
     * a rendszerben (mindenki send()-et hívott inline); a #290 az addToQueue()-t
     * használja, ezt a metódust pedig cron hívja batchenként korlátozva.
     */
    static function sendQueued($limit = 20) {
        self::requeueStuck();

        $queued = self::where('status', 'queued')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
        foreach ($queued as $email) {
            $email->send();
        }
        return true;
    }

    /**
     * A send() legelső dolga, hogy 'sending'-re állítja a sort, és csak utána próbálkozik
     * az SMTP-vel. Ha a folyamat közben hal meg (időkorlát, OOM, konténer-újraindulás),
     * a sor örökre 'sending'-ben marad: a sendQueued() csak a 'queued' státuszt szedi
     * elő, tehát az ilyen levél soha nem megy ki és soha nem is próbálkozik vele senki.
     * Élesen 89 ilyen sor gyűlt össze egyetlen hónap alatt.
     *
     * Itt visszatesszük őket a sorba. Aki viszont már régóta ezt csinálja, azt 'error'-ba
     * tesszük, hogy egy önmagában végzetes levél ne pörögjön a végtelenségig.
     *
     * @param  string $stuckAfter   ennyi idő után tekintjük beragadtnak (strtotime)
     * @param  string $giveUpAfter  ennyi idős sorral már nem próbálkozunk (strtotime)
     * @return array{requeued:int,failed:int}
     */
    static function requeueStuck($stuckAfter = '1 hour', $giveUpAfter = '3 days') {
        $stuckBefore = date('Y-m-d H:i:s', strtotime('-' . $stuckAfter));
        $giveUpBefore = date('Y-m-d H:i:s', strtotime('-' . $giveUpAfter));

        /*
         * #845: a feladás oka NEM a címzett.
         *
         * Ezek a sorok azért ragadtak be, mert a MI folyamatunk halt meg küldés közben
         * (időkorlát, OOM, konténer-újraindulás). Eddig ugyanabba az 'error' státuszba
         * kerültek, mint a valódi SMTP-visszautasítás — az `User::isUndeliverable()`
         * pedig ezeket is számolta. Következmény: egy háromnapos leállás után három
         * ilyen sor egy TÖKÉLETESEN MŰKÖDŐ címre is örökre elnémította az értesítőt.
         *
         * Külön státusz kell hozzá, nem külön mező: az `attemptedStatuses()`-nak
         * továbbra is bele kell számítania (megpróbáltuk, ne küldjük ki azonnal újra),
         * az `isUndeliverable()`-nek viszont nem (nem a cím hibája).
         */
        $failed = self::where('status', 'sending')
            ->where('updated_at', '<', $stuckBefore)
            ->where('created_at', '<', $giveUpBefore)
            ->update([
                'status' => self::STATUS_CRASHED,
                'error_reason' => 'A küldés közben megszakadt a folyamatunk; több próbálkozás után feladtam. Nem a címzett hibája.',
                'failed_at' => date('Y-m-d H:i:s'),
            ]);

        $requeued = self::where('status', 'sending')
            ->where('updated_at', '<', $stuckBefore)
            ->update(['status' => 'queued']);

        return ['requeued' => $requeued, 'failed' => $failed];
    }

    /**
     * Azok a státuszok, amik "már megpróbáltuk értesíteni" jelentésűek.
     *
     * A felhasználó-értesítő cronok eddig csak a 'queued' és 'sent' sorokat nézték, így
     * egy 'sending'-ben ragadt vagy 'error'-ra futott levél láthatatlan volt számukra —
     * a következő futás ezért újra kiküldte ugyanazt. Élesben ez adta a
     * 89 'sending' + 48 'error' + 47 'sent' arányt a user_pleaselogin típusnál.
     *
     * @return string[]
     */
    public static function attemptedStatuses(): array {
        return ['queued', 'sending', 'sent', 'error', self::STATUS_CRASHED];
    }

    /**
     * #845: a saját folyamatunk halt meg küldés közben — nem a címzett utasított vissza.
     *
     * „Megpróbáltuk" szempontból ugyanaz, mint az 'error' (l. `attemptedStatuses()`),
     * a cím megítélése szempontból viszont NEM az: az `User::isUndeliverable()` ezt nem
     * számolja bele, különben a mi leállásunk némítaná el a működő címeket.
     */
    const STATUS_CRASHED = 'crashed';

    /**
     * #845: azok a státuszok, amik a CÍMZETT oldali kudarcot jelentik.
     *
     * Csak ezeket szabad a kézbesíthetetlenség bizonyítékának tekinteni.
     *
     * @return string[]
     */
    public static function rejectedStatuses(): array {
        return ['error'];
    }
    
    function send($to = false) {
        if($to) $this->to = $to;

        $this->status = 'sending';
        $this->save();

        if ($this->debug == 1) {
            $this->header .= 'Bcc: ' . $this->debugger . "\r\n";
        } elseif ($this->debug == 2) {
            $this->body .= ".<br/>\n<i>Originally to: " . print_r($this->to, 1) . "</i>";
            $this->to = $this->debugger;
        }

        if (isset($this->subject) AND isset($this->body) AND isset($this->to)) {
            if ($this->debug == 3) {
                print_r($this);
                $this->status = 'debug';
                $this->save();
                return true;
            } else if ($this->debug == 5) {
                // black hole
                $this->status = 'blackhole';
                $this->save();
                return true;
            } else {
                // #610: konfigurálatlan SMTP-vel meg se próbálkozunk. Korábban ilyenkor a
                // beégetett 'mailcatcher' hostra ment minden levél, ami production-ben vagy
                // némán elnyelte a leveleket, vagy elszálló kivételt dobott a regisztráció
                // közepén (a sor pedig örökre 'sending' státuszban ragadt).
                if (!$this->isMailerConfigured()) {
                    return $this->fail('Nincs beállítva SMTP kiszolgáló (SMTP_HOST).');
                }

                try {
                    $mail = $this->createMailer();

                    //$mail->addAddress('joe@example.net', 'Joe User');     //Add a recipient
                    $mail->addAddress($this->to);               //Name is optional
                    //$mail->addReplyTo('info@example.com', 'Information');
                    //$mail->addCC('cc@example.com');
                    //$mail->addBCC('bcc@example.com');

                    //Attachments
                    //$mail->addAttachment('/var/tmp/file.tar.gz');         //Add attachments
                    //$mail->addAttachment('/tmp/image.jpg', 'new.jpg');    //Optional name

                    //Content
                    $mail->isHTML(true);                                  //Set email format to HTML
                    $mail->Subject = $this->subject;
                    $mail->Body    = $this->body;
                    //$mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

                    if (!$mail->send()) {
                        return $this->fail($mail->ErrorInfo);
                    }

                    $this->status = 'sent';
                    return $this->save();
                } catch (\Throwable $error) {
                    // A PHPMailer exception-módban fut (new PHPMailer(true)), a hívók
                    // viszont sehol nem kapják el: elszállt tőle az egész kérés.
                    return $this->fail($error->getMessage());
                }
            }
        }

        return $this->fail(
            'Kevés az adat (címzett, tárgy vagy törzs hiányzik).',
            'Nem tudtuk elküldeni az emailt. Kevés az adat.'
        );
    }

    /**
     * #610: egységes hibaág — a levél státusza NE ragadjon 'sending'-ben, és a valódi
     * SMTP-hibaüzenet kerüljön a szerver logjába (a felhasználónak csak jelzés megy).
     */
    protected function fail($reason, $userMessage = 'Valami hiba történt az email elküldése közben.') {
        $this->status = 'error';
        /*
         * #845: az OKOT is eltesszük, nem csak azt, hogy „hiba".
         *
         * Eddig kizárólag az error_log-ba ment, tehát az éles 117 hibás levélről semmit
         * nem tudtunk mondani — se azt, hogy elutasított cím, se azt, hogy a kiszolgáló
         * volt elérhetetlen. Márpedig a kettő ellentétes teendőt kíván.
         */
        $this->error_reason = mb_substr((string) $reason, 0, 1000);
        $this->failed_at = date('Y-m-d H:i:s');
        $this->save();

        // #845: `[miserend]` előtaggal, hogy a docs/logok.md-ben dokumentált
        // `docker logs | grep '[miserend]'` végre megtalálja. Ez volt az EGYETLEN
        // előtag nélküli hibanaplózás a classes/ alatt.
        error_log('[miserend] email hiba (id: ' . ($this->id ?: '-') . ', to: ' . $this->to . '): ' . $reason);
        addMessage($userMessage, 'danger');

        return false;
    }

    function isMailerConfigured() {
        global $config;

        return isset($config['smtp']['Host']) AND trim((string) $config['smtp']['Host']) !== '';
    }
    
    function __construct() {   
        global $config;
        
        $this->debug = $config['mail']['debug'];
        $this->debugger = $config['mail']['debugger'];
		
        $this->header = 'MIME-Version: 1.0' . "\r\n";
        $this->header .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
        $this->header .= 'From: ' . $config['mail']['sender'][0] . "\r\n";        
    }
    
    function render($twigfile, $array) {
        global $twig;

        if(is_object($array) AND method_exists($array, 'toArray')) 
            $array = $array->toArray();
        
        if(!$this->type)
            $this->type = $twigfile;
                
        $rendered = $twig->render('emails/' . strtolower($twigfile) . '.twig', (array) $array);        
                
        $lines=explode("\n", $rendered);
        if(!$this->subject)
            $this->subject = $lines[0];
        
        unset($lines[0]); unset($lines[1]);
        
        $this->body = implode("\n", $lines);
                
        return true;
    }
	
	function createMailer() {
		$mailer = new PHPMailer(true);
		
		//$mail->SMTPDebug = SMTP::DEBUG_SERVER;
		$mailer->CharSet = "UTF-8";
		$mailer->isSMTP();
		
		global $config;
		if( isset($config['smtp']) ) {
			foreach($config['smtp'] as $key => $value ) {
				$mailer->$key = $value;
			}
		}
		
		$mailer->setFrom($config['mail']['sender'][0],$config['mail']['sender'][1]);		
		
		return $mailer;
	}
	
	function test($content = false) {
        global $user, $config;

		// #610: a "nincs SMTP beállítva" eset korábban nem látszott, mert a config
		// beégetett 'mailcatcher' alapértéke mindig adott valamit.
		if(!$this->isMailerConfigured()) {
			return "Nincs beállítva SMTP kiszolgáló (SMTP_HOST).";
		}

		// A dev mailcatcher elnyeli a leveleket: production/staging alatt a "sikeres"
		// teszt ilyenkor is OK-ot mutatna, miközben senki nem kap levelet.
		if(in_array($config['env'], ['production','staging']) AND $config['smtp']['Host'] == 'mailcatcher') {
			return "A(z) ".$config['env']." környezet a dev mailcatcher-re küld: minden levél elveszik! Állítsd be az SMTP_HOST-ot.";
		}

		$mailer = $this->createMailer();
		try {
			$connection = $mailer->SmtpConnect();
		} catch(\Throwable $error) {
			return "PHPMailer Failed to connect : " . $error->getMessage();
		}

		$mailer->addAddress($this->debugger);
		$mailer->isHTML(true);
		$mailer->Subject = 'miserend.hu - egészség ellenőrzés';
		$mailer->Body    = '';
		if($content) {
            $mailer->Body .= "\n\n" . $content;
        }

		try {
			if(!$mailer->send()) {
				return "Valami hiba történt teszt email kiküldése közben: " . $mailer->ErrorInfo;
			}
		} catch(\Throwable $error) {
			return "Valami hiba történt teszt email kiküldése közben: " . $error->getMessage();
		}

		// A send() sikere csak azt jelenti, hogy a következő SMTP relay átvette a
		// levelet. A relay később még visszautasíthatja (például SPF/DKIM miatt), ezért
		// a health oldal ne állítsa, hogy a címzetti kézbesítés rendben van.
		return self::SMTP_ACCEPTED;

	}
    
}
