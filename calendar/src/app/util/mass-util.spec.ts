import { TestBed } from '@angular/core/testing';
import { provideTranslateService, TranslateService } from '@ngx-translate/core';
import { MassUtil } from './mass-util';
import { MassTitleCategory } from '../enum/mass-categories';
import { MASS_DEFINITIONS_DATA } from '../data/mass-definitions';
import { Rite } from '../enum/rites';
import {DialogEvent} from '../model/dialog-event';
import {CalendarEvent} from '../model/calendar/calendar-event';
import {Church} from '../model/church';
import {LanguageCode} from '../enum/language-code';
import {Renum} from '../enum/recurrence';
import {Day} from '../enum/day';
import {Mass} from '../model/mass';
import {GeneratedPeriod} from '../model/generated-period';

describe('MassUtil - Category Classification', () => {
  let translate: TranslateService;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      providers: [provideTranslateService({lang: 'hu'})]
    }).compileComponents();

    translate = TestBed.inject(TranslateService);
  });

  describe('getCategoryByTitle', () => {
    it('should be a function', () => {
      expect(typeof MassUtil.getCategoryByTitle).toBe('function');
    });

    it('should return a MassTitleCategory', () => {
      const category = MassUtil.getCategoryByTitle('HOLY_MASS', translate);
      
      expect([MassTitleCategory.MASS, MassTitleCategory.ADORATION, MassTitleCategory.CONFESSION, MassTitleCategory.OTHER])
        .toContain(category);
    });

    it('should categorize HOLY_MASS as MASS', () => {
      const category = MassUtil.getCategoryByTitle('HOLY_MASS', translate);
      
      expect(category).toBe(MassTitleCategory.MASS);
    });

    it('should categorize ADORATION as ADORATION', () => {
      const category = MassUtil.getCategoryByTitle('ADORATION', translate);
      
      expect(category).toBe(MassTitleCategory.ADORATION);
    });

    it('should categorize CONFESSION as CONFESSION', () => {
      const category = MassUtil.getCategoryByTitle('CONFESSION', translate);
      
      expect(category).toBe(MassTitleCategory.CONFESSION);
    });

    it('should categorize BREVIARY as OTHER', () => {
      const category = MassUtil.getCategoryByTitle('BREVIARY', translate);
      
      expect(category).toBe(MassTitleCategory.OTHER);
    });

    it('should categorize ROSARY as OTHER', () => {
      const category = MassUtil.getCategoryByTitle('ROSARY', translate);
      
      expect(category).toBe(MassTitleCategory.OTHER);
    });

    it('should categorize LITANY as OTHER', () => {
      const category = MassUtil.getCategoryByTitle('LITANY', translate);
      
      expect(category).toBe(MassTitleCategory.OTHER);
    });

    it('should handle empty title', () => {
      const category = MassUtil.getCategoryByTitle('', translate);
      
      expect(category).toBe(MassTitleCategory.OTHER);
    });

    it('should handle undefined translate service gracefully', () => {
      const category = MassUtil.getCategoryByTitle('HOLY_MASS');
      
      expect([MassTitleCategory.MASS, MassTitleCategory.ADORATION, MassTitleCategory.CONFESSION, MassTitleCategory.OTHER])
        .toContain(category);
    });
  });

  describe('getTitlesByCategory', () => {
    it('should return array of title strings for MASS category', () => {
      const titles = MassUtil.getTitlesByCategory(MassTitleCategory.MASS);
      
      expect(Array.isArray(titles)).toBe(true);
      expect(titles.length).toBeGreaterThan(0);
    });

    it('should return array of title strings for ADORATION category', () => {
      const titles = MassUtil.getTitlesByCategory(MassTitleCategory.ADORATION);
      
      expect(Array.isArray(titles)).toBe(true);
      expect(titles.length).toBeGreaterThan(0);
    });

    it('should return array of title strings for CONFESSION category', () => {
      const titles = MassUtil.getTitlesByCategory(MassTitleCategory.CONFESSION);
      
      expect(Array.isArray(titles)).toBe(true);
      expect(titles.length).toBeGreaterThan(0);
    });

    it('should return array of title strings for OTHER category', () => {
      const titles = MassUtil.getTitlesByCategory(MassTitleCategory.OTHER);
      
      expect(Array.isArray(titles)).toBe(true);
      expect(titles.length).toBeGreaterThan(0);
    });

    it('MASS category should contain HOLY_MASS', () => {
      const titles = MassUtil.getTitlesByCategory(MassTitleCategory.MASS);
      
      expect(titles).toContain('HOLY_MASS');
    });

    it('ADORATION category should contain ADORATION', () => {
      const titles = MassUtil.getTitlesByCategory(MassTitleCategory.ADORATION);
      
      expect(titles).toContain('ADORATION');
    });

    it('CONFESSION category should contain CONFESSION', () => {
      const titles = MassUtil.getTitlesByCategory(MassTitleCategory.CONFESSION);
      
      expect(titles).toContain('CONFESSION');
    });

    it('OTHER category should contain BREVIARY, ROSARY, LITANY', () => {
      const titles = MassUtil.getTitlesByCategory(MassTitleCategory.OTHER);
      
      expect(titles).toContain('BREVIARY');
      expect(titles).toContain('ROSARY');
      expect(titles).toContain('LITANY');
    });
  });

  describe('getAllCategories', () => {
    it('should return array of all categories', () => {
      const categories = MassUtil.getAllCategories();
      
      expect(Array.isArray(categories)).toBe(true);
      expect(categories.length).toBeGreaterThan(0);
    });

    it('should include MASS, ADORATION, CONFESSION, and OTHER', () => {
      const categories = MassUtil.getAllCategories();
      
      expect(categories).toContain(MassTitleCategory.MASS);
      expect(categories).toContain(MassTitleCategory.ADORATION);
      expect(categories).toContain(MassTitleCategory.CONFESSION);
      expect(categories).toContain(MassTitleCategory.OTHER);
    });
  });

  describe('getColorByCategory', () => {
    it('should return hex color for MASS category', () => {
      const color = MassUtil.getColorByCategory(MassTitleCategory.MASS);
      
      expect(color).toBeDefined();
      expect(color).toMatch(/^#[0-9A-Fa-f]{6}$/);
    });

    it('should return hex color for ADORATION category', () => {
      const color = MassUtil.getColorByCategory(MassTitleCategory.ADORATION);
      
      expect(color).toBeDefined();
      expect(color).toMatch(/^#[0-9A-Fa-f]{6}$/);
    });

    it('should return hex color for CONFESSION category', () => {
      const color = MassUtil.getColorByCategory(MassTitleCategory.CONFESSION);
      
      expect(color).toBeDefined();
      expect(color).toMatch(/^#[0-9A-Fa-f]{6}$/);
    });

    it('should return hex color for OTHER category', () => {
      const color = MassUtil.getColorByCategory(MassTitleCategory.OTHER);
      
      expect(color).toBeDefined();
      expect(color).toMatch(/^#[0-9A-Fa-f]{6}$/);
    });

    it('should return different colors for different categories', () => {
      const colorMass = MassUtil.getColorByCategory(MassTitleCategory.MASS);
      const colorAdoration = MassUtil.getColorByCategory(MassTitleCategory.ADORATION);
      const colorConfession = MassUtil.getColorByCategory(MassTitleCategory.CONFESSION);
      const colorOther = MassUtil.getColorByCategory(MassTitleCategory.OTHER);

      expect(colorMass).not.toBe(colorAdoration);
      expect(colorMass).not.toBe(colorConfession);
      expect(colorMass).not.toBe(colorOther);
      expect(colorAdoration).not.toBe(colorConfession);
      expect(colorAdoration).not.toBe(colorOther);
      expect(colorConfession).not.toBe(colorOther);
    });
  });

  describe('Integration: Category assignment in calendar events', () => {
    it('should correctly assign MASS category to HOLY_MASS events', (done) => {
      translate.get('MASS_TITLE.HOLY_MASS').subscribe(() => {
        const category = MassUtil.getCategoryByTitle('HOLY_MASS', translate);
        const color = MassUtil.getColorByCategory(category);
        
        expect(category).toBe(MassTitleCategory.MASS);
        expect(color).toBeDefined();
        done();
      });
    });

    it('should correctly assign ADORATION category to ADORATION events', (done) => {
      translate.get('MASS_TITLE.ADORATION').subscribe(() => {
        const category = MassUtil.getCategoryByTitle('ADORATION', translate);
        const color = MassUtil.getColorByCategory(category);
        
        expect(category).toBe(MassTitleCategory.ADORATION);
        expect(color).toBeDefined();
        done();
      });
    });

    it('should correctly assign CONFESSION category to CONFESSION events', (done) => {
      translate.get('MASS_TITLE.CONFESSION').subscribe(() => {
        const category = MassUtil.getCategoryByTitle('CONFESSION', translate);
        const color = MassUtil.getColorByCategory(category);
        
        expect(category).toBe(MassTitleCategory.CONFESSION);
        expect(color).toBeDefined();
        done();
      });
    });

    it('should correctly assign OTHER category to BREVIARY events', (done) => {
      translate.get('MASS_TITLE.BREVIARY').subscribe(() => {
        const category = MassUtil.getCategoryByTitle('BREVIARY', translate);
        const color = MassUtil.getColorByCategory(category);
        
        expect(category).toBe(MassTitleCategory.OTHER);
        expect(color).toBeDefined();
        done();
      });
    });

    it('should not have all events assigned to OTHER (main bug check)', (done) => {
      translate.get('MASS_TITLE.HOLY_MASS').subscribe(() => {
        const categoryMass = MassUtil.getCategoryByTitle('HOLY_MASS', translate);
        const categoryAdoration = MassUtil.getCategoryByTitle('ADORATION', translate);
        const categoryConfession = MassUtil.getCategoryByTitle('CONFESSION', translate);
        
        // This is the key test - not all should be OTHER
        const allOther = 
          categoryMass === MassTitleCategory.OTHER && 
          categoryAdoration === MassTitleCategory.OTHER && 
          categoryConfession === MassTitleCategory.OTHER;
        
        expect(allOther).toBe(false);
        
        done();
      });
    });

    it('should categorize all MASS_DEFINITIONS_DATA definitions correctly', (done) => {
      translate.get('MASS_TITLE.HOLY_MASS').subscribe(() => {
        const definitions = MASS_DEFINITIONS_DATA.definitions;
        
        definitions.forEach(definition => {
          const detectedCategory = MassUtil.getCategoryByTitle(definition.key, translate);
          
          // Should successfully categorize, not all as OTHER
          expect(detectedCategory).toBeDefined();
        });
        
        done();
      });
    });
  });

  describe('Regression: Category filter behavior', () => {
    it('should allow filtering by MASS category with multiple events', (done) => {
      translate.get('MASS_TITLE.HOLY_MASS').subscribe(() => {
        const massTitles = MassUtil.getTitlesByCategory(MassTitleCategory.MASS);
        const massCategories = massTitles.map(title => 
          MassUtil.getCategoryByTitle(title, translate)
        );
        
        // All should be MASS category
        expect(massCategories.every(cat => cat === MassTitleCategory.MASS)).toBe(true);
        done();
      });
    });

    it('should allow filtering by ADORATION category', (done) => {
      translate.get('MASS_TITLE.ADORATION').subscribe(() => {
        const adorationTitles = MassUtil.getTitlesByCategory(MassTitleCategory.ADORATION);
        const adorationCategories = adorationTitles.map(title => 
          MassUtil.getCategoryByTitle(title, translate)
        );
        
        // All should be ADORATION category
        expect(adorationCategories.every(cat => cat === MassTitleCategory.ADORATION)).toBe(true);
        done();
      });
    });

    it('should allow filtering by CONFESSION category', (done) => {
      translate.get('MASS_TITLE.CONFESSION').subscribe(() => {
        const confessionTitles = MassUtil.getTitlesByCategory(MassTitleCategory.CONFESSION);
        const confessionCategories = confessionTitles.map(title => 
          MassUtil.getCategoryByTitle(title, translate)
        );
        
        // All should be CONFESSION category
        expect(confessionCategories.every(cat => cat === MassTitleCategory.CONFESSION)).toBe(true);
        done();
      });
    });

    it('should allow filtering by OTHER category', (done) => {
      translate.get('MASS_TITLE.BREVIARY').subscribe(() => {
        const otherTitles = MassUtil.getTitlesByCategory(MassTitleCategory.OTHER);
        const otherCategories = otherTitles.map(title => 
          MassUtil.getCategoryByTitle(title, translate)
        );
        
        // All should be OTHER category
        expect(otherCategories.every(cat => cat === MassTitleCategory.OTHER)).toBe(true);
        done();
      });
    });
  });
});

function makeChurch(): Church {
  return {
    id: 999,
    rite: Rite.ROMAN_CATHOLIC,
  } as Church;
}

function makeCalendarEvent(): CalendarEvent {
  return {
    title: 'Szentmise',
    rrule: {
      dtstart: '2026-03-01T07:00:00',
      freq: 'weekly',
    },
  } as CalendarEvent;
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

describe('MassUtil.createMass (#428 manual experiod plumb-through)', () => {

  // #428: a dialógus kézi kivétel-választása a Mass.manualExperiod-be megy (NEM az
  // auto experiod-ba), hogy a mentéskori automatikák ne törölhessék ki.

  it('passes dialogEvent.manualExperiod through to the Mass.manualExperiod', () => {
    const dialogEvent = makeDialogEvent({manualExperiod: [11, 12, 13]});

    const mass = MassUtil.createMass(makeCalendarEvent(), dialogEvent, makeChurch(), 1);

    expect(mass.manualExperiod).toEqual([11, 12, 13]);
  });

  it('does NOT write the manual selection into the auto experiod field', () => {
    const dialogEvent = makeDialogEvent({manualExperiod: [11]});

    const mass = MassUtil.createMass(makeCalendarEvent(), dialogEvent, makeChurch(), 1);

    expect(mass.experiod).toBeUndefined();
  });

  it('does not set manualExperiod on the Mass when dialogEvent has none', () => {
    const dialogEvent = makeDialogEvent({manualExperiod: null});

    const mass = MassUtil.createMass(makeCalendarEvent(), dialogEvent, makeChurch(), 1);

    expect(mass.manualExperiod).toBeUndefined();
  });

  it('does not set manualExperiod when dialogEvent.manualExperiod is an empty array', () => {
    const dialogEvent = makeDialogEvent({manualExperiod: []});

    const mass = MassUtil.createMass(makeCalendarEvent(), dialogEvent, makeChurch(), 1);

    expect(mass.manualExperiod).toBeUndefined();
  });

  it('clones the manualExperiod array so later mutations on the dialog do not leak into the Mass', () => {
    const original = [11];
    const dialogEvent = makeDialogEvent({manualExperiod: original});

    const mass = MassUtil.createMass(makeCalendarEvent(), dialogEvent, makeChurch(), 1);
    original.push(99);

    expect(mass.manualExperiod).toEqual([11]);
  });
});

describe('MassUtil.getEffectiveExperiod (#428 auto ∪ manual union)', () => {

  it('unions the auto experiod with the manual experiod', () => {
    const mass = {periodId: 10, experiod: [11], manualExperiod: [7]} as Mass;

    expect(MassUtil.getEffectiveExperiod(mass).sort((a, b) => a - b)).toEqual([7, 11]);
  });

  it('deduplicates ids present in both arrays', () => {
    const mass = {periodId: 10, experiod: [11], manualExperiod: [11]} as Mass;

    expect(MassUtil.getEffectiveExperiod(mass)).toEqual([11]);
  });

  it('returns manual-only exclusions even when auto experiod is null (the #428 core case)', () => {
    const mass = {periodId: 10, experiod: null, manualExperiod: [11]} as Mass;

    expect(MassUtil.getEffectiveExperiod(mass)).toEqual([11]);
  });

  it('never excludes the mass own period', () => {
    const mass = {periodId: 11, experiod: [11], manualExperiod: [11]} as Mass;

    expect(MassUtil.getEffectiveExperiod(mass)).toEqual([]);
  });

  it('returns an empty array when both are null', () => {
    const mass = {periodId: 10, experiod: null, manualExperiod: null} as Mass;

    expect(MassUtil.getEffectiveExperiod(mass)).toEqual([]);
  });
});

describe('MassUtil.createCalendarEvent (#428 manual experiod hides dates)', () => {

  function makeGenPeriod(overrides: Partial<GeneratedPeriod> = {}): GeneratedPeriod {
    return {
      id: 1, periodId: 10, name: 'Évközi', weight: 1,
      startDate: '2026-01-01', endDate: '2027-01-01', color: '#fff',
      ...overrides,
    };
  }

  it('produces an exrule from manualExperiod alone (auto experiod is null)', () => {
    const yearPeriod = makeGenPeriod({id: 1, periodId: 10});
    const summerPeriod = makeGenPeriod({id: 2, periodId: 11, name: 'Nyári szünet',
      startDate: '2026-06-15', endDate: '2026-09-01'});
    const mass = {
      title: 'Szentmise', periodId: 10, experiod: null, manualExperiod: [11],
      rrule: {dtstart: '2026-03-01T07:00:00', until: '2027-01-01', freq: 'weekly'},
    } as unknown as Mass;

    const events = MassUtil.createCalendarEvent(mass, [yearPeriod, summerPeriod]);

    expect(events.length).toBeGreaterThan(0);
    expect(events[0].exrule).toBeDefined();
    expect(events[0].exrule!.length).toBeGreaterThan(0);
  });
});

describe('MassUtil.getRenumByMass', () => {
  const massWithRrule = (rrule: Mass['rrule']): Mass => ({
    id: 1, churchId: 100, title: 'Szentmise', rite: Rite.ROMAN_CATHOLIC,
    startDate: '2026-03-01T07:00:00', lang: 'hu', rrule,
  });

  it('nincs rrule -> NONE', () => {
    expect(MassUtil.getRenumByMass(massWithRrule(null))).toBe(Renum.NONE);
  });

  it('yearly -> YEARLY', () => {
    expect(MassUtil.getRenumByMass(massWithRrule({dtstart: '2026-03-01T07:00:00', freq: 'yearly'}))).toBe(Renum.YEARLY);
  });

  it('weekly byweekno nélkül -> EVERY_WEEK', () => {
    expect(MassUtil.getRenumByMass(massWithRrule({dtstart: '2026-03-01T07:00:00', freq: 'weekly'}))).toBe(Renum.EVERY_WEEK);
  });

  it('weekly páros byweekno -> EVEN_WEEK', () => {
    expect(MassUtil.getRenumByMass(massWithRrule({dtstart: '2026-03-01T07:00:00', freq: 'weekly', byweekno: [2, 4, 6]}))).toBe(Renum.EVEN_WEEK);
  });

  it('weekly páratlan byweekno -> ODD_WEEK', () => {
    expect(MassUtil.getRenumByMass(massWithRrule({dtstart: '2026-03-01T07:00:00', freq: 'weekly', byweekno: [1, 3, 5]}))).toBe(Renum.ODD_WEEK);
  });
});

/**
 * #334: több nyelven bemutatott mise (szlovák-latin, német-magyar). A backend a `lang`
 * mezőben vesszővel elválasztva tárolja a kódokat, a szerkesztő viszont listát kezel.
 */
describe('MassUtil nyelvlista (#334)', () => {

  it('egyetlen nyelvből egyelemű lista lesz', () => {
    expect(MassUtil.languageCodes('sk')).toEqual([LanguageCode.SK]);
  });

  it('vesszős listát szétbont', () => {
    expect(MassUtil.languageCodes('sk,va')).toEqual([LanguageCode.SK, LanguageCode.VA]);
  });

  it('szóközöket tűr a vesszők körül', () => {
    expect(MassUtil.languageCodes(' de , hu ')).toEqual([LanguageCode.DE, LanguageCode.HU]);
  });

  it('ismeretlen kódot eldob', () => {
    expect(MassUtil.languageCodes('sk,xx')).toEqual([LanguageCode.SK]);
  });

  it('üres vagy csupa ismeretlen érték esetén magyarra esik vissza', () => {
    expect(MassUtil.languageCodes('')).toEqual([LanguageCode.HU]);
    expect(MassUtil.languageCodes(null)).toEqual([LanguageCode.HU]);
    expect(MassUtil.languageCodes(undefined)).toEqual([LanguageCode.HU]);
    expect(MassUtil.languageCodes('xx,yy')).toEqual([LanguageCode.HU]);
  });

  it('duplikátumot nem ad vissza kétszer', () => {
    expect(MassUtil.languageCodes('hu,hu,de')).toEqual([LanguageCode.HU, LanguageCode.DE]);
  });

  it('vissza is alakítja a backend formájára', () => {
    expect(MassUtil.languageCodesToLang([LanguageCode.SK, LanguageCode.VA])).toBe('sk,va');
    expect(MassUtil.languageCodesToLang([LanguageCode.HU])).toBe('hu');
  });

  it('üres kiválasztásból magyar lesz — a mise nyelve nem maradhat üresen', () => {
    expect(MassUtil.languageCodesToLang([])).toBe('hu');
    expect(MassUtil.languageCodesToLang(null)).toBe('hu');
  });

  it('oda-vissza alakítás megőrzi a nyelveket', () => {
    const codes = MassUtil.languageCodes('sk,va');
    expect(MassUtil.languageCodes(MassUtil.languageCodesToLang(codes))).toEqual(codes);
  });
});
