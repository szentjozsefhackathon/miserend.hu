import {MassUtil} from './mass-util';
import {ChurchFamilyMember} from '../model/church';
import {Rite} from '../enum/rites';

/**
 * #506: mi álljon a rokon templomok eseményei előtt a naptárban.
 *
 * borazslo szabálya: „gyakran jobb a település nevét kiírni mint a templomét". Igaza
 * van — egy plébánia fíliái jellemzően különböző falvakban vannak, és a hívőnek a falu
 * mond valamit, nem a titulus („Nagyboldogasszony"-ból tíz is van a megyében).
 *
 * De ha KÉT misézőhely is ugyanabban a faluban van, a falunév már nem különböztet meg —
 * ilyenkor kell a templomnév is. Ez a fájl pontosan ezt a négy esetet méri.
 */

function tag(overrides: Partial<ChurchFamilyMember> = {}): ChurchFamilyMember {
  return {
    id: 1,
    name: 'Szent Kereszt',
    city: 'Röszke',
    rite: Rite.ROMAN_CATHOLIC,
    writable: true,
    isCurrent: false,
    masses: [],
    ...overrides,
  };
}

describe('MassUtil.familyCalendarLabels (#506)', () => {

  /** A szerkesztett templom eseményei ugyanúgy néznek ki, mint az egy-templomos nézetben. */
  it('a szerkesztett templom nem kap előtagot', () => {
    const cimkek = MassUtil.familyCalendarLabels([
      tag({id: 1, isCurrent: true}),
      tag({id: 2, name: 'Szent Anna', city: 'Domaszék'}),
    ]);

    expect(cimkek.has(1)).toBeFalse();
  });

  it('azonos településen a templom nevét írja ki', () => {
    const cimkek = MassUtil.familyCalendarLabels([
      tag({id: 1, name: 'Szent Kereszt', city: 'Szeged', isCurrent: true}),
      tag({id: 2, name: 'Szent Anna', city: 'Szeged'}),
    ]);

    expect(cimkek.get(2)).toBe('Szent Anna');
  });

  it('másik településen — ahol csak egy érintett hely van — a település nevét írja ki', () => {
    const cimkek = MassUtil.familyCalendarLabels([
      tag({id: 1, city: 'Szeged', isCurrent: true}),
      tag({id: 2, name: 'Szent Anna', city: 'Domaszék'}),
    ]);

    expect(cimkek.get(2)).toBe('Domaszék');
  });

  /**
   * Ez a szabály lényege: két domaszéki misézőhelynél a puszta „Domaszék" két
   * különböző eseményt jelölne ugyanúgy — a hívő nem tudná, melyikre menjen.
   */
  it('másik településen — ahol több érintett hely van — település és templomnév is kell', () => {
    const cimkek = MassUtil.familyCalendarLabels([
      tag({id: 1, city: 'Szeged', isCurrent: true}),
      tag({id: 2, name: 'Szent Anna', city: 'Domaszék'}),
      tag({id: 3, name: 'Kápolna', city: 'Domaszék'}),
    ]);

    expect(cimkek.get(2)).toBe('Domaszék, Szent Anna');
    expect(cimkek.get(3)).toBe('Domaszék, Kápolna');
  });

  /**
   * A számolás az ÉRINTETT helyekre megy: a település többi temploma nincs a
   * naptárban, tehát nincs mitől megkülönböztetni.
   */
  it('a saját település nem duzzasztja fel a másik település számlálóját', () => {
    const cimkek = MassUtil.familyCalendarLabels([
      tag({id: 1, name: 'Szent Kereszt', city: 'Szeged', isCurrent: true}),
      tag({id: 2, name: 'Szent Anna', city: 'Szeged'}),
      tag({id: 3, name: 'Kápolna', city: 'Domaszék'}),
    ]);

    expect(cimkek.get(3)).toBe('Domaszék');
  });

  /**
   * A szlovák állomány 23%-ának nincs határlánca, tehát a település ÜRES lehet.
   * Ilyenkor a templomnév az egyetlen fogódzó — üres előtag nem elfogadható.
   */
  it('település nélkül a templom nevét írja ki', () => {
    const cimkek = MassUtil.familyCalendarLabels([
      tag({id: 1, city: 'Szeged', isCurrent: true}),
      tag({id: 2, name: 'Szent Anna', city: ''}),
    ]);

    expect(cimkek.get(2)).toBe('Szent Anna');
  });

  it('egy-templomos szerkesztőben nincs egyetlen címke sem', () => {
    expect(MassUtil.familyCalendarLabels([]).size).toBe(0);
    expect(MassUtil.familyCalendarLabels([tag({isCurrent: true})]).size).toBe(0);
  });
});

describe('MassUtil.familySelectorLabel (#506)', () => {

  /**
   * A választó DÖNTÉSI pont: ha félreértjük, a mise rossz templomhoz íródik. Ezért itt
   * nem a naptár rövidítő szabálya megy — mindig kiírjuk mindkettőt.
   */
  it('mindig település és templomnév', () => {
    expect(MassUtil.familySelectorLabel(tag({name: 'Szent Anna', city: 'Domaszék'})))
      .toBe('Domaszék, Szent Anna');
  });

  it('a szerkesztett templomot sem rövidíti', () => {
    expect(MassUtil.familySelectorLabel(tag({name: 'Szent Kereszt', city: 'Szeged', isCurrent: true})))
      .toBe('Szeged, Szent Kereszt');
  });

  it('település nélkül csak a templomnév marad', () => {
    expect(MassUtil.familySelectorLabel(tag({name: 'Szent Anna', city: ''})))
      .toBe('Szent Anna');
  });
});
