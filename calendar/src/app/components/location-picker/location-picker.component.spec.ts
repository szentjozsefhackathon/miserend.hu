import {ComponentFixture, TestBed} from '@angular/core/testing';
import {provideTranslateService} from '@ngx-translate/core';

import {LocationPickerComponent} from './location-picker.component';
import {LeafletLoaderService} from '../../services/leaflet-loader.service';
import {CSEMPE_URL} from '../../map-tiles';

/**
 * #816: helyszínválasztás térképen.
 *
 * A Leafletet kicseréljük egy kémre: valódi térképet böngészőteszt nem tud értelmesen
 * mérni (méret, csempeletöltés, animáció), a LOGIKA viszont pontosan az, ami elromolhat:
 *
 *   - a térkép magától SOHA ne állítson be helyszínt (a puszta megnyitás nem választás),
 *   - koordináta nélkül ne legyen jelölő, de a kivágat a TEMPLOM körül legyen,
 *   - ha a Leaflet nem tölthető be, a szerkesztő maradjon ép (kézi mezők).
 */
describe('LocationPickerComponent (#816)', () => {

  let fixture: ComponentFixture<LocationPickerComponent>;
  let component: LocationPickerComponent;
  let map: any;
  let marker: any;
  let L: any;
  let loader: jasmine.SpyObj<LeafletLoaderService>;

  /** A térkép eseménykezelőit elkapjuk, hogy kattintást tudjunk szimulálni. */
  let mapHandlers: Record<string, (e: any) => void>;
  let markerHandlers: Record<string, (e: any) => void>;

  beforeEach(async () => {
    mapHandlers = {};
    markerHandlers = {};

    // A valódi ResizeObserver csak elrendezés után szól; a teszt kézzel vált ki.
    (window as any).ResizeObserver = class {
      __hivd: () => void;
      constructor(cb: () => void) { this.__hivd = cb; }
      observe(): void { /* a méretet a teszt adja meg */ }
      disconnect(): void { /* nincs mit elengedni */ }
    };

    map = {
      setView: jasmine.createSpy('setView').and.callFake(() => map),
      on: jasmine.createSpy('on').and.callFake((n: string, h: any) => { mapHandlers[n] = h; }),
      removeLayer: jasmine.createSpy('removeLayer'),
      invalidateSize: jasmine.createSpy('invalidateSize'),
      remove: jasmine.createSpy('remove'),
    };
    marker = {
      addTo: jasmine.createSpy('addTo').and.callFake(() => marker),
      on: jasmine.createSpy('on').and.callFake((n: string, h: any) => { markerHandlers[n] = h; }),
      setLatLng: jasmine.createSpy('setLatLng'),
      getLatLng: jasmine.createSpy('getLatLng').and.returnValue({lat: 46.1, lng: 20.1}),
    };
    L = {
      map: jasmine.createSpy('map').and.returnValue(map),
      tileLayer: jasmine.createSpy('tileLayer').and.returnValue({addTo: () => null}),
      marker: jasmine.createSpy('marker').and.returnValue(marker),
    };

    loader = jasmine.createSpyObj('LeafletLoaderService', ['load']);
    loader.load.and.returnValue(Promise.resolve(L));

    await TestBed.configureTestingModule({
      imports: [LocationPickerComponent],
      providers: [
        provideTranslateService(),
        {provide: LeafletLoaderService, useValue: loader},
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(LocationPickerComponent);
    component = fixture.componentInstance;
  });

  /**
   * A `ngAfterViewInit` a betöltő ígéretére vár, a térkép pedig csak akkor épül fel,
   * amikor a doboznak VAN mérete. A ResizeObserver a jsdom-mentes böngészőtesztben is
   * él, de a méret-visszajelzés időzítése bizonytalan — ezért kézzel adjuk meg.
   */
  async function inditsd(szelesseg = 500, magassag = 260): Promise<void> {
    fixture.detectChanges();
    await loader.load();
    await Promise.resolve();
    meretetAd(szelesseg, magassag);
  }

  /** A doboz méretének megadása + a figyelő kézi meghívása. */
  function meretetAd(szelesseg: number, magassag: number): void {
    const elem = component.mapContainer!.nativeElement;
    Object.defineProperty(elem, 'offsetWidth', {value: szelesseg, configurable: true});
    Object.defineProperty(elem, 'offsetHeight', {value: magassag, configurable: true});
    (component as any).figyelo?.__hivd?.();
  }

  describe('kiindulópont', () => {

    it('helyszín nélkül a TEMPLOM körül nyílik', async () => {
      component.churchLat = 46.2;
      component.churchLon = 20.04;

      await inditsd();

      expect(map.setView).toHaveBeenCalledWith([46.2, 20.04], jasmine.any(Number));
    });

    it('meglévő helyszínnél oda nyílik, nem a templomra', async () => {
      component.churchLat = 46.2;
      component.churchLon = 20.04;
      component.lat = 46.18;
      component.lon = 20.03;

      await inditsd();

      expect(map.setView).toHaveBeenCalledWith([46.18, 20.03], jasmine.any(Number));
    });

    /** Koordináta nélküli templom is van (47 ilyen) — a térkép ne omoljon össze. */
    it('templom-koordináta nélkül sem hasal el', async () => {
      await inditsd();

      expect(map.setView).toHaveBeenCalled();
      expect(component.hiba).toBeNull();
    });
  });

  describe('jelölő', () => {

    /**
     * Ez a legfontosabb szabály: a megnyitás önmagában NEM választás. Ha a térkép
     * magától kitenné a jelölőt, a mise némán elmozdulna a templomtól.
     */
    it('helyszín nélkül nincs jelölő', async () => {
      component.churchLat = 46.2;
      component.churchLon = 20.04;

      await inditsd();

      expect(L.marker).not.toHaveBeenCalled();
    });

    it('helyszínnel jelölőt tesz ki, húzhatóan', async () => {
      component.lat = 46.18;
      component.lon = 20.03;

      await inditsd();

      expect(L.marker).toHaveBeenCalledWith([46.18, 20.03], {draggable: true});
    });
  });

  describe('választás', () => {

    it('kattintásra kiadja a pontot', async () => {
      await inditsd();
      const kapott: any[] = [];
      component.picked.subscribe(p => kapott.push(p));

      mapHandlers['click']({latlng: {lat: 46.123456789, lng: 20.987654321}});

      expect(kapott.length).toBe(1);
    });

    /**
     * Hat tizedes ~11 cm a felszínen — bőven a szabadtéri alkalom pontossága alatt.
     * A több jegy csak zajt vinne a mezőbe, és a kézzel beírttal sem lenne
     * összemérhető.
     */
    it('hat tizedesre kerekít', async () => {
      await inditsd();
      let kapott: any;
      component.picked.subscribe(p => kapott = p);

      mapHandlers['click']({latlng: {lat: 46.123456789, lng: 20.987654321}});

      expect(kapott.lat).toBe(46.123457);
      expect(kapott.lon).toBe(20.987654);
    });

    it('a jelölő elhúzása is választás', async () => {
      component.lat = 46.18;
      component.lon = 20.03;
      await inditsd();
      let kapott: any;
      component.picked.subscribe(p => kapott = p);

      markerHandlers['dragend']({});

      expect(kapott).toEqual({lat: 46.1, lon: 20.1});
    });
  });

  describe('ha nincs térkép', () => {

    /**
     * A térkép kiegészítés, nem feltétel: a koordináta kézzel is beírható. Ha a
     * betöltés elbukik, a szerkesztő többi részének épnek kell maradnia.
     */
    it('a betöltés hibáját elnyeli, és jelzi a felhasználónak', async () => {
      loader.load.and.returnValue(Promise.reject(new Error('nincs hálózat')));

      fixture.detectChanges();
      await loader.load().catch(() => null);
      await Promise.resolve();

      expect(component.hiba).toBe('MAP_UNAVAILABLE');
    });
  });

  describe('takarítás', () => {

    /** A Leaflet globális figyelőket akaszt az ablakra; bezáráskor el kell engedni. */
    it('bezáráskor elengedi a térképet', async () => {
      await inditsd();

      fixture.destroy();

      expect(map.remove).toHaveBeenCalled();
    });
  });

  describe('csukott panelben', () => {

    /**
     * A helyszín-blokk egy alapból CSUKOTT panelben ül: a doboz ilyenkor 0×0. Ha a
     * térképet ekkor építenénk meg, a Leaflet egyetlen csempényi területet kérne le,
     * és a kinyitás után is az maradna — borazslo pontosan ezt kapta: „egy-egy a
     * sarkában szinte találkozó négyzetnyi térképpel (többi fehér)".
     */
    it('nulla méretnél még nem épül fel a térkép', async () => {
      await inditsd(0, 0);

      expect(L.map).not.toHaveBeenCalled();
    });

    it('a panel kinyitásakor épül fel', async () => {
      await inditsd(0, 0);
      expect(L.map).not.toHaveBeenCalled();

      meretetAd(500, 260);

      expect(L.map).toHaveBeenCalledTimes(1);
    });

    /** További méretváltozásnál nem újraépítünk, hanem újramérünk. */
    it('újabb méretváltozásnál csak újramér', async () => {
      await inditsd(500, 260);

      meretetAd(700, 260);

      expect(L.map).toHaveBeenCalledTimes(1);
      expect(map.invalidateSize).toHaveBeenCalled();
    });
  });

  /**
   * #817: a csempeforrás.
   *
   * A kém eddig is megvolt, csak nem állítottunk rá semmit — ezért került be zöld futam
   * mellett a direkt OSM-forrás. Az OSM a blokkolt kérésre HTTP 200-at ad és egy valódi
   * PNG-t „Access blocked" felirattal, amit a Leaflet szabályos csempeként rak ki: ez
   * borazslo „foltos" térképe. Ilyen hibát csak forrás-szinten lehet megfogni.
   */
  describe('csempeforrás (#817)', () => {

    it('a kanonikus CARTO Voyager forrást használja', async () => {
      await inditsd();

      expect(L.tileLayer).toHaveBeenCalledWith(
        CSEMPE_URL,
        jasmine.objectContaining({subdomains: 'abcd', maxZoom: 19}),
      );
    });

    it('NEM a direkt OpenStreetMap-csempeszervert hívja', async () => {
      await inditsd();

      expect(L.tileLayer.calls.mostRecent().args[0]).not.toContain('tile.openstreetmap.org');
    });

    /** A #816 kérése: ugyanaz a licenc-feltüntetés, mint a site többi térképén. */
    it('mindkét kötelező attribúciót feltünteti', async () => {
      await inditsd();

      const attribution = L.tileLayer.calls.mostRecent().args[1].attribution;

      expect(attribution).toContain('openstreetmap.org/copyright');
      expect(attribution).toContain('carto.com/attributions');
    });
  });
});
