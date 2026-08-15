<?php
namespace Eloquent;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Capsule\Manager as DB;

class CalSuggestionPackage extends CalModel
{
    protected $table = 'cal_suggestion_packages';

    protected array $excludeFromArray = ['updated_at'];
    
    

    protected $fillable = [
        'church_id',
        'sender_name',
        'sender_email',
        'sender_user_id',
        'sender_message',
        'state',
    ];

    public function suggestions(): HasMany
    {
        return $this->hasMany(CalSuggestion::class, 'package_id');
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(\Eloquent\Church::class, 'church_id');
    }

    /**
     * Legacy user accessor: returns an instance of \User for sender_user_id if available.
     * Usage: $pkg->senderUser or in Twig: sgpack.senderUser
     * Note: \User is not an Eloquent model, it is the legacy user class in classes/user.php
     *
     * @return \User|null
     */
    public function getSenderUserAttribute()
    {
        if (isset($this->sender_user_id) && $this->sender_user_id) {
            return new \User($this->sender_user_id);
        }
        return null;
    }

    /**
     * Ki kezelte (fogadta el / utasította el) a javaslatot.
     *
     * Eddig CSAK az állapot tárolódott, a kezelő nem — az adminfelületen ezért nem
     * lehetett látni a nevét. Nem elveszett az adat, hanem sosem keletkezett.
     */
    protected $appends = ['handledByName'];

    public function getHandledByNameAttribute(): ?string
    {
        if (empty($this->handled_by_user_id)) {
            return null;
        }

        $kezelo = new \User($this->handled_by_user_id);

        return \Html\Ajax\Calendar\Suggestions::displayName($kezelo);
    }

    /**
     * #307: email-értesítés a beérkező javaslat-csomagról.
     *
     * Ugyanazt a 3 címzett-csoportot értesíti, mint a \Eloquent\Remark::emails():
     *   - miserend adminok (jogok LIKE '%miserend%')
     *   - egyházmegyei felelős (a templom egyházmegyéje alapján)
     *   - templom-feltöltésre jogosult felhasználók
     *
     * A `notifications=1` flag-et tiszteletben tartja minden felhasználónál.
     * Egy címet (e-mailt) csak egyszer értesít, akkor is ha több role-ben szerepel.
     */
    public function emails(): bool
    {
        $church = $this->church;
        if (!$church) {
            return false;
        }

        $emails = [];

        // Miserend admin-ok
        $admins = DB::table('user')
            ->where('jogok', 'LIKE', '%miserend%')
            ->where('notifications', 1)
            ->get();
        foreach ($admins as $admin) {
            $emails[$admin->email] = ['admin', $admin->email, $admin];
        }

        // Egyházmegyei felelős
        $responsabile = DB::table('egyhazmegye')
            ->select('user.*')
            ->where('egyhazmegye.id', $church->egyhazmegye)
            ->leftJoin('user', 'user.login', '=', 'egyhazmegye.felelos')
            ->where('notifications', 1)
            ->first();
        if ($responsabile && !empty($responsabile->email)) {
            $emails[$responsabile->email] = ['diocese', $responsabile->email, $responsabile];
        }

        // Templom felelősök
        $churchHolders = DB::table('church_holders')
            ->where('church_id', $church->id)
            ->where('church_holders.status', 'allowed')
            ->leftJoin('user', 'user.uid', '=', 'church_holders.user_id')
            ->where('user.notifications', 1)
            ->get();
        foreach ($churchHolders as $churchHolder) {
            if (!empty($churchHolder->email)) {
                $emails[$churchHolder->email] = ['responsible', $churchHolder->email, $churchHolder];
            }
        }

        foreach ($emails as $email) {
            $this->sendMail($email[0], $email[1], $email[2]);
        }

        return true;
    }

    /**
     * #307: egy konkrét címzettnek küldi el a 'suggestion_' + $type Twig-templatét.
     * A renderhez betöltjük a kapcsolódó suggestion-öket és a templomot,
     * hogy a template ezeket változókként érje el.
     */
    public function sendMail(string $type, string $to, $addressee = false): bool
    {
        if ($addressee) {
            $this->addressee = $addressee;
        } else {
            $this->addressee = false;
        }

        // A kapcsolódó adatokat eager-load-oljuk a render-hez
        $this->loadMissing('suggestions', 'church');

        $mail = new Email();
        $mail->render('suggestion_' . $type, $this);
        $mail->send($to);

        return true;
    }
}
