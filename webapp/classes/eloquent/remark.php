<?php

namespace Eloquent;

use Illuminate\Database\Capsule\Manager as DB;

class Remark extends \Illuminate\Database\Eloquent\Model {
    
    public $adminmegjlist = [];

    public function church() {
        return $this->belongsTo('\Eloquent\Church');
    }

    function scopeSelectCreatedMonth($query) {
        return $query->addSelect(DB::raw('DATE_FORMAT(created_at,\'%Y-%m\') as created_month'), DB::raw('COUNT(*) as count_created_month'));
    }

    function scopeSelectCreatedYear($query) {
        return $query->addSelect(DB::raw('DATE_FORMAT(created_at,\'%Y\') as created_year'), DB::raw('COUNT(*) as count_created_year'));
    }

    function scopeCountByCreatedMonth($query) {
        return $query->selectCreatedMonth()
                        ->groupBy('created_month')->orderBy('created_month');
    }

    function scopeCountByCreatedYear($query) {
        return $query->selectCreatedYear()
                        ->groupBy('created_year')->orderBy('created_year');
    }
   
    public function getChurchAttribute($value) {
        return \Eloquent\Church::find($this->church_id);
        
    }    

    /**
     * #755: mennyi ideig számít ismétlésnek a betűre azonos észrevétel.
     *
     * Bőven a „kétszer kattintottam" fölött: a /remark/add POST-ra nincs
     * átirányítás, tehát egy F5 percekkel később is újraküldi ugyanazt. Téves
     * elnyomásból nincs kár — a szöveg azonos, tehát nem vész el információ; egy
     * valóban új mondanivaló pedig már nem betűre ugyanaz.
     */
    public const DUPLICATE_WINDOW_SECONDS = 600;

    /**
     * #755: van-e friss, betűre azonos észrevétel ugyanattól, ugyanahhoz a templomhoz?
     *
     * Ugyanaz az észrevétel néha két-háromszor futott be, pár másodperc különbséggel.
     * Kiszolgáló oldalon szűrjük, mert az mindhárom okot lefedi: dupla kattintás,
     * újraküldés (F5), hálózati ismétlés. A hívó a meglévő sorral tér vissza, hogy a
     * beküldő ugyanazt a visszajelzést kapja — különben azt hinné, nem ment el, és
     * megint próbálkozna.
     *
     * @return static|null
     */
    public static function findRecentDuplicate($churchId, ?string $email, ?string $leiras)
    {
        if (empty($churchId) || empty($email) || empty($leiras)) {
            return null;
        }

        return static::where('church_id', $churchId)
            ->where('email', $email)
            ->where('leiras', $leiras)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - static::DUPLICATE_WINDOW_SECONDS))
            ->orderBy('created_at', 'desc')
            ->first();
    }
    
    /* 
     * custom functions 
     */
    public function appendComment($text) {
        global $user;
        if($text != '') {
            $newline = "\n<img src='/img/edit.gif' align='absmiddle' title='" . $user->username . " (" . date('Y-m-d H:i:s') . ")'>" . $text;
            $this->adminmegj .= $newline;
        }
    }
    
    function emails() {                
        /*
         * miserend adminiok
         * egyházmegyei felelős(ök)
         * templom feltöltésre jogosult felhasználó
         */
        $emails = [];        
        /* Miserend Adminok */
        $admins = DB::table('user')->where('jogok','LIKE','%miserend%')->where('notifications',1)->get();
        foreach($admins as $admin) {
           $emails[$admin->email] = ['admin',$admin->email,$admin];
        }              
        /* Egyházmegyei felelős (csak felhasználónév alapján) */
        $responsabile = DB::table('egyhazmegye')->select('user.*')->where('egyhazmegye.id',$this->church->egyhazmegye)->leftJoin('user','user.login','=','egyhazmegye.felelos')->where('notifications',1)->first();
        if($responsabile) {
            $emails[$responsabile->email] = ['diocese', $responsabile->email, $responsabile];
        }
        /* Templom felelősök — #819: a származtatott gondnokok is. Egy fília, aminek
           nincs saját gondnoka, eddig SENKIT nem értesített, pedig a plébánosa
           hivatalosan hozzáfér hozzá. */
        $churchHolders = $this->church->notifiableHolders();
        foreach($churchHolders as $churchHolder) {
            $emails[$churchHolder->email] = ['responsible', $churchHolder->email, $churchHolder];
        }
        
        foreach($emails as $email) {
            /*
             * #872: akinek napi/heti összefoglalót kért a beállítása, annak nem külön
             * levél megy, hanem egy sor a várólistára. Az azonnalit választóknál semmi
             * nem változik.
             */
            if (\DigestQueue::halaszt(
                    $email[2],
                    'remark',
                    (int) $this->church_id,
                    (string) $this->leiras,
                    '/templom/' . (int) $this->church_id . '/eszrevetelek')) {
                continue;
            }

            $this->sendMail($email[0], $email[1], $email[2]);            
        }
                
        return true;
    }

    function sendMail($type, $to, $addressee = false) {
        if($addressee) $this->addressee = $addressee;
        else  $this->addressee = false;
                      
        $this->append('church');
        
        $mail = new \Eloquent\Email();                
        $mail->render('remark_'.$type,$this);
        $mail->send($to);
    }
}
