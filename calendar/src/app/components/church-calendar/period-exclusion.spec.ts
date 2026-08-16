import {ChurchCalendarComponent} from './church-calendar.component';
import {Mass} from '../../model/mass';

/**
 * #747: melyik időszak nyer, ha két mise ugyanarra a napra esik?
 *
 * A szabály eddig csak a SÚLYT nézte. borazslo megtalálta, hogy ez kevés — és azt is,
 * hogy a javítás első fele nem elég:
 *
 *   „A Nyári szünet 7 órás miséjét módosítottam 8 órásra. És ekkor eltűnt erről a
 *    miséről a Kivétel. És a naptárban is jól látszik: egész augusztusban két
 *    csütörtök mise van."
 *
 * A Nyári időszámítás (súly 5, 03-29–10-25) teljesen lefedi a Nyári szünetet
 * (súly 3, 06-16–08-31). Ilyenkor a kizárás nem felülírás, hanem KIÜRÍTÉS: a
 * szűkebb mise sehol nem maradna látható. Tehát nem őt zárjuk ki — hanem fordítva,
 * a szélesebbet a szűkebb tartományában.
 *
 * A kihagyás önmagában viszont azt eredményezi, hogy MINDKÉT mise látszik. Pont ezt
 * jelentette borazslo. A fordítást ezért meg is kell tenni, nem csak elhagyni a
 * kizárást.
 *
 * Miért nem TestBed: a `ChurchCalendarComponent` konstruktora fél tucat szolgáltatást
 * kér, és a teljes komponens tesztje ma is pendingben van (#436). Az itt mért
 * viselkedés viszont tiszta algebra a mise-térképeken — prototípusra épített
 * példányon pontosan mérhető, a naptár összeszerelése nélkül.
 */
describe('Időszak-kizárás lefedésnél (#747)', () => {

  /** period_id -> [súly, kezdet, vég] */
  const IDOSZAKOK: Record<number, [number, string, string]> = {
    1: [0, '2026-01-01', '2026-12-31'],  // Egész évben
    2: [5, '2026-03-29', '2026-10-25'],  // Nyári időszámítás
    3: [3, '2026-06-16', '2026-08-31'],  // Nyári szünet
  };

  let component: any;

  function mise(id: number, periodId: number, experiod?: number[]): Mass {
    return {id, churchId: 1, periodId, title: 'Szentmise', startDate: '2026-06-18T07:00:00',
            ...(experiod && {experiod})} as Mass;
  }

  beforeEach(() => {
    component = Object.create(ChurchCalendarComponent.prototype);
    component.masses = new Map<number, Mass>();
    component.changes = new Map<number, Mass>();
    component.deletedMasses = [];
    component.refreshCalendarAndMassList = () => { /* a naptár újrarajzolása itt nem érdekes */ };
    component.periodService = {
      getPeriodById: (id?: number) =>
        id && IDOSZAKOK[id] ? {id, weight: IDOSZAKOK[id][0], name: 'p' + id} : null,
      getGeneratedPeriodsByPeriodId: (id?: number) =>
        id && IDOSZAKOK[id]
          ? [{periodId: id, startDate: IDOSZAKOK[id][1], endDate: IDOSZAKOK[id][2]}]
          : [],
    };
  });

  describe('a lefedő, nagyobb súlyú időszak', () => {

    /** A bejelentett eset: a szűkebb misét szerkesztjük. */
    it('nem kerül be a szűkebb mise kizárásai közé', () => {
      component.masses.set(10, mise(10, 2)); // nyári időszámítás
      const szunetiMise = mise(11, 3);       // nyári szünet, most szerkesztve

      component.excludeHigherPeriodMassesFromNewMass(szunetiMise, 3, 3);

      expect(szunetiMise.experiod ?? []).not.toContain(2);
    });

    /**
     * Ez a hiányzó fél: a kihagyás önmagában két látható misét hagyott augusztusra.
     */
    it('viszont ŐT zárjuk ki a szűkebb időszakban', () => {
      component.masses.set(10, mise(10, 2));
      const szunetiMise = mise(11, 3);

      component.excludeHigherPeriodMassesFromNewMass(szunetiMise, 3, 3);

      expect(component.changes.get(10)?.experiod).toContain(3);
    });

    it('a mentett misét nem a helyén írja át, hanem a változások közé teszi', () => {
      const eredeti = mise(10, 2);
      component.masses.set(10, eredeti);

      component.excludeHigherPeriodMassesFromNewMass(mise(11, 3), 3, 3);

      expect(eredeti.experiod ?? []).not.toContain(3);
      expect(component.changes.has(10)).toBeTrue();
    });

    it('kétszer futtatva sem duplázza a kizárást', () => {
      component.masses.set(10, mise(10, 2));

      component.excludeHigherPeriodMassesFromNewMass(mise(11, 3), 3, 3);
      component.excludeHigherPeriodMassesFromNewMass(mise(12, 3), 3, 3);

      expect(component.changes.get(10)?.experiod).toEqual([3]);
    });
  });

  describe('a másik irány', () => {

    /**
     * Ugyanaz a szabály a szélesebb mise felől nézve: ha ÉN fedem le a kisebb súlyút,
     * akkor ő a specifikusabb — őt zárom ki magamból.
     */
    it('a szélesebb mise kizárja a lefedett, kisebb súlyú időszakot', () => {
      component.masses.set(11, mise(11, 3)); // nyári szünet (szűkebb, kisebb súly)
      const idoszamitasMise = mise(10, 2);   // nyári időszámítás, most szerkesztve

      component.excludeHigherPeriodMassesFromNewMass(idoszamitasMise, 2, 5);

      expect(idoszamitasMise.experiod).toContain(3);
    });
  });

  describe('ahol a súly dönt', () => {

    /**
     * Nincs lefedés: a Nyári szünet (06-16–08-31) NEM tartalmazza az Egész évben-t.
     * Ilyenkor marad a régi viselkedés — a kisebb súlyú zárja ki a nagyobbat.
     */
    it('lefedés nélkül a nagyobb súlyú kerül a kizárásba', () => {
      component.masses.set(11, mise(11, 3));
      const eveseMise = mise(10, 1);

      component.excludeHigherPeriodMassesFromNewMass(eveseMise, 1, 0);

      expect(eveseMise.experiod).toContain(3);
      expect(component.changes.has(11)).toBeFalse();
    });
  });

  describe('azonos tartományú időszakok', () => {

    /**
     * A tartalmazás nem szigorú, tehát két AZONOS tartomány kölcsönösen „lefedi"
     * egymást. Ott viszont egyik sem a szűkebb — maradjon a súly. Enélkül a nagyobb
     * súlyú veszítene a vele azonos tartományú kisebbel szemben, ami a súlyozás
     * értelmét fordítaná meg.
     */
    it('a súly dönt, nem a lefedés', () => {
      IDOSZAKOK[4] = [7, '2026-06-16', '2026-08-31']; // ugyanaz a tartomány, nagyobb súly
      component.masses.set(20, mise(20, 4));
      const szunetiMise = mise(11, 3);

      component.excludeHigherPeriodMassesFromNewMass(szunetiMise, 3, 3);

      expect(szunetiMise.experiod).toContain(4);
      expect(component.changes.has(20)).toBeFalse();
    });
  });

  describe('hiányzó adat', () => {

    /** Generált tartomány nélkül nem tudunk lefedésről dönteni — maradjon a súly. */
    it('generált tartomány nélkül a súly dönt', () => {
      component.periodService.getGeneratedPeriodsByPeriodId = () => [];
      component.masses.set(10, mise(10, 2));
      const szunetiMise = mise(11, 3);

      component.excludeHigherPeriodMassesFromNewMass(szunetiMise, 3, 3);

      expect(szunetiMise.experiod).toContain(2);
    });
  });
});
