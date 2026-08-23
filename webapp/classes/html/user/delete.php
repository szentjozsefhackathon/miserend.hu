<?php

namespace Html\User;

class Delete extends \Html\Html {

    public $user2delete;
    public $isSelf = false;
    public $input = [];

    public function __construct() {
        global $user;

        $this->title = 'Felhasználó törlése';
        $this->template = 'user/delete.twig';
        $this->input['uid'] = \Request::IntegerRequired('uid');

        $this->user2delete = new \User($this->input['uid']);
        if ($this->user2delete->uid == 0) {
            addMessage("Nincs ilyen felhasználó!", 'danger');
            return;
        }

        // #110: eddig csak a `user` joggal rendelkező adminisztrátor törölhetett bárkit,
        // magát senki. Mostantól a saját fiókját mindenki törölheti.
        $this->isSelf = ($user->uid > 0 AND $user->uid == $this->user2delete->uid);
        if (!$user->checkRole('user') AND !$this->isSelf) {
            throw new \Exception("Hiányzó jogosultság miatt nem lehetséges a törlése!");
        }

        if ($this->isSelf) {
            $this->deleteSelf();
            return;
        }

        $this->input['confirmation'] = \Request::SimpleText('confirmation');
        if (!$this->input['confirmation']) {
            return;
        }

        // #873: a felhasználó törlése eddig egyetlen linken múlt
        // (`/user/{uid}/delete?confirmation=confirmed`) — vagyis egy beágyazott kép
        // a bejelentkezett adminunk nevében bárkit törölhetett.
        \Csrf::guard();

        $this->user2delete->delete();
        header("Location: /user/catalogue");
    }

    /**
     * A saját fiók törlése visszafordíthatatlan, ezért nem elég egy linkre kattintani:
     * POST kell hozzá, CSRF-token és a saját jelszó. A jelszó nem csak elgépelés ellen
     * véd — a tokentől függetlenül is bizonyítja, hogy tényleg a fiók gazdája kérte.
     * (#873 óta van CSRF-token mechanizmus; a jelszó marad, mert nem ugyanazt védi.)
     */
    private function deleteSelf() {
        global $user;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $password = \Request::get('password');
        if (!is_string($password) OR $password === '') {
            addMessage('A törléshez add meg a jelszavadat.', 'danger');
            return;
        }

        if (!$this->user2delete->verifyPassword($password)) {
            addMessage('Hibás jelszó — a fiókot nem töröltük.', 'danger');
            return;
        }

        $this->user2delete->delete();

        // A tokent is el kell dobni, különben törölt uid-dal maradnánk "bejelentkezve".
        \Token::delete();
        $user = new \User();

        addMessage('A fiókodat töröltük. Köszönjük, hogy velünk voltál!', 'success');
        header("Location: /");
    }

}
