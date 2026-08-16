import {ComponentFixture, TestBed} from '@angular/core/testing';
import {provideTranslateService} from '@ngx-translate/core';

import {LocationPickerComponent} from './location-picker.component';
import {LeafletLoaderService} from '../../services/leaflet-loader.service';

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

  /** A `ngAfterViewInit` a betöltő ígéretére vár — meg kell várni a felfutását. */
  async function inditsd(): Promise<void> {
    fixture.detectChanges();
    await loader.load();
    await Promise.resolve();
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
});
