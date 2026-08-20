import {DateTimeUtil} from './date-time-util';

/**
 * #837 kliensoldali ellenőrzés: a `cal_generated_periods.end_date` KIZÁRÓ vég.
 *
 * A PHP a #837 óta `subDay()`-t alkalmaz (calmass.php:459 és :506). A kliensnek
 * ugyanezt kell tennie, különben a szerkesztő egy nappal többet mutat, mint az éles
 * oldal — pontosan az a fajta eltérés, amit tombi bejelentett a 2405-ös templomnál.
 */
describe('generált időszak záró napja (#837)', () => {

  it('a tárolt end_date NEM tartozik bele az időszakba', () => {
    // Szenteste: egyetlen nap, 12-24. A generátor `addDay()`-jel tárolja, tehát 12-25.
    const igazitott = DateTimeUtil.adjustEndDates([
      {periodId: 1, startDate: '2026-12-24', endDate: '2026-12-25'} as any,
    ]);

    expect(igazitott[0].endDate.slice(0, 10)).toBe('2026-12-24');
  });

  it('a hónap-forduló sem csúszik el', () => {
    // Május: 05-01 .. 05-31, tárolva 06-01-gyel.
    const igazitott = DateTimeUtil.adjustEndDates([
      {periodId: 2, startDate: '2026-05-01', endDate: '2026-06-01'} as any,
    ]);

    expect(igazitott[0].endDate.slice(0, 10)).toBe('2026-05-31');
  });
});
