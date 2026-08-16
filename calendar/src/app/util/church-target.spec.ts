import {MassUtil} from './mass-util';
import {Mass} from '../model/mass';

/**
 * #506: melyik templomhoz kerül a mise a szerkesztés után?
 *
 * borazslo jelentése a /templom/2172?csalad=1 oldalról:
 *
 *   „Módosítani kívánom a babarszőlősi vasárnapi 8-as misét, akkor kattingatok és
 *    megjelenik a select hogy »Melyik templomban?«. de ott »Bicsérd« szerepel, nem a
 *    helyes templom."
 *
 * Az ok: a `massToDialogEvent` nem adta vissza a mise saját templomát, tehát a
 * választó a lista ELSŐ elemére esett vissza — és mentéskor a mise NÉMÁN odakerült.
 * Család módban ez adatvesztés: a fília miséje a plébániához íródott volna át.
 *
 * A hiba csak akkor látszik, ha valaki figyeli a legördülőt. Ezért mérjük itt.
 */
describe('MassUtil.massToDialogEvent — templom-hovatartozás (#506)', () => {

  function mise(churchId: number, extra: Partial<Mass> = {}): Mass {
    return {
      id: 42,
      churchId,
      title: 'Szentmise',
      rite: 'ROMAN_CATHOLIC',
      startDate: '2026-03-01T08:00:00',
      lang: 'hu',
      ...extra,
    } as Mass;
  }

  it('visszaadja a mise saját templomát', () => {
    expect(MassUtil.massToDialogEvent(mise(2173)).churchId).toBe(2173);
  });

  /**
   * Ez a lényeg: a szerkesztő a `churchId ?? churches[0].id` alakot használja, tehát
   * ha itt `undefined` jön vissza, a mise a lista első templomához kerül.
   */
  it('nem hagyja üresen — különben a lista első templomához kerülne a mise', () => {
    expect(MassUtil.massToDialogEvent(mise(2173)).churchId).toBeDefined();
  });

  /** Egyetlen alkalom szerkesztésekor is a mise saját temploma az alapértelmezés. */
  it('egyetlen alkalom szerkesztésénél is megmarad', () => {
    const esemeny = MassUtil.massToDialogEventEditOne(mise(2173), new Date('2026-03-08T08:00:00'));

    expect(esemeny.churchId).toBe(2173);
    expect(esemeny.editOne).toBeTrue();
  });

  /**
   * A másolás új misét hoz létre a forrás adataiból. A templomot innen is örökölnie
   * kell, különben a másolat a lista első templomához kerülne.
   */
  it('a másoláshoz készült dialógus is a forrás templomát hozza', () => {
    expect(MassUtil.massToDialogEvent(mise(2173, {periodId: 5})).churchId).toBe(2173);
  });
});
