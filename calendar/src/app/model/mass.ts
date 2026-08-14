import {RecurrenceRule} from './calendar/recurrence-rule';
import {Rite} from '../enum/rites';
import {MassType} from '../enum/types';

export interface Mass {

  /**
   * Mise egyedi azonosítója
   */
  id: number;

  /**
   * Templom egyedi azonosítója
   */
  churchId: number;

  /**
   * Időszak egyedi azonosítója
   */
  periodId?: number | null;

  /**
   * Cím (naptárban megjelenő)
   */
  title: string;

  /**
   * A liturgia jellemzői.
   */
  types?: MassType[] | null;

  /**
   * A liturgia rítusa. Default: a templom rítusa szerinti
   */
  rite: Rite;

  /**
   * A mise (sorozat) kezdő időpontja.
   * pl.: 2025-03-24T10:00:00
   */
  startDate: string;

  /**
   * A mise hossza. Alapértelmezetten 1 óra.
   * pl.: {hours: 1, minutes: 30}
   */
  duration?: Duration | null;

  /**
   * Az ismétlődési szabály. A dtstart és az until az időszak alapján számolódik (ha van).
   * {
   *  dtstart: "2025-01-01T07:00:00",
   *  until: "2026-01-01"
   *  tzid: "Europe/Budapest",
   *  freq: "WEEKLY",
   *  byweekday: ["MO","TU","WE","TH","FR","SA","SU"]
   * }
   */
  rrule?: RecurrenceRule | null;

  /**
   * A megadott időpontokkal kezdődő események elrejtése.
   * Pl.: ['2025-04-02T07:00:00']
   */
  exdate?: string[] | null;

  /**
   * A megadott periódusok által lefedett időpontok elrejtése.
   * Ez az AUTOMATIKUSAN számolt kizárás (ütközés-elkerülés). A mentéskori
   * automatikák (backend optimizeExperiods, frontend collision-logika) ezt
   * szabadon újraszámolják.
   */
  experiod?: number[] | null;

  /**
   * #428: A felhasználó által KÉZZEL beállított kizárt időszakok. Ezt egyik
   * automatika sem bántja, így megmarad akkor is, ha a kizárt periódusban
   * egyáltalán nincs is külön mise (pl. "egész évben, kivéve nyáron").
   * Megjelenítéskor/dátumszűréskor az `experiod`-dal UNIÓBAN rejt.
   */
  manualExperiod?: number[] | null;

  /**
   * A mise nyelvének kétbetűs kódja. Default: hu
   *
   * #334: több nyelvű mise esetén vesszővel elválasztva több kód is állhat benne
   * ("sk,la"). A listás alakhoz: MassUtil.languageCodes().
   */
  lang: string;

  /**
   * Szöveges megjegyzés a miséhez.
   */
  comment?: string | null;

  /**
   * #592: külső naptárból (iCalendar) importált liturgia. Ezeket a napi szinkron
   * teljes cserével írja újra, ezért itt nem szerkeszthetők — a szerkesztő
   * felajánlás helyett magyarázatot mutat. A backend származtatja, nem tárolt mező.
   */
  imported?: boolean;
}

export interface Duration {
  days?: number,
  hours?: number,
  minutes?: number
}
