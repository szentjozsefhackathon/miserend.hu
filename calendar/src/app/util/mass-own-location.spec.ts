import {MassUtil} from './mass-util';
import {Mass} from '../model/mass';
import {DialogEvent} from '../model/dialog-event';
import {CalendarEvent} from '../model/calendar/calendar-event';
import {Church} from '../model/church';
import {Rite} from '../enum/rites';
import {Renum} from '../enum/recurrence';
import {Day} from '../enum/day';
import {LanguageCode} from '../enum/language-code';

/**
 * #431: az alkalom SAJÁT helyszíne.
 *
 * A használati eset: „Röszke plébánia biciklitúrát szervez időnként, és van mise valami
 * random pusztai helyen." A helyet az ALKALOMHOZ kötjük, nem új misézőhelyhez — így a
 * mise a szervező plébániáé marad, és nem keletkezik minden szabadtéri alkalomból egy
 * örökre ottmaradó pont a térképen.
 *
 * Amit itt mérünk, az a felület ígérete: a hívő MEGLÁSSA, hogy nem a templomban lesz.
 * Ha a jelzés elmarad, rossz helyre megy — ez a hiba drágább, mint bármi más ebben a
 * jegyben.
 */

function mise(overrides: Partial<Mass> = {}): Mass {
  return {id: 1, churchId: 999, title: 'Szentmise', ...overrides} as Mass;
}

describe('MassUtil.hasOwnLocation (#431)', () => {

  it('felismeri, ha az alkalomnak van saját koordinátája', () => {
    expect(MassUtil.hasOwnLocation(mise({locationLat: 46.2, locationLon: 20.05}))).toBeTrue();
  });

  it('koordináta nélkül a mise a templomban van', () => {
    expect(MassUtil.hasOwnLocation(mise())).toBeFalse();
  });

  /**
   * Fél koordináta nem helyszín: térképre sem lehet tenni. A féligkész adatból
   * kirajzolt jel rosszabb, mint a hiányzó — azt hinné a hívő, hogy tudjuk, hol lesz.
   */
  it('fél koordinátát nem fogad el', () => {
    expect(MassUtil.hasOwnLocation(mise({locationLat: 46.2}))).toBeFalse();
    expect(MassUtil.hasOwnLocation(mise({locationLon: 20.05}))).toBeFalse();
  });

  /** A 0.0 érvényes koordináta (Gulf of Guinea) — nem szabad hiányzónak venni. */
  it('a nulla koordinátát nem nézi hiányzónak', () => {
    expect(MassUtil.hasOwnLocation(mise({locationLat: 0, locationLon: 0}))).toBeTrue();
  });

  it('hiányzó misére sem esik el', () => {
    expect(MassUtil.hasOwnLocation(null)).toBeFalse();
    expect(MassUtil.hasOwnLocation(undefined)).toBeFalse();
  });
});

describe('MassUtil.locationLabel (#431)', () => {

  it('a megadott nevet adja vissza', () => {
    const cimke = MassUtil.locationLabel(mise({
      locationLat: 46.2, locationLon: 20.05, locationName: 'Röszkei puszta',
    }));

    expect(cimke).toBe('Röszkei puszta');
  });

  /** Név nélkül is meg kell tudni különböztetni két szabadtéri alkalmat. */
  it('név nélkül a koordinátát írja ki', () => {
    const cimke = MassUtil.locationLabel(mise({locationLat: 46.2, locationLon: 20.05}));

    expect(cimke).toBe('46.20000, 20.05000');
  });

  it('a csupa szóközből álló nevet üresnek veszi', () => {
    const cimke = MassUtil.locationLabel(mise({
      locationLat: 46.2, locationLon: 20.05, locationName: '   ',
    }));

    expect(cimke).toBe('46.20000, 20.05000');
  });
});

describe('MassUtil.locationOsmUrl (#431)', () => {

  /**
   * borazslo kérése: „Mondjuk link az openstreetmap-re adott koordinátákkal".
   * Az `mlat`/`mlon` teszi ki a JELÖLŐT — enélkül a link csak egy térképkivágás
   * lenne, ami pont a lényeget, a pontot hagyja el.
   */
  it('jelölőt is kitesz, nem csak odazoomol', () => {
    const url = MassUtil.locationOsmUrl(mise({locationLat: 46.2, locationLon: 20.05}));

    expect(url).toContain('mlat=46.200000');
    expect(url).toContain('mlon=20.050000');
    expect(url).toContain('#map=17/46.200000/20.050000');
  });

  it('openstreetmap.org-ra mutat, https-en', () => {
    const url = MassUtil.locationOsmUrl(mise({locationLat: 46.2, locationLon: 20.05}));

    expect(url.startsWith('https://www.openstreetmap.org/')).toBeTrue();
  });
});

// ---- a szerkesztő és a mentés közötti út -----------------------------------------

function makeChurch(): Church {
  return {id: 999, rite: Rite.ROMAN_CATHOLIC} as Church;
}

function makeCalendarEvent(): CalendarEvent {
  return {title: 'Szentmise', rrule: {dtstart: '2026-03-01T07:00:00', freq: 'weekly'}} as CalendarEvent;
}

function makeDialogEvent(overrides: Partial<DialogEvent> = {}): DialogEvent {
  return {
    period: null,
    rite: Rite.ROMAN_CATHOLIC,
    types: [],
    title: 'Szentmise',
    start: new Date('2026-03-01T07:00:00'),
    duration: {hours: 1},
    language: [LanguageCode.HU],
    renum: Renum.EVERY_WEEK,
    selectedDays: [Day.SU],
    comment: '',
    editOne: false,
    ...overrides,
  };
}

describe('MassUtil.createMass — saját helyszín (#431)', () => {

  it('átviszi a helyszínt a mentendő misére', () => {
    const dialogEvent = makeDialogEvent({
      locationLat: 46.2, locationLon: 20.05, locationName: 'Röszkei puszta',
    });

    const mass = MassUtil.createMass(makeCalendarEvent(), dialogEvent, makeChurch(), 1);

    expect(mass.locationLat).toBe(46.2);
    expect(mass.locationLon).toBe(20.05);
    expect(mass.locationName).toBe('Röszkei puszta');
  });

  it('helyszín nélkül nem tesz be helyszín-mezőt', () => {
    const mass = MassUtil.createMass(makeCalendarEvent(), makeDialogEvent(), makeChurch(), 1);

    expect(mass.locationLat).toBeUndefined();
    expect(mass.locationLon).toBeUndefined();
  });

  /**
   * Ez a lényegi szabály: fél koordinátával a mise a TEMPLOMBAN marad. Enélkül egy
   * félbehagyott szerkesztés némán elmozdítaná a misét a Gulf of Guinea felé.
   */
  it('fél koordinátát nem küld el', () => {
    const mass = MassUtil.createMass(
      makeCalendarEvent(), makeDialogEvent({locationLat: 46.2}), makeChurch(), 1);

    expect(mass.locationLat).toBeUndefined();
    expect(mass.locationLon).toBeUndefined();
  });

  it('név nélküli helyszínt is elfogad', () => {
    const mass = MassUtil.createMass(
      makeCalendarEvent(), makeDialogEvent({locationLat: 46.2, locationLon: 20.05}), makeChurch(), 1);

    expect(mass.locationLat).toBe(46.2);
    expect(mass.locationName).toBeNull();
  });
});
