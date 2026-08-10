import {DateTimeUtil} from './date-time-util';
import {Day} from '../enum/day';

/**
 * #374: DateTimeUtil tiszta dátum/idő-string építők lefedése. A getOnlyDateString/
 * getIsoString natív Date + padStart alapú, determinisztikus (nincs luxon/timezone-
 * függés); a getShortEnDay a hét napját képezi a Day enumra. Ezek hajtják az RRULE
 * dtstart-okat a naptárban — egy elrontott padding csendben rossz dátumot adna.
 */
describe('DateTimeUtil', () => {

  describe('getOnlyDateString', () => {
    it('egyjegyű hónapot/napot nullázva ad vissza', () => {
      expect(DateTimeUtil.getOnlyDateString(new Date(2026, 0, 5))).toBe('2026-01-05');
    });

    it('kétjegyű hónap/nap', () => {
      expect(DateTimeUtil.getOnlyDateString(new Date(2026, 11, 25))).toBe('2026-12-25');
    });

    it('vegyes (szeptember 9)', () => {
      expect(DateTimeUtil.getOnlyDateString(new Date(2026, 8, 9))).toBe('2026-09-09');
    });
  });

  describe('getIsoString', () => {
    it('dátum + nullázott idő', () => {
      expect(DateTimeUtil.getIsoString(new Date(2026, 2, 1, 7, 5, 9))).toBe('2026-03-01T07:05:09');
    });

    it('éjfél (00:00:00)', () => {
      expect(DateTimeUtil.getIsoString(new Date(2026, 2, 1, 0, 0, 0))).toBe('2026-03-01T00:00:00');
    });

    it('periodDate felülírja a dátumot, az idő a Date-ből jön', () => {
      expect(DateTimeUtil.getIsoString(new Date(2026, 2, 1, 7, 5, 9), '2026-06-15'))
        .toBe('2026-06-15T07:05:09');
    });
  });

  describe('getShortEnDay', () => {
    it('hétfő -> Day.MO', () => {
      // 2026-07-20 hétfő
      expect(DateTimeUtil.getShortEnDay(new Date(2026, 6, 20))).toBe(Day.MO);
    });

    it('vasárnap -> Day.SU', () => {
      // 2026-07-19 vasárnap
      expect(DateTimeUtil.getShortEnDay(new Date(2026, 6, 19))).toBe(Day.SU);
    });

    it('péntek -> Day.FR', () => {
      // 2026-07-17 péntek
      expect(DateTimeUtil.getShortEnDay(new Date(2026, 6, 17))).toBe(Day.FR);
    });
  });
});

/**
 * A `2026-01-01TNaN:NaN:NaN` kezdések forrása. A `pad(NaN)` a "NaN" sztringet adta, a
 * szerver ellenőrzés nélkül kiírta, a generátor pedig kihagyta az ilyen misét — így az
 * a szerkesztőben látszott, a keresőben soha. (Nagyatád, Őrangyalok-kápolna, 7 hónapig.)
 */
describe('DateTimeUtil érvénytelen időpont', () => {
  it('érvénytelen Date-re hibát dob, nem NaN-t formáz', () => {
    expect(() => DateTimeUtil.getIsoString(new Date('kacsa'))).toThrowError(/Érvénytelen időpont/);
  });

  it('a periódus dátumával együtt sem enged át NaN időt', () => {
    // Pontosan ez az eset gyártotta az élesen talált értéket: a dátum-rész helyes
    // maradt, csak az idő lett NaN — ezért nézett ki "majdnem jónak".
    expect(() => DateTimeUtil.getIsoString(new Date(NaN), '2026-01-01'))
      .toThrowError(/Érvénytelen időpont/);
  });

  it('érvényes időpontot változatlanul formáz', () => {
    expect(DateTimeUtil.getIsoString(new Date(2026, 0, 1, 10, 30, 0))).toBe('2026-01-01T10:30:00');
  });

  it('érvényes időpontot a periódus dátumával is formáz', () => {
    expect(DateTimeUtil.getIsoString(new Date(2026, 5, 5, 18, 0, 0), '2026-01-01'))
      .toBe('2026-01-01T18:00:00');
  });

  it('felismeri az érvénytelen dátumot', () => {
    expect(DateTimeUtil.isValidDate(new Date(2026, 0, 1))).toBeTrue();
    expect(DateTimeUtil.isValidDate(new Date('kacsa'))).toBeFalse();
    expect(DateTimeUtil.isValidDate(null)).toBeFalse();
    expect(DateTimeUtil.isValidDate(undefined)).toBeFalse();
    expect(DateTimeUtil.isValidDate('2026-01-01')).toBeFalse();
  });
});
