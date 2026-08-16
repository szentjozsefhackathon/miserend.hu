import {MatDialogRef} from '@angular/material/dialog';

import {SensorEventPopupComponent, SensorEventDialogData} from './sensor-event-popup.component';

/**
 * Ez a felugró ablak mutatja meg, mit érzékelt a templomban a szenzor — például meddig
 * tartott a gyóntatás. A szerver csak KEZDETET és MÁSODPERCBEN mért hosszt küld, a
 * befejezést és az emberi formátumot ez a komponens számolja.
 *
 * Az időszámítás a lényeg: az órahatár átlépése, a nap átlépése, és a másodpercekből
 * összerakott óra:perc:mp. Ezeket a felületen csak akkor venné észre valaki, ha kiszúrja,
 * hogy egy 23:50-kor kezdődő, hosszú esemény vége „rossz napra" esik.
 *
 * A komponenst közvetlenül példányosítjuk — nincs benne más állapot, csak a kapott adat.
 */
describe('SensorEventPopupComponent', () => {

  let dialogRef: jasmine.SpyObj<MatDialogRef<SensorEventPopupComponent>>;

  beforeEach(() => {
    dialogRef = jasmine.createSpyObj('MatDialogRef', ['close']);
  });

  /**
   * @param startDate az esemény kezdete
   * @param duration hossz másodpercben
   * @param id a szenzor azonosítója (ebből derül ki a típusa)
   */
  function komponens(startDate: string, duration: number, id = 'confession_1'): SensorEventPopupComponent {
    const adat: SensorEventDialogData = {
      sensorEvent: {id, startDate, duration} as any,
      churchName: 'Teszt-templom',
    };
    return new SensorEventPopupComponent(dialogRef, adat);
  }

  describe('időszámítás', () => {

    it('a kezdetet dátum és idő alakban adja', () => {
      const {start} = komponens('2026-03-15T09:05:00', 0).getTimeRange();
      expect(start).toBe('2026.03.15 09:05:00');
    });

    it('a hosszt hozzáadja a kezdethez', () => {
      const {end} = komponens('2026-03-15T09:00:00', 30 * 60).getTimeRange();
      expect(end).toBe('2026.03.15 09:30:00');
    });

    it('átlépi az órahatárt', () => {
      const {end} = komponens('2026-03-15T09:45:00', 30 * 60).getTimeRange();
      expect(end).toBe('2026.03.15 10:15:00');
    });

    /** Éjfél átlépésekor a dátumnak is fordulnia kell. */
    it('átlépi a napot is', () => {
      const {end} = komponens('2026-03-15T23:50:00', 20 * 60).getTimeRange();
      expect(end)
        .withContext('éjfél után már a következő nap van')
        .toBe('2026.03.16 00:10:00');
    });

    it('a hosszt óra:perc:mp alakban írja ki', () => {
      const {duration} = komponens('2026-03-15T09:00:00', 3661).getTimeRange();
      expect(duration).toBe('01:01:01');
    });

    it('a rövid hosszt is két számjegyre tölti', () => {
      const {duration} = komponens('2026-03-15T09:00:00', 5).getTimeRange();
      expect(duration).toBe('00:00:05');
    });

    it('a több órás hosszt is kezeli', () => {
      const {duration} = komponens('2026-03-15T09:00:00', 10 * 3600 + 30 * 60).getTimeRange();
      expect(duration).toBe('10:30:00');
    });

    /** Hiányzó hossznál a vég a kezdet, nem "Invalid Date". */
    it('hiányzó hossznál a vég egyenlő a kezdettel', () => {
      const {start, end, duration} = komponens('2026-03-15T09:00:00', undefined as any).getTimeRange();

      expect(end).toBe(start);
      expect(duration).toBe('00:00:00');
    });
  });

  describe('információs hivatkozások', () => {

    it('gyóntatásnál a gyóntatás-specifikus linkeket adja', () => {
      const linkek = komponens('2026-03-15T09:00:00', 60, 'confession_1').getInfoUrls();

      expect(linkek.length).toBe(2);
      expect(linkek[0].url).toContain('/confession');
    });

    it('ismeretlen szenzortípusnál általános linket ad', () => {
      const linkek = komponens('2026-03-15T09:00:00', 60, 'valami_5').getInfoUrls();

      expect(linkek.length).toBe(1);
      expect(linkek[0].url).toContain('/sensor-info');
    });

    /** Azonosító nélkül sem maradhat link nélkül a felugró ablak. */
    it('azonosító nélkül is ad hivatkozást', () => {
      // Szándékosan NEM a segédfüggvényen át: ott az alapértelmezett paraméterérték
      // visszahozná a 'confession_1'-et, és a teszt sosem mérné az azonosító hiányát.
      const c = new SensorEventPopupComponent(dialogRef, {
        sensorEvent: {startDate: '2026-03-15T09:00:00', duration: 60} as any,
        churchName: 'Teszt-templom',
      });

      const linkek = c.getInfoUrls();
      expect(linkek.length).toBe(1);
      expect(linkek[0].url).toContain('/sensor-info');
    });

    it('minden hivatkozásnak van felirata és címe', () => {
      for (const link of komponens('2026-03-15T09:00:00', 60).getInfoUrls()) {
        expect(link.label.length).toBeGreaterThan(0);
        expect(link.url).toMatch(/^https:\/\//);
      }
    });
  });

  describe('kezelés', () => {

    it('a bezárás tényleg becsukja az ablakot', () => {
      komponens('2026-03-15T09:00:00', 60).onClose();
      expect(dialogRef.close).toHaveBeenCalled();
    });

    it('a hivatkozást új lapon nyitja', () => {
      spyOn(window, 'open');

      komponens('2026-03-15T09:00:00', 60).openLink('https://miserend.hu/confession');

      expect(window.open).toHaveBeenCalledWith('https://miserend.hu/confession', '_blank');
    });
  });
});
