import {LeafletLoaderService} from './leaflet-loader.service';

/**
 * #816: a Leafletet futásidőben töltjük be, nem a csomagba fordítva.
 *
 * A STÍLUSLAP itt a lényeg. A Leaflet a csempéket a `leaflet.css`-ből kapott
 * `position: absolute`-tal rakja a helyükre, a doboz épségét pedig az `overflow: hidden`
 * őrzi. Enélkül a csempék kiszöknek és szétszóródnak az űrlapon — borazslo pontosan
 * ezt kapta: „össze-vissza ugrál és elmászkál".
 *
 * A script és a stíluslap KÜLÖN-KÜLÖN is hiányozhat: az `editschedule` oldal egyiket
 * sem tölti be, a templomoldal viszont csak akkor, ha a templomnak van koordinátája.
 * Ezért a stíluslapot akkor is be kell szúrni, ha a `window.L` már megvan — és meg is
 * kell várni, mielőtt a térkép felépülne.
 */
describe('LeafletLoaderService (#816)', () => {

  let service: LeafletLoaderService;
  let eredetiL: any;

  const SCRIPT = 'script[src="/node_modules/leaflet/dist/leaflet.js"]';
  const STILUS = 'link[href="/node_modules/leaflet/dist/leaflet.css"]';

  function takarits(): void {
    document.querySelectorAll('script[src*="leaflet"], link[href*="leaflet"]')
      .forEach(el => el.remove());
  }

  beforeEach(() => {
    service = new LeafletLoaderService();
    eredetiL = (window as any).L;
    takarits();
  });

  afterEach(() => {
    if (eredetiL === undefined) {
      delete (window as any).L;
    } else {
      (window as any).L = eredetiL;
    }
    takarits();
  });

  /** A `load` esemény kézzel kiváltva — a valódi letöltést nem várjuk meg. */
  function stilustBetoltottnekJelol(): void {
    document.querySelector(STILUS)?.dispatchEvent(new Event('load'));
  }

  describe('a stíluslap', () => {

    it('akkor is bekerül, ha a Leaflet script már betöltött', async () => {
      (window as any).L = {marker: () => null};

      const igeret = service.load();
      expect(document.querySelector(STILUS))
        .withContext('a script megléte nem jelenti azt, hogy a stílus is ott van')
        .not.toBeNull();

      stilustBetoltottnekJelol();
      await igeret;
    });

    /** Meglévő Leafletnél nem szabad másodszor is behúzni a scriptet. */
    it('meglévő Leaflet mellett nem szúr be script-elemet', async () => {
      (window as any).L = {};

      const igeret = service.load();
      stilustBetoltottnekJelol();
      await igeret;

      expect(document.querySelectorAll(SCRIPT).length).toBe(0);
    });

    /**
     * Ez a hiba lényege: ha a `L`-t a stíluslap előtt adnánk vissza, a térkép
     * stílus nélküli DOM-ra épülne fel, és a csempék kiszöknének a dobozból.
     */
    it('a stíluslap betöltése előtt NEM adja vissza a Leafletet', async () => {
      (window as any).L = {};
      let megvan = false;

      service.load().then(() => megvan = true);
      await Promise.resolve();

      expect(megvan).withContext('a stílusra várni kell').toBeFalse();

      stilustBetoltottnekJelol();
      await Promise.resolve();
      await Promise.resolve();

      expect(megvan).toBeTrue();
    });

    /**
     * Ha a stíluslap nem jön meg, a térkép csúnya lesz — de a szerkesztő ne dőljön el
     * tőle. A dobozból kiszökés ellen a komponens saját CSS-e is véd.
     */
    it('a stíluslap hibája nem buktatja el a betöltést', async () => {
      (window as any).L = {};

      const igeret = service.load();
      document.querySelector(STILUS)?.dispatchEvent(new Event('error'));

      await expectAsync(igeret).toBeResolved();
    });

    it('nem szúr be másodszor is stíluslapot', async () => {
      (window as any).L = {};

      const igeret = service.load();
      stilustBetoltottnekJelol();
      await igeret;
      await service.load();

      expect(document.querySelectorAll(STILUS).length).toBe(1);
    });
  });

  describe('a script', () => {

    it('hiányzó Leafletnél betölti a scriptet és a stílust', () => {
      delete (window as any).L;

      service.load().catch(() => { /* a betöltést itt nem visszük végig */ });

      expect(document.querySelector(SCRIPT)).not.toBeNull();
      expect(document.querySelector(STILUS)).not.toBeNull();
    });

    /** Két párhuzamos hívó egyetlen betöltésen osztozik — nem két Leaflet-példányon. */
    it('párhuzamos kérésre is csak egyszer tölt be', () => {
      delete (window as any).L;

      service.load().catch(() => {});
      service.load().catch(() => {});

      expect(document.querySelectorAll(SCRIPT).length).toBe(1);
    });

    /**
     * Ha a script lefutott, de nincs `window.L`, a hívó egy használhatatlan objektumot
     * kapna. Inkább hibázzunk hangosan — a szerkesztő így a kézi mezőkre esik vissza.
     */
    it('elutasít, ha a script lefutott, de nincs window.L', async () => {
      delete (window as any).L;

      const igeret = service.load();
      stilustBetoltottnekJelol();
      document.querySelector(SCRIPT)!.dispatchEvent(new Event('load'));

      await expectAsync(igeret).toBeRejected();
    });
  });
});
