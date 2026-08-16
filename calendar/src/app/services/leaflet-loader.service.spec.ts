import {LeafletLoaderService} from './leaflet-loader.service';

/**
 * #816: a Leafletet futásidőben töltjük be, nem a csomagba fordítva.
 *
 * A templomoldal a helyszín-panelhez már betölti (#661/#653), tehát a `window.L`
 * jellemzően kész van, mire a szerkesztő megnyílik. Amit itt mérünk, az a két eset
 * különbsége: meglévő Leafletnél NE töltsünk be másodszor is (két példány két
 * verzióval pont az a rendetlenség, amit a #661 eltakarított), hiánynál pedig
 * pontosan egyszer töltsünk, akárhány hívó kéri.
 */
describe('LeafletLeaderService (#816)', () => {

  let service: LeafletLoaderService;
  let eredetiL: any;

  beforeEach(() => {
    service = new LeafletLoaderService();
    eredetiL = (window as any).L;
    // A korábbi tesztek beszúrt elemei ne szóljanak bele.
    document.querySelectorAll('script[src*="leaflet"], link[href*="leaflet"]')
      .forEach(el => el.remove());
  });

  afterEach(() => {
    if (eredetiL === undefined) {
      delete (window as any).L;
    } else {
      (window as any).L = eredetiL;
    }
    document.querySelectorAll('script[src*="leaflet"], link[href*="leaflet"]')
      .forEach(el => el.remove());
  });

  it('a már betöltött Leafletet adja vissza', async () => {
    const hamis = {marker: () => null};
    (window as any).L = hamis;

    await expectAsync(service.load()).toBeResolvedTo(hamis);
  });

  it('meglévő Leaflet mellett nem szúr be script-elemet', async () => {
    (window as any).L = {};

    await service.load();

    expect(document.querySelectorAll('script[src*="leaflet"]').length).toBe(0);
  });

  it('hiányzó Leafletnél betölti a scriptet és a stílust', () => {
    delete (window as any).L;

    service.load().catch(() => { /* a betöltést itt nem visszük végig */ });

    expect(document.querySelector('script[src="/node_modules/leaflet/dist/leaflet.js"]')).not.toBeNull();
    expect(document.querySelector('link[href="/node_modules/leaflet/dist/leaflet.css"]')).not.toBeNull();
  });

  /** Két párhuzamos hívó egyetlen betöltésen osztozik — nem két Leaflet-példányon. */
  it('párhuzamos kérésre is csak egyszer tölt be', () => {
    delete (window as any).L;

    service.load().catch(() => {});
    service.load().catch(() => {});

    expect(document.querySelectorAll('script[src="/node_modules/leaflet/dist/leaflet.js"]').length).toBe(1);
  });

  /**
   * Ha a script lefut, de nincs `window.L`, a hívó egy használhatatlan objektumot
   * kapna. Inkább hibázzunk hangosan — a szerkesztő így a kézi mezőkre esik vissza.
   */
  it('elutasít, ha a script lefutott, de nincs window.L', async () => {
    delete (window as any).L;

    const igeret = service.load();
    const script = document.querySelector('script[src="/node_modules/leaflet/dist/leaflet.js"]')!;
    script.dispatchEvent(new Event('load'));

    await expectAsync(igeret).toBeRejected();
  });
});
