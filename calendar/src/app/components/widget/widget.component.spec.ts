import {convertToParamMap} from '@angular/router';
import {of, throwError} from 'rxjs';

import {WidgetComponent} from './widget.component';
import {EventService} from '../../event.service';

/**
 * A widget az a nézet, amit IDEGEN oldalak ágyaznak be (`/templom/:id/widget`). Nincs
 * körülötte se menü, se hibalap: ha elszáll, a beágyazó oldalon egy üres doboz marad,
 * és a plébánia annyit lát, hogy „nem működik".
 *
 * Ezért a hangsúly a rossz bemeneten. A templom azonosítója az útvonalból jön, tehát
 * kívülről írható: hiányozhat, és lehet szemét is. Mindkettőre kilépéssel kell
 * válaszolni — és ami a legfontosabb, a töltésjelzőt MINDEN ágon le kell venni,
 * különben a widget örökre pörög.
 *
 * A komponenst közvetlenül példányosítjuk: a teljes logikája az `ngOnInit`-ben van, a
 * sablonja pedig a naptár-komponenst húzná be a maga teljes függőségi fájával.
 */
describe('WidgetComponent', () => {

  let eventService: jasmine.SpyObj<EventService>;

  beforeEach(() => {
    eventService = jasmine.createSpyObj('EventService', ['getChurch']);
  });

  /** @param id az útvonalban álló templom-azonosító (null = nincs megadva) */
  function komponens(id: string | null): WidgetComponent {
    const route = {snapshot: {paramMap: convertToParamMap(id === null ? {} : {id})}} as any;
    return new WidgetComponent(route, eventService);
  }

  describe('rossz bemenet', () => {

    it('azonosító nélkül hibát jelez és nem hív szervert', () => {
      const c = komponens(null);
      c.ngOnInit();

      expect(c.error).toBe('church_id missing');
      expect(eventService.getChurch).not.toHaveBeenCalled();
    });

    it('azonosító nélkül leveszi a töltésjelzőt', () => {
      const c = komponens(null);
      c.ngOnInit();

      expect(c.loading)
        .withContext('különben a beágyazott widget örökre pörög')
        .toBeFalse();
    });

    it('nem számot kapva hibát jelez és nem hív szervert', () => {
      const c = komponens('abc');
      c.ngOnInit();

      expect(c.error).toBe('invalid church_id');
      expect(c.loading).toBeFalse();
      expect(eventService.getChurch).not.toHaveBeenCalled();
    });

    /**
     * A `parseInt('12abc', 10)` 12-t ad — ez a JavaScript viselkedése, nem hiba.
     * Rögzítem, hogy tudatos maradjon: a widget ilyenkor a 12-es templomot mutatja.
     */
    it('a számmal kezdődő szemetet a szám-előtagként veszi', () => {
      eventService.getChurch.and.returnValue(of({id: 12, masses: []} as any));

      const c = komponens('12abc');
      c.ngOnInit();

      expect(eventService.getChurch).toHaveBeenCalledWith(12);
      expect(c.error).toBeNull();
    });
  });

  describe('betöltés', () => {

    it('az útvonalbeli azonosítóval kéri le a templomot', () => {
      eventService.getChurch.and.returnValue(of({id: 7, masses: []} as any));

      komponens('7').ngOnInit();

      expect(eventService.getChurch).toHaveBeenCalledWith(7);
    });

    it('sikeres betöltés után nincs hiba és nincs töltésjelző', () => {
      eventService.getChurch.and.returnValue(of({id: 7, masses: []} as any));

      const c = komponens('7');
      c.ngOnInit();

      expect(c.church).toBeDefined();
      expect(c.error).toBeNull();
      expect(c.loading).toBeFalse();
    });

    /** A naptár-komponens Map-et vár, a szerver tömböt ad — a widget fordít. */
    it('a mise-tömbből azonosító szerinti Map-et épít', () => {
      eventService.getChurch.and.returnValue(of({
        id: 7,
        masses: [{id: 101, title: 'Reggeli'}, {id: 202, title: 'Esti'}],
      } as any));

      const c = komponens('7');
      c.ngOnInit();

      expect(c.massesMap.size).toBe(2);
      expect(c.massesMap.get(101).title).toBe('Reggeli');
      expect(c.massesMap.get(202).title).toBe('Esti');
    });

    it('mise nélküli templomnál üres marad a Map', () => {
      eventService.getChurch.and.returnValue(of({id: 7, masses: []} as any));

      const c = komponens('7');
      c.ngOnInit();

      expect(c.massesMap.size).toBe(0);
    });

    /** Ha a szerver nem tömböt ad (régi API, hiányzó mező), nem szabad elszállni. */
    it('hiányzó mise-mezőnél sem dob', () => {
      eventService.getChurch.and.returnValue(of({id: 7} as any));

      const c = komponens('7');
      expect(() => c.ngOnInit()).not.toThrow();
      expect(c.massesMap.size).toBe(0);
    });
  });

  describe('szerverhiba', () => {

    it('magyar üzenetet mutat, nem a nyers hibát', () => {
      eventService.getChurch.and.returnValue(throwError(() => new Error('boom')));

      const c = komponens('7');
      c.ngOnInit();

      expect(c.error).toBe('Nem sikerült betölteni a templom adatait.');
    });

    it('hiba után is leveszi a töltésjelzőt', () => {
      eventService.getChurch.and.returnValue(throwError(() => new Error('boom')));

      const c = komponens('7');
      c.ngOnInit();

      expect(c.loading).toBeFalse();
      expect(c.church).toBeUndefined();
    });
  });
});
