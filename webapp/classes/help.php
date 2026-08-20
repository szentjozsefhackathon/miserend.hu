<?php

class Help {

    public $id;
    public $html;

    public function __construct($id) {
        switch ($id) {
            case 1:
                //miserend - templom adatlap
                $this->html = '<b>Megjegyzés</b> (a szerkesztéssel kapcsolatban)' .
                        '<br><br>Ide akkor kell beírni, ha a feltöltésnél van valami olyan körülmény, amiről fontos, hogy minden rögzítő, aki megnyithatja az űrlapot módosításra, tudjon.' .
                        '<br><br>Pl. "még ne engedélyezd, most egyeztetek a plébánossal" vagy bármi hasonló';
                break;

            case 4:
                //miserend - templom adatlap
                $this->html = '<b>Közismert neve</b>' .
                        '<br><br>Ide azt a nevet kell megadni, akár többet is, ahogy a helybeliek ismerik a templomot.  <br>Abban az esetben, ha olyan településen van a templom, ami időközben másik településbe olvadt, de azon belül még ismerik a régi nevet, azt is ide kell rögzíteni, pl. "izbégi templom", ami egyébként már Szentendréhez tartozik.';
                break;

            case 5:
                //miserend - templom adatlap
                $this->html = '<b>Templom címe</b>' .
                        '<br><br>Értelemszerűen a templom (és nem a plébánia!) elérhetőségeit kell megadni. A listákból a kezdőbetüket begépelve könnyen kereshető a település.' .
                        '<br><br>Megközelítésnek egy rövid leírást célszerű beírni (ha tudjuk), hogyan kell eljutni a templomhoz, amivel az odalátogatókat segítjük.';
                break;

            case 6:
                //miserend - templom adatlap
                $this->html = '<b>Plébánia adatai</b>' .
                        '<br><br>Itt a templomhoz kapcsolódó plébánia elérhetőségét kell megadni, ha pl. valami ügyintézés (esküvő, ilyesmi) miatt szeretne valaki érdeklődni, akkor hol teheti meg.' .
                        '<br><br>A plébánosok és minden alkalmazott névsora nem szükséges, de ha frissíthető, akkor persze néha jó tudni, hogy ki ott a plébános.';
                break;

            case 7:
                //miserend - templom adatlap
                $this->html = '<b>Ünnep adatok</b>' .
                        '<br><br>Ide alapvetően a búcsút és időpontját kell beírni (pl. a templom búcsúja augusztus 20.)' .
                        '<br><br>Előfordulhat több búcsú, vagy más rendszeres ünnep, esetleg ezen ünnepek nem csak a templomot, hanem környezetét is érinthetik, ilyenkor rövid leírást is be lehet írni.';
                break;


            case 9:
                //miserend - templom adatlap
                $this->html = '<b>Részletes leírás, templom története</b>' .
                        '<br><br>Ide a templom történeti leírása, összefoglalása kerülhet, illetve minden érdekesség a templommal kapcsolatban.<br/>'
                    . 'Html formázás lehetséges. Sortöréseket automatikusan '.htmlentities('<br/>').'-é alakítja';
                break;

            case 10:
                //miserend - templom adatlap
                $this->html = '<b>Megjegyzés</b>' .
                        '<br><br>Ide kerülhet minden olyan, ami a templommal összefügg, fontos információ, pl. búcsú időpontja, védőszent, plébániával kapcsolatos rövid (!) "reklámok".' .
                        '<br><br>Az ide írt szöveg a templom bemutatásánál jelenik meg "jó tudni..." című dobozban.' .
                        '<br><br>FONTOS! A rendszeres alkalmak infomrációi (pl. rózsafűzér, szentségimádás, hittan) NEM ide való, annak a miserend űrlap tetején van egy megjegyzés mező!';
                break;

            case 11:
                //miserend - templom adatlap
                $this->html = '<b>Képek</b>' .
                        '<br><br>Feltölthetők templomképek, egy templomhoz maximum 20 db. Fontos, hogy a fájlnevekben ne legyen ékezet vagy szóköz, valamint az azonos fájlnevű képek felülírják egymást, erre figyeljünk oda! Ilyenkor két azonos képet fogunk látni, méghozzá az utóbb feltöltöttet. Ez esetben töröljük mindkettőt és töltsük fel újra.' .
                        '<br><br>Lehetőség szerint CSAK jpg (esetleg gif) fájlokat töltsünk föl! A rendszer automatikusan kicsinyít, így a mérettel nem kell foglalkozni.' .
                        '<br><br>A feltöltött képek alatt jelölhető, hogy a kép a főoldalra (véletlenszerű válogatás) kikerüljön-e. Ha nincs kijelölve a négyzet, akkor nem teszi ki. Térképnél, kevésbé látványos templombelsőnél inkább ne jelöljük be!';
                break;

            case 14:
                //miserend - templom adatlap
                $this->html = '<b>Frissítés</b>' .
                        '<br><br>Minden templomhoz ki lesz írva, hogy mikoriak a információink, segítségképpen, hogy vajon mennyire lehet aktuális. Itt tehát akkor kell a dátumfrissítést bejelölni, ha az adatokat átnéztük és rendben találtuk. Ha csak egy helyesírási hibát javítottunk, vagy hasonló kis javítás történt, de az adatok valódiságáról nincs tudomásunk, akkor nem kell frissíteni.';
                break;

            case 15:
                //miserend - templom adatlap
                $this->html = '<b>Megjelenhet:</b>' .
                        '<br><br>Itt lehet beállítani, hogy a templom valamiért (hiányos adatok, tesztelés, stb.) nem jelenhet meg, vagy új feltöltés, módosítás miatt még várakozik.' .
                        '<br>Ha az adatok rendben vannak, akkor itt engedélyezhetjük.';
                break;

            case 16:
                //miserend - templom adatlap
                $this->html = '<b>Turistautak ID</b>' .
                        '<br><br>A turistautak.hu oldal gazdái gyűjtik a templomok (GPS) koordinátáit. Ebből térképen pontosan meghatározható a templom elhelyezkedése. Nekünk nem kell külön felmérnünk, hanem az elérhető <a href=http://turistautak.hu/search.php?s=templom target=_blank>listából</a> elég kikeresnünk a templomot (ha megvan) és ráklikkelve a címsorban megjelenő id utáni értéket kell ide beírnunk. ' .
                        /*
                         * #855: az URL-t NEM szabad HTML-taggel kettévágni.
                         *
                         * Itt korábban ez állt: `http://turistautak.hu/poi.php?<b>id=6515</b>`
                         * — vagyis a `<b>` a kérdőjel UTÁN nyílt. Aki ezt a szöveget
                         * automatikusan linkesíti (böngésző-kiegészítő, indexelő), a tagek
                         * kiszedése után egy `poi.php?id=6515` TÖREDÉKET kap, amit RELATÍV
                         * útvonalként a mi domainünkre kér le. borazslo pontosan ezt látta a
                         * statisztikában: „elég sok /poi.php?id=1234 formájú kérés is érkezik".
                         *
                         * Ezért az URL egyben marad, és rendes hivatkozás lesz belőle; a
                         * kiemelés a szám köré kerül, az URL-en kívülre.
                         */
                        '<br><br>Egy példa: a tardosi rk. templom linkje: ' .
                        '<a href="http://turistautak.hu/poi.php?id=6515" target="_blank" rel="noopener">http://turistautak.hu/poi.php?id=6515</a>' .
                        '<br>Az űrlapba beírandó szám tehát a <b>6515</b>';
                break;

            case 17:
                //user
                $this->html = '<b>Becenév, megszólítás:</b>' .
                        '<br><br>Portálunkon mindenki az itt megadott (bece)nevével szerepel, hangsúlyosan ez a név látható. Így nem okoz gondot, ha egy bejelentkezési név már foglalt, lehet másikat regisztrálni, s itt meg tudjuk adni kedvenc becenevünket, ahogy szeretnénk, hogy megszólítsanak. ' .
                        'Mivel így több azonos becenevű felhasználó is lehetséges, az azonosítás miatt (amennyiben a becenévtől különböző) valamilyen formában megjelenik a bejelentkezési név is.';
                break;

            case 18:
                //user
                $this->html = '<b>Bejelentkezési név</b>' .
                        '<br><br>Ezzel a névvel tudsz belépni, ez a név azonosít, így csak olyan nevet választhatsz, amit más még nem regisztrált. Előfordulhat, hogy a beírt név már foglalt, ez esetben másikat kell választani.' .
                        '<br><br>Szóköz, idézőjel és aposztróf nem lehet a bejelentkezési névben, de javasoljuk, hogy ékezetek és speciális karakterek se legyenek, a későbbi belépések során ezek gondot okozhatnak.';
                break;

            case 19:
                //user
                $this->html = '<b>Email cím</b>' .
                        '<br><br>Az itt megadott emailcímre küldjük ki a belépéshez szükséges jelszót, amit a későbbiekben a beállítások menüben tudsz majd megváltoztatni.' .
                        '<br>Továbbá ez az emailcím szolgál a kapcsolattartásra, valamint ezzel a címeddel tudsz feliratkozni különböző hírleveleinkre.' .
                        '<br><br>Emailcímed nyilvánosságáról külön dönthetsz.';
                break;

            case 20:
                //user
                $this->html = '<b>Név:</b>' .
                        '<br><br>Itt megadhatod teljes neved. A mező kitöltése nem kötelező, de akár a nyilvánosságot is állíthatod, így meghatározva, hogy mely kör az, akik láthatják megadott neved, s a többiek nem.';
                break;

            case 21:
                //user
                $this->html = '<b>Bemutatkozás</b>' .
                        '<br><br>Elsősorban a fórumon, de egyéb kapcsolatoknál is segítség lehet, ha pár szót írsz magadról. Nem célszerű önéletrajzot írni, de egy rövid bemutatkozás segíthet másokat, hogy megismerjenek.' .
                        '<br><br>A bemutatkozás nyilvánossága állítható, így szűrhető, hogy ne lássa mindenki.';
                break;

            case 22:
                //user
                $this->html = '<b>Elérhetőség</b>' .
                        '<br><br>Itt megadhatod mások számára elérhetőségeid. Ezt a mezőt célszerű óvatosan kezelni, személyes adatokat csak megfelelő kör számára szabad engedélyezni.' .
                        '<br><br>Itt különös szerepe van a nyilvánosság beállításának!';
                break;

            case 23:
                //user
                $this->html = '<b>Foglalkozás</b>' .
                        '<br><br>Itt megadhatod foglalkozásod, ami adatlapodon jelenik meg, illetve adott esetben kereshető is.' .
                        '<br><br>A nyilvánossága ennek a mezőnek is beállítható.';
                break;

            case 24:
                //user
                $this->html = '<b>Születésnap</b>' .
                        '<br><br>A születési dátumot megadva a beállított nyilvánosság figyelembevételével a rendszer automatikusan kezeli azt.' .
                        '<br><br><b>Névnap</b>' .
                        '<br><br>A születésnaphoz hasonlóan kezelődik.';
                break;

            case 25:
                //user
                $this->html = '<b>Családi állapot</b>' .
                        '<br><br>Itt állíthatod be családi állapotod, valamint meghatározható a nyilvánossága is.';
                break;

            case 26:
                //user
                $this->html = '<b>Lakhely</b>' .
                        '<br><br>Itt beállítható az ország és a település. Amennyiben a listában nem lenne a keresett ország, úgy válaszd az "egyéb" megjelölést.' .
                        '<br><br>A településnél figyelj a pontos beírásra, mindig a települést és ne a településen belüli résznevet (pl. valamilyen telep, stb.) írd! A beírt településnevet a rendszer használhatja, szolgáltatások kapcsolódhatnak hozzá, melyeket hibás beírás esetén nem tudsz használni.' .
                        '<br><br>Budapest esetében a kerületeket a következő formában kell mögé írni, pl.: <u>Budapest XVI. kerület</u>';
                break;

            case 27:
                //user
                $this->html = '<b>Vallás</b>' .
                        '<br><br>Amennyiben teheted, kérlek add meg vallásod, s ha nem szeretnéd, hogy mások lássák, akkor a nyilvánosságát tiltsd le. Ezt az adatot többek között statisztikához használjuk fel, de ha a nyilvánosságát engedélyezed, akkor adatlapodon is megjelenik.';
                break;

            case 28:
                //user
                $this->html = '<b>Internetes elérhetőség</b>' .
                        '<br><br>Itt a skype és messenger azonosítók adhatóak meg, hogy adott esetben online fel lehessen venni a kapcsolatot egymással.' .
                        '<br><br>A programok segítségével akár élő hanggal is lehet beszélgetni az interneten, így költségkímélő megoldás a telefonhoz képest.';
                break;

            case 29:
                //hírek admin - hírek űrlap
                $this->html = '<b>Megjegyzés</b> (a szerkesztéssel kapcsolatban)' .
                        '<br><br>Ide akkor kell beírni, ha a feltöltésnél van valami olyan körülmény, amiről fontos, hogy minden rögzítő, aki megnyithatja az űrlapot módosításra, tudjon.' .
                        '<br><br>Pl. "még ne engedélyezd, most egyeztetek" vagy bármi hasonló';
                break;

            case 41:
                //miserend - miseűrlap
                $this->html = '<b>Kiegészítő infók</b>' .
                        '<br><br>Ide jön minden olyan kapcsolódó információ, ami nem egy konkrét miséhez tartozik, hanem rendszeres esemény. Pl. a minden héten valamelyik este szentségimádás, vagy minden hónap első hétfője családos hittan, vagy bármi hasonló, ami fontos lehet, de nem a templomhoz tartozik (pl. védőszent, ünnep), hanem a miserendet kiegészítő rendszeres információ.';
                break;
         
            case 51:
                //templom űrlap - akadálymentesség
                $this->html = '<h4>Akadálymentesség</h4>
				<h5>Kerekesszékkel hozzáférhetőség:</h5>
				<ul>
					<li><strong>Teljesen</strong>: A bejáratnál és a helyiségekben sincsenek lépcsők.</li>
					<li><strong>Részben</strong>: A bejáratnak egy lépcsőfoka van, max. 7 cm magas, vagy van elérhető rámpa. A legtöbb ill. legfontosabb helyiségek lépcső nélküliek.</li>
					<li><strong>Egyáltalán nem</strong>: A bejáratnál egy vagy több lépcső van, a fontosabb helyiségek nem hozzáférhetőek.</li>						
				</ul>
				<h5>Kiegészítés, ha szükséges:</h5>
				Például: <i>Rámpa az oldalsó ajtónál érhető el.</i>
				<h5>Akadálymentes mosdó:</h5>
				Akadálymentes egy mosdó, ha az ajtó belső szélessége ≥ 90 cm, és az elérhető padlóterület ≥ 150 cm széles, és van kerekesszék magasságú WC ülőke, és vannak lehajtható kapaszkodók, és akadálymentesített a kézmosó.
				<h5>Hallást segítő indukciós hurok:</h5>
				<h5>További leírás bármilyen akadálymentesség kapcsán:</h5>
				Ide jöhet leírás arról, ha például minden szöveget kivetítenek a hallássérülteknek. Vagy van a padlót a látássérülteket segítő burkolat.';
				$this->html .= 'Az OSM alapú <a href="https://wheelmap.org/" target="_blank">wheelmap.org</a> kategóriáit alkalmazzuk. További információ az <a href="https://wiki.openstreetmap.org/wiki/How_to_map_for_the_needs_of_people_with_disabilities" target="_blank">OSM wiki</a> oldalán.';
                break;

            default:
                $this->html = 'Nincs ilyen segítség.';
                break;
        }
    }

}
