import {DateTime} from "luxon";
import {Day} from '../enum/day';
import {GeneratedPeriod} from '../model/generated-period';

export class DateTimeUtil {

  static DATETIME_FORMAT_HU = new Intl.DateTimeFormat('hu-HU', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });

  static CALENDAR_DATETIME_FORMAT = "yyyy-MM-dd'T'HH:mm";
  static CALENDAR_DATE_FORMAT = "yyyy. MM. dd.";
  static CALENDAR_TIME_FORMAT = "HH:mm";
  static READABLE_DATE_FORMAT = "yyyy. LLLL dd.";
  static READABLE_DATETIME_FORMAT = "yyyy. LLLL dd. HH:mm";

  public static getDateTimeString(date: Date) {
    return DateTimeUtil.DATETIME_FORMAT_HU.format(date);
  }

  /** Érvényes, valódi dátum-e? Egy Invalid Date getTime()-ja NaN. */
  public static isValidDate(value: unknown): value is Date {
    return value instanceof Date && !Number.isNaN(value.getTime());
  }

  /**
   * Ez a függvény gyártotta a `2026-01-01TNaN:NaN:NaN` kezdéseket, amik némán eltűntek
   * az oldalról: a `pad(NaN)` a "NaN" sztringet adja, a szerver pedig ellenőrzés nélkül
   * kiírta. A hibás mise a szerkesztőben látszott, a keresőben soha — a nagyatádi
   * Őrangyalok-kápolna miserendje így volt láthatatlan hét hónapig.
   *
   * A `periodDate` külön csúsztatta el a felismerést: a dátum-rész attól helyes maradt,
   * tehát az érték "majdnem jónak" nézett ki.
   *
   * Inkább hangosan elszállunk, mint hogy értelmezhetetlen időpontot mentsünk.
   */
  public static getIsoString(dateTime: Date, periodDate?: string): string {
    if (!DateTimeUtil.isValidDate(dateTime)) {
      throw new Error('Érvénytelen időpont: a mise kezdete nem értelmezhető.');
    }

    const pad = (n: number) => n.toString().padStart(2, '0');

    const date: string = periodDate ? periodDate : DateTimeUtil.getOnlyDateString(dateTime);

    const hours = pad(dateTime.getHours());
    const minutes = pad(dateTime.getMinutes());
    const seconds = pad(dateTime.getSeconds());

    return `${date}T${hours}:${minutes}:${seconds}`;
  }

  public static getOnlyDateString(date: Date): string {
    const pad = (n: number) => n.toString().padStart(2, '0');

    const year = date.getFullYear();
    const month = pad(date.getMonth() + 1);
    const day = pad(date.getDate());

    return `${year}-${month}-${day}`;
  }

  public static getOnlyTimeString(date: string): string {
    const localTime = DateTime.fromISO(date);
    return localTime.toFormat(DateTimeUtil.CALENDAR_TIME_FORMAT);
  }

  public static getReadableDateFromIso(isoString: string): string {
    return DateTime.fromISO(isoString).setLocale('hu').toFormat(DateTimeUtil.READABLE_DATE_FORMAT);
  }

  public static getReadableDateTime(dateTime: Date): string {
    return DateTime.fromJSDate(dateTime).setLocale('hu').toFormat(DateTimeUtil.READABLE_DATETIME_FORMAT);
  }

  public static getReadableTime(dateTime: Date): string {
    return DateTime.fromJSDate(dateTime).setLocale('hu').toFormat(DateTimeUtil.CALENDAR_TIME_FORMAT);
  }

  public static getReadableDateTimeFromIso(dateTime: string): string {
    return DateTime.fromISO(dateTime).setLocale('hu').toFormat(DateTimeUtil.READABLE_DATETIME_FORMAT);
  }

  static getShortEnDay(date: Date): Day {
    const dt = DateTime.fromJSDate(date);
    switch (dt.weekday) {
      case 1: return Day.MO;
      case 2: return Day.TU;
      case 3: return Day.WE;
      case 4: return Day.TH;
      case 5: return Day.FR;
      case 6: return Day.SA;
      default: return Day.SU;
    }
  }

  static getExRuleDateTime(periodStart: string, startTime: string): string {
    const timeString = DateTimeUtil.getOnlyTimeString(startTime);
    return `${periodStart}T${timeString}`;
  }

  /**
   * Alapból egy periódus endDate-je úgy van tárolva, hogy az már nem számít bele az ismétlődésekbe.
   * De pl. ha éjféli esemény van, akkor még okozhat duplikációt, így inkább átállítjuk előző nap végére.
   */
  public static adjustEndDates(generatedPeriods: GeneratedPeriod[]): GeneratedPeriod[] {
    return generatedPeriods.map(period => {
      const endDateTime = DateTime.fromISO(period.endDate);
      const adjustedEndDate = endDateTime.minus({ days: 1 }).endOf('day').toFormat(DateTimeUtil.CALENDAR_DATETIME_FORMAT);
      return {
        ...period,
        endDate: adjustedEndDate ? adjustedEndDate : period.endDate
      };
    });
  }
}
