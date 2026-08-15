import {WeekCompressionUtil, WeekEvent} from './week-compression-util';

/**
 * #358: az események UTC-MEZŐKBEN hordozzák a faliidőt — pontosan úgy, ahogy a
 * FullCalendar adja őket, amikor névvel megadott időzónában fut (`timeZone:
 * 'Europe/Budapest'`) és nincs betöltve időzóna-plugin.
 *
 * Korábban ez a helper `setHours()`-t használt, tehát a GÉP zónája szerint épített
 * dátumot. Nyáron ez két órával eltolta a tesztadatot a valóságtól — a hiba, ami
 * miatt élesben a 08:00-s mise 10:00-nak látszott, itt nem tudott megjelenni.
 */
function ev(dayOffset: number, hStart: number, mStart: number, hEnd: number, mEnd: number, title = 'mise'): WeekEvent {
  const start = new Date(Date.UTC(2026, 2, 9 + dayOffset, hStart, mStart, 0, 0));
  const end = new Date(Date.UTC(2026, 2, 9 + dayOffset, hEnd, mEnd, 0, 0));
  return {start, end, title};
}

const WEEK_START = new Date(Date.UTC(2026, 2, 9));   // Mon 2026-03-09
const WEEK_END = new Date(Date.UTC(2026, 2, 16));    // Mon 2026-03-16 (exclusive)

describe('WeekCompressionUtil.analyze', () => {

  it('no-events: nem tömörít, üres collapsed-halmaz', () => {
    const result = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END, events: [],
    });
    expect(result.shouldCompress).toBe(false);
    expect(result.diagnostics.reason).toBe('no-events');
    expect(result.diagnostics.totalEvents).toBe(0);
    expect(result.collapsedSlotMinutes).toEqual([]);
  });

  it('too-few-events: 2 esemény még nem éri el a threshold-ot, nem tömörít', () => {
    const result = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END,
      events: [ev(0, 7, 0, 8, 0), ev(2, 18, 0, 19, 0)],
    });
    expect(result.shouldCompress).toBe(false);
    expect(result.diagnostics.reason).toBe('too-few-events');
    expect(result.diagnostics.totalEvents).toBe(2);
  });

  it('no-gap-detected: csak délelőtti, nincs esti — nem tömörít', () => {
    const result = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END,
      events: [ev(0, 7, 0, 8, 0), ev(1, 8, 0, 9, 0), ev(2, 9, 0, 10, 0)],
    });
    expect(result.shouldCompress).toBe(false);
    expect(result.diagnostics.reason).toBe('no-gap-detected');
  });

  it('gap-too-small: 2 órás lyuk a 3-órás threshold alatt, nem tömörít', () => {
    const result = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END,
      // morningLatest=12:00, eveningEarliest=14:00 → 2 óra gap < 3 óra threshold
      events: [ev(0, 9, 0, 10, 0), ev(1, 11, 0, 12, 0), ev(2, 14, 0, 15, 0)],
    });
    expect(result.shouldCompress).toBe(false);
    expect(result.diagnostics.reason).toBe('gap-too-small');
  });

  it('compress: tipikus reggel-este eset', () => {
    const result = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END,
      events: [
        ev(0, 7, 0, 8, 0, 'reggeli mise hétfő'),
        ev(0, 18, 0, 19, 0, 'esti mise hétfő'),
        ev(2, 7, 30, 8, 30, 'reggeli mise szerda'),
        ev(4, 18, 30, 19, 30, 'esti mise péntek'),
      ],
    });
    expect(result.shouldCompress).toBe(true);
    expect(result.diagnostics.reason).toBe('compressed');
    expect(result.slotMinTime).toBe('07:00:00');
    expect(result.slotMaxTime).toBe('19:30:00');
    expect(result.diagnostics.gapStart).toBe('08:30');  // latest morning end
    expect(result.diagnostics.gapEnd).toBe('18:00');    // earliest evening start
    expect(result.diagnostics.gapSizeHours).toBe(9.5);
  });

  it('collapse: a középső üres slotok collapsed, a reggeli/esti foglaltak NEM', () => {
    const result = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END,
      events: [
        ev(0, 8, 0, 9, 0),    // 08:00-09:00 → slot 480,510
        ev(2, 8, 30, 9, 30),  // 08:30-09:30 → slot 510,540
        ev(4, 18, 0, 19, 0),  // 18:00-19:00 → slot 1080,1110
      ],
    });
    expect(result.shouldCompress).toBe(true);
    const collapsed = new Set(result.collapsedSlotMinutes);
    // reggeli/esti foglalt slotok NEM collapsed
    expect(collapsed.has(480)).toBe(false);   // 08:00
    expect(collapsed.has(510)).toBe(false);   // 08:30
    expect(collapsed.has(1080)).toBe(false);  // 18:00
    // a középső üres sáv IGEN collapsed
    expect(collapsed.has(600)).toBe(true);    // 10:00
    expect(collapsed.has(720)).toBe(true);    // 12:00
    expect(collapsed.has(900)).toBe(true);    // 15:00
    // a collapsed slotok mind a [slotMin=480, slotMax=1140) ablakon belül vannak
    for (const s of result.collapsedSlotMinutes) {
      expect(s).toBeGreaterThanOrEqual(480);
      expect(s).toBeLessThan(1140);
    }
  });

  /**
   * A collapse MINDEN elég hosszú üres futamra vonatkozik, nem csak a „reggel↔este"
   * sávra. Korábban az utóbbira korlátoztuk, ami következetlen képet adott: a
   * Szent István-bazilikán a 11:00–16:00 összement, a 17:00–18:00 viszont nyitva
   * maradt — pedig az is ugyanolyan üres, csak a legkorábbi esti mise UTÁN van.
   */
  it('minden elég hosszú üres futam collapsed — a reggeli holt sáv is', () => {
    const result = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END,
      events: [
        ev(0, 6, 0, 7, 0),    // reggel korán (06:00-07:00)
        ev(0, 10, 0, 11, 0),  // késő reggel (10:00-11:00) — a kettő közt 3 órás rés
        ev(2, 6, 0, 7, 0),
        ev(2, 10, 0, 11, 0),
        ev(4, 18, 0, 19, 0),  // este
      ],
    });
    expect(result.shouldCompress).toBe(true);
    const collapsed = new Set(result.collapsedSlotMinutes);

    // A reggeli 3 órás holt sáv is összemegy — ugyanolyan üres, mint a délutáni.
    expect(collapsed.has(480)).toBe(true);   // 08:00
    expect(collapsed.has(540)).toBe(true);   // 09:00
    // A középső gap természetesen szintén.
    expect(collapsed.has(720)).toBe(true);   // 12:00
    expect(collapsed.has(900)).toBe(true);   // 15:00
    expect(collapsed.has(1020)).toBe(true);  // 17:00

    // De a MISÉK slotjai sosem.
    [360, 600, 1080].forEach(min => {
      expect(collapsed.has(min)).toBe(false);
    });
  });

  /**
   * Ez véd a túl-tömörítéstől, amit a #358 review kifogásolt: a misék közti apró
   * rés maradjon nyitva, csak a tényleg hosszú holt sáv menjen össze.
   */
  it('a küszöbnél rövidebb rés NEM collapsed', () => {
    const result = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END,
      events: [
        ev(0, 7, 0, 7, 30),   // 07:00-07:30
        ev(0, 8, 0, 8, 30),   // 08:00-08:30 — köztük CSAK 30 perc
        ev(0, 18, 0, 19, 0),  // este
        ev(2, 7, 0, 7, 30),
        ev(2, 18, 0, 19, 0),
      ],
    });
    expect(result.shouldCompress).toBe(true);
    const collapsed = new Set(result.collapsedSlotMinutes);

    // A 07:30-08:00 rés fél óra: az alapértelmezett 1 órás küszöb alatt van.
    expect(collapsed.has(450)).toBe(false);  // 07:30
    // A délelőtt-esti holt sáv viszont bőven fölötte.
    expect(collapsed.has(600)).toBe(true);   // 10:00
  });

  it('a küszöb hangolható', () => {
    const events = [
      ev(0, 7, 0, 7, 30),
      ev(0, 8, 0, 8, 30),
      ev(0, 18, 0, 19, 0),
      ev(2, 7, 0, 7, 30),
      ev(2, 18, 0, 19, 0),
    ];
    // Fél órás küszöbbel a 07:30-as rés is összemegy.
    const lazabb = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END, events,
      options: {minCollapseRunHours: 0.5},
    });
    expect(new Set(lazabb.collapsedSlotMinutes).has(450)).toBe(true);

    // Három órás küszöbbel viszont csak a nagy sáv.
    const szigorubb = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END, events,
      options: {minCollapseRunHours: 3},
    });
    expect(new Set(szigorubb.collapsedSlotMinutes).has(450)).toBe(false);
    expect(new Set(szigorubb.collapsedSlotMinutes).has(600)).toBe(true);
  });

  it('nagyszombat: egy KÖZÉPSŐ mise slotjai NEM collapsed-ek (körülötte törik a tengely)', () => {
    const result = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END,
      events: [
        ev(0, 7, 0, 8, 0),     // hétfő reggel
        ev(0, 18, 0, 19, 0),   // hétfő este
        ev(2, 7, 30, 8, 30),   // szerda reggel
        ev(4, 18, 30, 19, 30), // péntek este
        // Szombat 12:00-13:00 — a gap KÖZEPÉN. 12:00 < 14:00 (eveningStartHour),
        // ezért nem esti eseménynek számít, a gap-detektálást nem zavarja meg.
        ev(5, 12, 0, 13, 0, 'nagyszombati vigília'),
      ],
    });
    expect(result.shouldCompress).toBe(true);
    const collapsed = new Set(result.collapsedSlotMinutes);
    // A nagyszombat 12:00-13:00 slotjai FOGLALTAK → NEM collapsed (a helyükön maradnak)
    expect(collapsed.has(720)).toBe(false);  // 12:00
    expect(collapsed.has(750)).toBe(false);  // 12:30
    // A körülötte lévő üres slotok IGEN collapsed → a tengely TÖRIK a mise körül
    expect(collapsed.has(690)).toBe(true);   // 11:30
    expect(collapsed.has(780)).toBe(true);   // 13:00
  });

  it('spanned: egy 45 perces mise MINDKÉT átfedett slotja foglalt (egyik sem collapsed)', () => {
    const result = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END,
      events: [
        ev(0, 7, 0, 8, 0),      // reggel
        ev(1, 10, 0, 10, 45),   // 10:00-10:45 → átfedi a 600 ÉS 630 slotot
        ev(2, 18, 0, 19, 0),    // este
      ],
    });
    expect(result.shouldCompress).toBe(true);
    const collapsed = new Set(result.collapsedSlotMinutes);
    expect(collapsed.has(600)).toBe(false);  // 10:00 - foglalt
    expect(collapsed.has(630)).toBe(false);  // 10:30 - a 45 perces mise átlóg ide
    expect(collapsed.has(660)).toBe(true);   // 11:00 - már üres
  });

  it('midnight-cross: az éjfélen átnyúló esemény a nap végi slotot foglalja', () => {
    const result = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END,
      events: [
        ev(0, 6, 0, 7, 0),        // reggel (globális min)
        ev(2, 9, 0, 10, 0),
        // 23:30-00:30 másnapra átnyúló (pl. éjféli mise) → a 23:30 slot foglalt
        ev(4, 23, 30, 0, 30, 'éjféli mise'),
      ],
    });
    expect(result.shouldCompress).toBe(true);
    const collapsed = new Set(result.collapsedSlotMinutes);
    expect(collapsed.has(1410)).toBe(false); // 23:30 - foglalt
    expect(collapsed.has(720)).toBe(true);   // 12:00 - üres középső
  });

  it('slotMin/Max: kora-hajnali esemény + padding → slot-igazított slotMinTime', () => {
    const result = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END,
      events: [
        ev(0, 6, 0, 7, 0, 'kora-hajnali'),  // ez a globális min
        ev(0, 9, 0, 10, 0),
        ev(1, 9, 0, 10, 0),
        ev(2, 18, 0, 19, 0),
        ev(3, 18, 0, 19, 0),
      ],
      options: {paddingHours: 0.5},  // 30 perc padding → slotMinTime = 05:30
    });
    expect(result.shouldCompress).toBe(true);
    expect(result.slotMinTime).toBe('05:30:00');
  });

  it('opciók: morningEndHour 11-re csökkentve elcsenheti a 11:30-as eseményt', () => {
    const result = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END,
      events: [
        ev(0, 8, 0, 9, 0),
        ev(0, 11, 0, 11, 30),  // 11:30 — morningEnd=11 esetén már NEM délelőtti
        ev(1, 18, 0, 19, 0),
        ev(2, 18, 30, 19, 30),
      ],
      options: {morningEndHour: 11, minEventsThreshold: 3},
    });
    // morningEndHour=11 esetén csak a 8:00-as esemény számít délelőttinek
    // (vége 9:00 ≤ 11:00). A 11:30 kívül esik.
    expect(result.shouldCompress).toBe(true);
    expect(result.diagnostics.gapStart).toBe('09:00');
  });

  it('összes esemény a gap-ben (egy szokatlan templom déli misékkel) → nincs gap, nem tömörít', () => {
    const result = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END,
      events: [
        ev(0, 12, 30, 13, 30),
        ev(1, 12, 30, 13, 30),
        ev(2, 13, 0, 14, 0),
      ],
    });
    expect(result.shouldCompress).toBe(false);
    expect(result.diagnostics.reason).toBe('no-gap-detected');
  });

  it('különböző napokon különböző órák — a global min/max-ot számoljuk', () => {
    const result = WeekCompressionUtil.analyze({
      weekStart: WEEK_START, weekEnd: WEEK_END,
      events: [
        ev(0, 7, 0, 8, 0),    // hétfő 7-8
        ev(2, 9, 30, 10, 30), // szerda 9:30-10:30 — ez a legkésőbbi reggeli
        ev(4, 17, 0, 18, 0),  // péntek 17-18 — ez a legkorábbi esti
        ev(6, 20, 0, 21, 0),  // vasárnap 20-21 — ez a globális max
      ],
    });
    expect(result.shouldCompress).toBe(true);
    expect(result.slotMinTime).toBe('07:00:00');
    expect(result.slotMaxTime).toBe('21:00:00');
    expect(result.diagnostics.gapStart).toBe('10:30');
    expect(result.diagnostics.gapEnd).toBe('17:00');
  });
});

describe('WeekCompressionUtil.minutesToTimeString', () => {
  it('00:00:00 a 0 percre', () => {
    expect(WeekCompressionUtil.minutesToTimeString(0)).toBe('00:00:00');
  });

  it('formattal a HH:MM:SS-t (másodperc mindig 00)', () => {
    expect(WeekCompressionUtil.minutesToTimeString(7 * 60)).toBe('07:00:00');
    expect(WeekCompressionUtil.minutesToTimeString(8 * 60 + 30)).toBe('08:30:00');
    expect(WeekCompressionUtil.minutesToTimeString(23 * 60 + 59)).toBe('23:59:00');
  });

  it('24:00:00 a felső határra', () => {
    expect(WeekCompressionUtil.minutesToTimeString(24 * 60)).toBe('24:00:00');
    expect(WeekCompressionUtil.minutesToTimeString(2000)).toBe('24:00:00');  // clamp
  });

  it('negatív érték 00:00:00-ra clamp-elve', () => {
    expect(WeekCompressionUtil.minutesToTimeString(-30)).toBe('00:00:00');
  });
});
