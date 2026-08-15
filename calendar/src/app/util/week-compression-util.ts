/**
 * #358: a heti naptár-nézet idősáv-tömörítése — VALÓDI törött időtengely.
 *
 * borazslo issue-leírása: „A hét nézet nehezen fér el egy képernyőn, mert hát
 * a reggeli misék és az esti misék között jó nagy a távolság. De nem lehet
 * simán kiiktatni a közepét sem az éjszakát, mert van olyan hogy nagyszombat,
 * és van olyan hogy valami extra."
 *
 * Két, egymást kiegészítő eszköz:
 *  1. `slotMinTime`/`slotMaxTime` — a nap ELEJÉN (első mise előtt) és VÉGÉN
 *     (utolsó mise után) lévő üres sávot levágja (a FullCalendar ki sem rajzolja).
 *  2. `collapsedSlotMinutes` — a KÖZÉPSŐ üres slotok (reggel↔este közti holt idő)
 *     minute-of-day listája. A UI ezeket CSS-sel 0 magasságúra húzza, így az este
 *     felcsúszik a reggel alá — de EGY foglalt slot (pl. Nagyszombat 15:00) SOSEM
 *     kerül a listába, ezért az köré „törik" a tengely, és a helyén marad.
 *
 * Tisztán számoló pure-function, semmi side-effect.
 */

export interface CompressionInput {
  /** Az aktuális nézet kezdő dátuma (inclusive). */
  weekStart: Date;
  /** Az aktuális nézet vég-dátuma (exclusive). */
  weekEnd: Date;
  /** Az események listája. Csak `start` és `end` (Date) kötelező; egyebek átmennek. */
  events: ReadonlyArray<WeekEvent>;
  /** Kapcsolók — alapértelmezés ipari. */
  options?: CompressionOptions;
}

export interface WeekEvent {
  start: Date;
  end: Date;
  title?: string;
  extendedProps?: Record<string, any>;
}

export interface CompressionOptions {
  /** Reggel/délelőtt határa (óra). Default: 12. */
  morningEndHour?: number;
  /** Délután/este határa (óra). Default: 14. */
  eveningStartHour?: number;
  /** Minimum „nagy lyuk" amit megéri tömöríteni (óra). Default: 3. */
  minGapHours?: number;
  /**
   * Ennél rövidebb összefüggő üres sávot NEM húzunk össze (óra). Default: 1.
   * Ez védi a misék közti apró réseket a túl-tömörítéstől (#358 review).
   */
  minCollapseRunHours?: number;
  /** Minimum eseményszám amelynél tömörítünk. Kevesebbnél nem érdemes. Default: 3. */
  minEventsThreshold?: number;
  /** Hozzáad puffer-órákat a `slotMinTime` előtt és a `slotMaxTime` után. Default: 0. */
  paddingHours?: number;
  /** A naptár slot-felbontása percben (a FullCalendar `slotDuration`-jével EGYEZZEN). Default: 30. */
  slotDurationMinutes?: number;
}

export interface CompressionResult {
  /** Aktívan kell-e tömöríteni. Ha false, a default slotMinTime/slotMaxTime maradjon. */
  shouldCompress: boolean;
  /** Az ajánlott `slotMinTime` ('HH:MM:SS'). Slot-határra igazítva. */
  slotMinTime: string;
  /** Az ajánlott `slotMaxTime` ('HH:MM:SS'). Slot-határra igazítva. */
  slotMaxTime: string;
  /**
   * #358: azoknak a KÖZÉPSŐ üres slotoknak a minute-of-day értékei (slot-kezdet),
   * amiket a UI-nak 0 magasságúra kell húznia. A [slotMin, slotMax) ablakon belül
   * minden slot-kezdet, amit EGYETLEN esemény sem fed le. A foglalt slotok (bármely
   * mise, akár egy középső Nagyszombat) kimaradnak — köréjük „törik" a tengely.
   */
  collapsedSlotMinutes: number[];
  /** Diagnosztika a megjelenítéshez (tooltip). */
  diagnostics: CompressionDiagnostics;
}

export interface CompressionDiagnostics {
  totalEvents: number;
  reason:
    | 'no-events'
    | 'too-few-events'
    | 'no-gap-detected'
    | 'gap-too-small'
    | 'compressed';
  /** A felismert gap kezdete (HH:MM) — csak ha compressed. */
  gapStart?: string;
  /** A felismert gap vége (HH:MM) — csak ha compressed. */
  gapEnd?: string;
  /** A felismert gap mérete órában — csak ha compressed. */
  gapSizeHours?: number;
  /** Hány középső slotot húzunk össze — csak ha compressed. */
  collapsedSlotCount?: number;
}

export class WeekCompressionUtil {

  static readonly DEFAULTS: Required<CompressionOptions> = {
    morningEndHour: 12,
    eveningStartHour: 14,
    minGapHours: 3,
    minCollapseRunHours: 1,
    minEventsThreshold: 3,
    paddingHours: 0,
    slotDurationMinutes: 30,
  };

  /** A FullCalendar `getEvents()` visszaadta esemény minimális alakja. */
  static readonly DEFAULT_EVENT_MINUTES = 60;

  /**
   * #358: a nap hányadik percében kezdődik ez az időpont — a NAPTÁR faliórája szerint.
   *
   * A naptár a templom saját időzónájában fut (`timeZone: 'Europe/Budapest'`), de
   * időzóna-plugin NINCS betöltve (dayGrid/timeGrid/list/interaction/rrule). Ilyenkor a
   * FullCalendar a dátumokat UTC-alapon kezeli: a faliidő a Date UTC-mezőiben van.
   * A `getHours()` ezért a böngésző zónájával eltolva olvasna — nyáron +2 órával.
   *
   * Ez nem elméleti: emiatt látszott a 08:00-s vasárnapi mise 10:00-nak, a levágás
   * `slotMinTime`-ja 10:00 lett (a `slotMinTime` viszont faliidőt vár), és a 8 órás
   * mise egyszerűen eltűnt a heti nézetből.
   */
  static minuteOfDay(d: Date): number {
    return d.getUTCHours() * 60 + d.getUTCMinutes();
  }

  /**
   * #358: a FullCalendar eseményeiből WeekEvent-lista.
   *
   * Ez a lépés eddig a komponensbe volt beágyazva, és pont ITT bújt meg a hiba: a
   * régi szűrő `!!e.start && !!e.end`-et követelt, a misék viszont pont-események
   * `end` NÉLKÜL — így minden eseményt kidobott, a tömörítés némán nem csinált
   * semmit, a kapcsoló holt volt. Kiemelve, hogy tesztelhető legyen.
   *
   * `end` hiányában a FullCalendar saját alapértelmezésével egyezően +1 órát veszünk.
   */
  static toWeekEvents(
    events: ReadonlyArray<{start: Date | null; end?: Date | null; title?: string; extendedProps?: unknown}>
  ): WeekEvent[] {
    const fallbackMs = WeekCompressionUtil.DEFAULT_EVENT_MINUTES * 60 * 1000;

    return events
      .filter(e => !!e.start)
      .map(e => {
        const start = e.start as Date;
        return {
          start,
          end: e.end ?? new Date(start.getTime() + fallbackMs),
          title: e.title || '',
          extendedProps: e.extendedProps as Record<string, any>,
        };
      });
  }

  /**
   * Központi belépő. Idempotens, pure-function — semmi side-effect.
   */
  static analyze(input: CompressionInput): CompressionResult {
    const opts: Required<CompressionOptions> = {...WeekCompressionUtil.DEFAULTS, ...(input.options ?? {})};
    const slot = opts.slotDurationMinutes > 0 ? opts.slotDurationMinutes : 30;

    // 1. Szűrjük a heti nézet idősávjába tartozó eseményeket.
    const weekEvents = (input.events ?? []).filter(e =>
      e.start && e.end
      && e.end.getTime() > input.weekStart.getTime()
      && e.start.getTime() < input.weekEnd.getTime(),
    );

    const totalEvents = weekEvents.length;

    // 2. Korai kilépés: nincs vagy túl kevés esemény.
    if (totalEvents === 0) {
      return WeekCompressionUtil.noCompressResult(totalEvents, 'no-events');
    }
    if (totalEvents < opts.minEventsThreshold) {
      return WeekCompressionUtil.noCompressResult(totalEvents, 'too-few-events');
    }

    // 3. Számoljuk a globális min/max órát az események alapján.
    let globalMinMin = 24 * 60;  // perces felbontásban
    let globalMaxMin = 0;
    let morningLatestMin = 0;    // legkésőbbi délelőtti (morningEndHour előtt befejeződő) esemény vége
    let eveningEarliestMin = 24 * 60;  // legkorábbi esti (eveningStartHour után kezdődő) esemény eleje

    for (const e of weekEvents) {
      const startMin = WeekCompressionUtil.minuteOfDay(e.start);
      const endMin = WeekCompressionUtil.minuteOfDay(e.end);
      // Ha az esemény ENDe „átnyúlik másnapra", clamp 24:00-ra (a gap-analízis a napon belül érvényes).
      const cappedEnd = endMin > 0 && endMin < startMin ? 24 * 60 : endMin;

      if (startMin < globalMinMin) globalMinMin = startMin;
      if (cappedEnd > globalMaxMin) globalMaxMin = cappedEnd;

      // Délelőtti: az esemény vége strictly a morningEndHour előtt van.
      if (cappedEnd <= opts.morningEndHour * 60) {
        if (cappedEnd > morningLatestMin) morningLatestMin = cappedEnd;
      }
      // Esti: az esemény kezdete a eveningStartHour után van.
      if (startMin >= opts.eveningStartHour * 60) {
        if (startMin < eveningEarliestMin) eveningEarliestMin = startMin;
      }
    }

    // 4. Detektáljuk a gap-et.
    //    Ha nincs délelőtti VAGY nincs esti esemény, nincs értelme tömöríteni
    //    (mert akkor nincs amibe ütközni az amúgy üres közepet).
    const hasMorning = morningLatestMin > 0;
    const hasEvening = eveningEarliestMin < 24 * 60;

    if (!hasMorning || !hasEvening) {
      return WeekCompressionUtil.noCompressResult(totalEvents, 'no-gap-detected');
    }

    const gapMinutes = eveningEarliestMin - morningLatestMin;
    if (gapMinutes < opts.minGapHours * 60) {
      return WeekCompressionUtil.noCompressResult(totalEvents, 'gap-too-small');
    }

    // 5. Head/tail trim: a slotMin/Max a globális min/max + opcionális padding,
    //    SLOT-HATÁRRA igazítva (lefelé a min, felfelé a max), hogy a slot-kezdetek
    //    egybeessenek a FullCalendar slat-jaival (különben a collapse elcsúszna).
    const padMin = Math.max(0, opts.paddingHours) * 60;
    const slotMinMin = Math.max(0, Math.floor((globalMinMin - padMin) / slot) * slot);
    const slotMaxMin = Math.min(24 * 60, Math.ceil((globalMaxMin + padMin) / slot) * slot);

    // 6. Occupancy: minden slot-kezdet, amit LEGALÁBB egy esemény lefed.
    //    Egy [from,to) intervallum MINDEN átfedett slotját megjelöljük (nem csak
    //    a kezdőt), különben egy 45/90 perces mise „átlógna" egy összehúzott slotba.
    const occupied = new Set<number>();
    const markRange = (fromMin: number, toMin: number) => {
      const first = Math.floor(fromMin / slot) * slot;
      const lastExclusive = Math.ceil(toMin / slot) * slot;
      for (let s = first; s < lastExclusive; s += slot) {
        occupied.add(s);
      }
    };
    for (const e of weekEvents) {
      const startMin = WeekCompressionUtil.minuteOfDay(e.start);
      const endMin = WeekCompressionUtil.minuteOfDay(e.end);
      if (endMin > startMin) {
        markRange(startMin, endMin);
      } else if (endMin === startMin) {
        // nulla hosszú esemény: a kezdetet tartalmazó egyetlen slot
        markRange(startMin, startMin + 1);
      } else {
        // éjfél-átlógás: [startMin, 1440) + [0, endMin)
        markRange(startMin, 24 * 60);
        markRange(0, endMin);
      }
    }

    // 7. Collapsed slotok: MINDEN elég hosszú összefüggő üres futam a látható
    //    [slotMin, slotMax) ablakon belül.
    //
    //    Korábban ez csak a detektált „reggel↔este" sávra (`[morningLatestMin,
    //    eveningEarliestMin)`) korlátozódott, ami következetlen képet adott: a
    //    Szent István-bazilikán a 11:00–16:00 sáv összement, a 17:00–18:00 viszont
    //    nyitva maradt, pedig az is ugyanolyan üres — csak épp a legkorábbi esti
    //    mise (16:00) UTÁN van, tehát kívül esett a sávon.
    //
    //    A `minCollapseRunHours` küszöb védi meg attól, amit a #358 review kifogásolt
    //    (túl-tömörítés): a misék közti apró, félórás rések nyitva maradnak, csak a
    //    tényleg hosszú holt sávok húzódnak össze. A FOGLALT slotok sosem kerülnek
    //    a listába, ezért a középső misék (Nagyszombat, extra alkalom) a helyükön
    //    maradnak, és köréjük „törik" a tengely.
    const minRunSlots = Math.max(1, Math.round((opts.minCollapseRunHours * 60) / slot));
    const collapsedSlotMinutes: number[] = [];
    let runStart: number | null = null;

    const flushRun = (endExclusive: number) => {
      if (runStart === null) return;
      const runSlots = (endExclusive - runStart) / slot;
      if (runSlots >= minRunSlots) {
        for (let s = runStart; s < endExclusive; s += slot) {
          collapsedSlotMinutes.push(s);
        }
      }
      runStart = null;
    };

    for (let s = slotMinMin; s < slotMaxMin; s += slot) {
      if (occupied.has(s)) {
        flushRun(s);
      } else if (runStart === null) {
        runStart = s;
      }
    }
    flushRun(slotMaxMin);

    return {
      shouldCompress: true,
      slotMinTime: WeekCompressionUtil.minutesToTimeString(slotMinMin),
      slotMaxTime: WeekCompressionUtil.minutesToTimeString(slotMaxMin),
      collapsedSlotMinutes,
      diagnostics: {
        totalEvents,
        reason: 'compressed',
        gapStart: WeekCompressionUtil.minutesToTimeString(morningLatestMin).slice(0, 5),
        gapEnd: WeekCompressionUtil.minutesToTimeString(eveningEarliestMin).slice(0, 5),
        gapSizeHours: Math.round(gapMinutes / 60 * 10) / 10,
        collapsedSlotCount: collapsedSlotMinutes.length,
      },
    };
  }

  private static noCompressResult(
    totalEvents: number,
    reason: CompressionDiagnostics['reason'],
  ): CompressionResult {
    return {
      shouldCompress: false,
      slotMinTime: '00:00:00',
      slotMaxTime: '24:00:00',
      collapsedSlotMinutes: [],
      diagnostics: {totalEvents, reason},
    };
  }

  /** 0-1440 perc → 'HH:MM:SS'. 1440-re 24:00:00 megy vissza (FullCalendar-konform). */
  static minutesToTimeString(min: number): string {
    const clamped = Math.max(0, Math.min(24 * 60, Math.round(min)));
    const h = Math.floor(clamped / 60);
    const m = clamped % 60;
    return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:00`;
  }
}
