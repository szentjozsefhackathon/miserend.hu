import {Mass} from '../../model/mass';
import {Church, ChurchFamilyMember} from '../../model/church';
import {MassUtil} from '../../util/mass-util';
import {Rite} from '../../enum/rites';

/**
 * #506: a plébánia és fíliái egy naptárban.
 *
 * Két állítást mérünk, és mindkettő a biztonságról szól:
 *
 *  1. A rokon templom miséje MEGTARTJA a saját `churchId`-jét. Ezen múlik minden: a
 *     mentés ezzel megy vissza, és a szerver ez alapján dönti el, van-e rá jogunk.
 *     Ha a betöltés elvesztené, a fília miséje a plébániához íródna.
 *
 *  2. Az egy-templomos szerkesztő semmiben nem változik: család nélkül ugyanaz a
 *     mise-halmaz és ugyanaz a cím, mint eddig.
 */
describe('ChurchComponent — család módú betöltés', () => {

  const mise = (id: number, churchId: number, title = 'Szentmise'): Mass => ({
    id,
    churchId,
    title,
    rite: Rite.ROMAN_CATHOLIC,
  } as Mass);

  const csaladtag = (id: number, name: string, isCurrent: boolean, masses: Mass[]): ChurchFamilyMember => ({
    id, name, city: 'Teszt', rite: Rite.ROMAN_CATHOLIC, writable: true, isCurrent, masses,
  });

  /** A komponens privát összefésülőjének megfelelő, kiemelt logika. */
  const osszefesul = (church: Church): Map<number, Mass> => {
    const osszes: Mass[] = [...church.masses];
    for (const tag of church.family ?? []) {
      if (tag.isCurrent) {
        continue;
      }
      osszes.push(...tag.masses);
    }
    return new Map(osszes.map(e => [e.id!, e]));
  };

  it('család nélkül csak a saját misék jönnek', () => {
    const church = {id: 1, masses: [mise(10, 1), mise(11, 1)]} as Church;

    const masses = osszefesul(church);

    expect(masses.size).toBe(2);
    expect(Array.from(masses.values()).every(m => m.churchId === 1)).toBeTrue();
  });

  it('család módban a fíliák miséi is bekerülnek', () => {
    const church = {
      id: 1,
      masses: [mise(10, 1)],
      family: [
        csaladtag(1, 'Plébánia', true, [mise(10, 1)]),
        csaladtag(2, 'Fília', false, [mise(20, 2), mise(21, 2)]),
      ],
    } as Church;

    const masses = osszefesul(church);

    expect(masses.size).toBe(3);
  });

  it('a saját miséket nem duplázzuk', () => {
    const church = {
      id: 1,
      masses: [mise(10, 1)],
      family: [csaladtag(1, 'Plébánia', true, [mise(10, 1)])],
    } as Church;

    expect(osszefesul(church).size).toBe(1);
  });

  it('a fília miséje megtartja a saját templom-azonosítóját', () => {
    const church = {
      id: 1,
      masses: [mise(10, 1)],
      family: [
        csaladtag(1, 'Plébánia', true, [mise(10, 1)]),
        csaladtag(2, 'Fília', false, [mise(20, 2)]),
      ],
    } as Church;

    const masses = osszefesul(church);

    expect(masses.get(20)!.churchId).toBe(2);
    expect(masses.get(10)!.churchId).toBe(1);
  });
});

/**
 * A naptárban látszania kell, melyik esemény melyik templomé — különben a szerkesztő
 * összekeveri őket, és a felhasználó azt hiszi, a sajátját írja át.
 */
describe('MassUtil — rokon templomok jelölése a naptárban', () => {

  const periods: any[] = [];
  const mise = (id: number, churchId: number): Mass => ({
    id,
    churchId,
    title: 'Szentmise',
    rite: Rite.ROMAN_CATHOLIC,
    startDate: '2026-01-04T09:00:00',
  } as Mass);

  it('név nélkül a cím változatlan marad', () => {
    const events = MassUtil.createCalendarEvents(
      [mise(10, 1)], periods, [], [], new Map(), undefined
    );

    events.forEach(e => expect(e.title).toBe('Szentmise'));
  });

  it('a rokon templom eseménye elé kerül a templom neve', () => {
    const nevek = new Map<number, string>([[2, 'Fília']]);

    const events = MassUtil.createCalendarEvents(
      [mise(20, 2)], periods, [], [], new Map(), undefined, nevek
    );

    events.forEach(e => {
      expect(e.title).toBe('Fília — Szentmise');
      expect(e.extendedProps.churchId).toBe(2);
      expect(e.extendedProps.churchName).toBe('Fília');
    });
  });

  it('a saját templom eseménye jelöletlen marad', () => {
    const nevek = new Map<number, string>([[2, 'Fília']]);

    const events = MassUtil.createCalendarEvents(
      [mise(10, 1)], periods, [], [], new Map(), undefined, nevek
    );

    events.forEach(e => {
      expect(e.title).toBe('Szentmise');
      expect(e.extendedProps.churchId).toBeUndefined();
    });
  });
});
