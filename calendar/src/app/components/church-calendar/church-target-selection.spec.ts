import {Church, ChurchFamilyMember} from '../../model/church';
import {Mass} from '../../model/mass';
import {Rite} from '../../enum/rites';
import {MassUtil} from '../../util/mass-util';

/**
 * #506 „B": az ÚJ esemény ahhoz a templomhoz kerüljön, amelyiket a felhasználó
 * választotta — és a saját rítusával.
 *
 * A választó csak akkor jelenhet meg, ha tényleg van miből választani: a plébániának
 * vannak fíliái, és többhöz is van írásjogunk. Egy templomnál felesleges zaj, két
 * kattintással több munka.
 *
 * A rítus azért fontos, mert templomonként más lehet (görögkatolikus fília római
 * plébánia alatt), és az új mise alapértelmezett címét és rítusát ez adja. Ha a
 * választott templom helyett a megnyitottét használnánk, a fília miséje csendben rossz
 * rítust kapna.
 *
 * A komponens megfelelő metódusai privátak/beágyazottak, ezért itt a logikájukat
 * emeljük ki — a lényeg a szabály rögzítése.
 */
describe('#506 — új esemény céltemploma', () => {

  const tag = (id: number, name: string, rite: Rite, writable: boolean, isCurrent = false): ChurchFamilyMember =>
    ({id, name, city: 'Teszt', rite, writable, isCurrent, masses: []});

  const plebania = {id: 1, name: 'Plébánia', rite: Rite.ROMAN_CATHOLIC} as Church;

  /** A komponens `targetChurch()`-ének megfelelő szabály. */
  const celTemplom = (family: ChurchFamilyMember[], current: Church, churchId?: number): Church => {
    const irhato = family.filter(t => t.writable);
    if (!churchId || churchId === current.id) {
      return current;
    }
    const valasztott = irhato.find(t => t.id === churchId);
    if (!valasztott) {
      return current;
    }
    return {...current, id: valasztott.id, name: valasztott.name, rite: valasztott.rite};
  };

  it('választás nélkül a megnyitott templom marad', () => {
    const cel = celTemplom([], plebania, undefined);

    expect(cel.id).toBe(1);
    expect(cel.rite).toBe(Rite.ROMAN_CATHOLIC);
  });

  it('a választott fília azonosítóját és rítusát kapja az esemény', () => {
    const family = [
      tag(1, 'Plébánia', Rite.ROMAN_CATHOLIC, true, true),
      tag(2, 'Görög fília', Rite.GREEK_CATHOLIC, true),
    ];

    const cel = celTemplom(family, plebania, 2);

    expect(cel.id).toBe(2);
    expect(cel.name).toBe('Görög fília');
    expect(cel.rite).toBe(Rite.GREEK_CATHOLIC);
  });

  /**
   * Ez a védelem: ha valahogy mégis nem írható templom azonosítója érkezik, NEM oda
   * írunk. A szerver úgyis visszautasítaná, de a felület se csináljon rosszat.
   */
  it('nem írható templomra nem esik a választás', () => {
    const family = [
      tag(1, 'Plébánia', Rite.ROMAN_CATHOLIC, true, true),
      tag(3, 'Idegen', Rite.ROMAN_CATHOLIC, false),
    ];

    const cel = celTemplom(family, plebania, 3);

    expect(cel.id).toBe(1);
  });

  it('ismeretlen azonosítónál a megnyitott templom marad', () => {
    const family = [tag(1, 'Plébánia', Rite.ROMAN_CATHOLIC, true, true)];

    expect(celTemplom(family, plebania, 999).id).toBe(1);
  });

  it('a létrehozott mise a céltemplom azonosítóját viseli', () => {
    const family = [
      tag(1, 'Plébánia', Rite.ROMAN_CATHOLIC, true, true),
      tag(2, 'Fília', Rite.ROMAN_CATHOLIC, true),
    ];
    const cel = celTemplom(family, plebania, 2);

    const mise: Mass = MassUtil.createSimpleMassByDate(
      new Date('2026-08-16T09:00:00'), cel, -1,
      {instant: (kulcs: string) => kulcs} as any
    );

    expect(mise.churchId).toBe(2);
  });
});

/**
 * A választó megjelenésének szabálya: csak akkor, ha egynél több írható templom van.
 */
describe('#506 — mikor jelenjen meg a templomválasztó', () => {

  const tag = (id: number, writable: boolean): ChurchFamilyMember =>
    ({id, name: 'T' + id, city: '', rite: Rite.ROMAN_CATHOLIC, writable, isCurrent: false, masses: []});

  const vanValasztas = (family: ChurchFamilyMember[]): boolean =>
    family.filter(t => t.writable).length > 1;

  it('család nélkül nincs választó', () => {
    expect(vanValasztas([])).toBeFalse();
  });

  it('egyetlen írható templomnál nincs választó', () => {
    expect(vanValasztas([tag(1, true), tag(2, false), tag(3, false)])).toBeFalse();
  });

  it('két írható templomnál van választó', () => {
    expect(vanValasztas([tag(1, true), tag(2, true)])).toBeTrue();
  });
});
