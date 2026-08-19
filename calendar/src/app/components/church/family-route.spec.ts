import {ChurchComponent} from './church.component';

/**
 * #830: mikor mutassa a naptár a család miserendjét?
 *
 * borazslo kérése a #804-hez: a `?csalad=1` helyett legyen saját, beszédes útvonal
 * („kéne egy szó hogy miserend.hu/[valami ügyes szó]/:id"). A paraméteres alak
 * viszont NEM tűnhet el: a #804 óta kiküldött linkek és könyvjelzők nem törhetnek el.
 *
 * Ez a döntés tiszta függvény, ezért komponens-példány nélkül mérhető — a naptár
 * összeszerelése (FullCalendar, dialógusok, szolgáltatások) semmit nem tenne hozzá.
 */
describe('ChurchComponent.isFamilyRoute (#830)', () => {

  describe('a régi, paraméteres alak', () => {

    it('a ?csalad=1 továbbra is család mód', () => {
      expect(ChurchComponent.isFamilyRoute('1', '/templom/2172')).toBeTrue();
    });

    it('más érték nem az', () => {
      expect(ChurchComponent.isFamilyRoute('0', '/templom/2172')).toBeFalse();
      expect(ChurchComponent.isFamilyRoute('igen', '/templom/2172')).toBeFalse();
    });

    it('paraméter nélkül a templom-oldal marad egytemplomos', () => {
      expect(ChurchComponent.isFamilyRoute(null, '/templom/2172')).toBeFalse();
    });
  });

  describe('az új útvonal', () => {

    it('a /plebania/:id család mód paraméter nélkül is', () => {
      expect(ChurchComponent.isFamilyRoute(null, '/plebania/2172')).toBeTrue();
    });

    /**
     * A naptár egy PHP-lapba ágyazva fut, ezért a teljes címet nézzük — a beágyazás
     * miatt a router csak részleges útvonalat láthat.
     */
    it('előtaggal együtt is felismeri', () => {
      expect(ChurchComponent.isFamilyRoute(null, '/valami/plebania/2172')).toBeTrue();
    });

    it('záró perjellel is', () => {
      expect(ChurchComponent.isFamilyRoute(null, '/plebania/2172/')).toBeTrue();
    });
  });

  describe('amit NEM szabad félreértenie', () => {

    /**
     * A „plebania" szó a templom nevében vagy a leírásban is előfordulhat — csak
     * akkor számít, ha ÚTVONAL-szakasz, és szám követi.
     */
    it('a szövegben előforduló szó nem elég', () => {
      expect(ChurchComponent.isFamilyRoute(null, '/templom/2172/plebaniatortenet')).toBeFalse();
    });

    it('azonosító nélkül nem család mód', () => {
      expect(ChurchComponent.isFamilyRoute(null, '/plebania')).toBeFalse();
      expect(ChurchComponent.isFamilyRoute(null, '/plebania/')).toBeFalse();
    });

    it('nem szám azonosítóra sem', () => {
      expect(ChurchComponent.isFamilyRoute(null, '/plebania/valami')).toBeFalse();
    });

    it('üres útvonalon sem esik el', () => {
      expect(ChurchComponent.isFamilyRoute(null, '')).toBeFalse();
      expect(ChurchComponent.isFamilyRoute(null, null as any)).toBeFalse();
    });
  });
});
