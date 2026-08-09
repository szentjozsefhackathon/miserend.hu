import {ComponentFixture, TestBed} from '@angular/core/testing';
import {of} from 'rxjs';
import {MAT_DIALOG_DATA, MatDialogRef} from '@angular/material/dialog';
import {provideTranslateService} from '@ngx-translate/core';

import {AddFullEventDialogComponent} from './add-full-event-dialog.component';
import {PeriodService} from '../../services/period.service';
import {GeneratedPeriod} from '../../model/generated-period';
import {Rite} from '../../enum/rites';
import {LanguageCode} from '../../enum/language-code';
import {Renum} from '../../enum/recurrence';
import {Day} from '../../enum/day';

function makeGeneratedPeriod(overrides: Partial<GeneratedPeriod> = {}): GeneratedPeriod {
  return {
    id: 1,
    periodId: 10,
    name: 'Évközi idő',
    weight: 1,
    startDate: '2026-01-01',
    endDate: '2026-12-31',
    color: '#ccc',
    ...overrides,
  };
}

// #450: a default-period tesztek ismétlődő misét igényelnek (EVERY_WEEK), mert
// egyszeri alkalom (Renum.NONE → singleEvent) NEM kap alapértelmezett időszakot.
// Az eventOverrides spread a renum után van, így egy teszt felül tudja írni.
function makeDialogData(
  periodOverride: GeneratedPeriod | null = null,
  existingPeriodIds: number[] = [],
  eventOverrides: Record<string, any> = {},
) {
  return {
    title: 'ADD_NEW_MASS',
    existingPeriodIds,
    event: {
      period: periodOverride,
      rite: Rite.ROMAN_CATHOLIC,
      types: [],
      title: 'Szentmise',
      start: new Date('2026-03-15T10:00:00'),
      duration: {hours: 1},
      language: LanguageCode.HU,
      renum: Renum.EVERY_WEEK,
      selectedDays: [Day.SU],
      comment: '',
      editOne: false,
      ...eventOverrides,
    },
  };
}

describe('AddFullEventDialogComponent (#308 default period)', () => {
  let component: AddFullEventDialogComponent;
  let fixture: ComponentFixture<AddFullEventDialogComponent>;
  let periodServiceMock: { getSelectableGeneratedPeriodsByDate: jasmine.Spy; getPeriodById: jasmine.Spy; getSpecialPeriodType: jasmine.Spy };

  async function setup(periods: GeneratedPeriod[], data = makeDialogData()) {
    periodServiceMock = {
      getSelectableGeneratedPeriodsByDate: jasmine.createSpy().and.returnValue(of(periods)),
      getPeriodById: jasmine.createSpy().and.returnValue(null),
      getSpecialPeriodType: jasmine.createSpy().and.returnValue(null),
    };

    await TestBed.configureTestingModule({
      imports: [AddFullEventDialogComponent],
      providers: [
        provideTranslateService(),
        {provide: MAT_DIALOG_DATA, useValue: data},
        {provide: MatDialogRef, useValue: {close: jasmine.createSpy()}},
        {provide: PeriodService, useValue: periodServiceMock},
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(AddFullEventDialogComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  }

  it('falls back to [0] when the church has no existing masses (new church / no overlap)', async () => {
    const relevant = makeGeneratedPeriod({id: 1, periodId: 10, name: 'Iskolaidő'});
    const other = makeGeneratedPeriod({id: 2, periodId: 11, name: 'Nyári szünet'});

    await setup([relevant, other]);

    expect(periodServiceMock.getSelectableGeneratedPeriodsByDate).toHaveBeenCalled();
    expect(component.periodCtr.value).toEqual(relevant);
  });

  it('does not overwrite an existing period selection (edit flow)', async () => {
    const preselected = makeGeneratedPeriod({id: 5, periodId: 50, name: 'Húsvéti idő'});
    const fromServer = makeGeneratedPeriod({id: 1, periodId: 10, name: 'Iskolaidő'});

    await setup([fromServer], makeDialogData(preselected, [10]));

    expect(component.periodCtr.value).toEqual(preselected);
  });

  it('leaves periodCtr null when no selectable periods exist', async () => {
    await setup([]);

    expect(component.periodCtr.value).toBeNull();
  });

  // #308 (borazslo review):
  it('prefers a sortable period the church already has a mass for, over the first sorted period', async () => {
    // Order from PeriodService reflects "May day weight": "Húsvéti idő" first (highest weight
    // for a date in the easter range), then "Iskolaidő" (school time, lower weight but valid).
    const easter      = makeGeneratedPeriod({id: 1, periodId: 50, name: 'Húsvéti idő',      weight: 10});
    const schoolTime  = makeGeneratedPeriod({id: 2, periodId: 10, name: 'Iskolaidő',        weight: 1});
    const summerBreak = makeGeneratedPeriod({id: 3, periodId: 11, name: 'Nyári szünet',     weight: 1});

    // The church already has a Iskolaidő (10) mass — Wednesday addition should default to
    // Iskolaidő, NOT Húsvéti idő, even though Húsvéti idő is the first in the sorted list.
    await setup([easter, schoolTime, summerBreak], makeDialogData(null, [10]));

    expect(component.periodCtr.value).toEqual(schoolTime);
  });

  it('walks the sorted list in order: picks the first existing period, not the highest existingPeriodId', async () => {
    // existingPeriodIds includes both 50 and 11, but the sorted order is [10, 11, 50].
    // We must pick 11, the FIRST existing in sort order, NOT 50.
    const p10 = makeGeneratedPeriod({id: 1, periodId: 10, name: 'A',  weight: 3});
    const p11 = makeGeneratedPeriod({id: 2, periodId: 11, name: 'B',  weight: 2});
    const p50 = makeGeneratedPeriod({id: 3, periodId: 50, name: 'C',  weight: 1});

    await setup([p10, p11, p50], makeDialogData(null, [50, 11]));

    expect(component.periodCtr.value).toEqual(p11);
  });

  it('falls back to [0] when none of the existing periods match the sorted list', async () => {
    // The church has miséket period 999, but 999 isn't in this day's selectable list.
    // Should fall back to the original behaviour: [0].
    const fromServer = makeGeneratedPeriod({id: 1, periodId: 10, name: 'Iskolaidő'});

    await setup([fromServer], makeDialogData(null, [999]));

    expect(component.periodCtr.value).toEqual(fromServer);
  });

  it('synchronously syncs data.event.period when prefilling (avoids save-race)', async () => {
    const evkozi = makeGeneratedPeriod({id: 1, periodId: 10, name: 'Iskolaidő'});

    await setup([evkozi]);

    expect(component.periodCtr.value).toEqual(evkozi);
    expect(component.data.event.period).toEqual(evkozi as any);
  });

  it('onSave defensively syncs data.event.period from periodCtr if they drifted apart', async () => {
    const evkozi = makeGeneratedPeriod({id: 1, periodId: 10});
    await setup([evkozi]);

    (component.data.event as any).period = null;
    component.singleEvent = false;

    component.onSave();

    expect(component.data.event.period).toEqual(evkozi as any);
    expect(component.dialogRef.close).toHaveBeenCalled();
  });

  it('handles missing existingPeriodIds (undefined) like an empty list — fallback to [0]', async () => {
    const p10 = makeGeneratedPeriod({id: 1, periodId: 10, name: 'Iskolaidő'});

    // DialogData without existingPeriodIds key at all (legacy callers / safety net).
    // #450: ismétlődő mise, hogy a default-period ág lefusson.
    const data = {
      title: 'ADD_NEW_MASS',
      event: {
        period: null,
        rite: Rite.ROMAN_CATHOLIC,
        types: [],
        title: 'Szentmise',
        start: new Date('2026-03-15T10:00:00'),
        duration: {hours: 1},
        language: LanguageCode.HU,
        renum: Renum.EVERY_WEEK,
        selectedDays: [Day.SU],
        comment: '',
        editOne: false,
      },
    };

    await setup([p10], data as any);

    expect(component.periodCtr.value).toEqual(p10);
  });
});

describe('AddFullEventDialogComponent (#428 manual experiod selector)', () => {
  let component: AddFullEventDialogComponent;
  let fixture: ComponentFixture<AddFullEventDialogComponent>;
  let periodServiceMock: {
    getSelectableGeneratedPeriodsByDate: jasmine.Spy;
    getPeriodById: jasmine.Spy;
    getSpecialPeriodType: jasmine.Spy;
  };

  async function setup(periods: GeneratedPeriod[], data = makeDialogData()) {
    periodServiceMock = {
      getSelectableGeneratedPeriodsByDate: jasmine.createSpy().and.returnValue(of(periods)),
      getPeriodById: jasmine.createSpy().and.returnValue(null),
      getSpecialPeriodType: jasmine.createSpy().and.returnValue(null),
    };

    await TestBed.configureTestingModule({
      imports: [AddFullEventDialogComponent],
      providers: [
        provideTranslateService(),
        {provide: MAT_DIALOG_DATA, useValue: data},
        {provide: MatDialogRef, useValue: {close: jasmine.createSpy()}},
        {provide: PeriodService, useValue: periodServiceMock},
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(AddFullEventDialogComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  }

  it('initializes experiodCtr empty when no manualExperiod is preset', async () => {
    await setup([makeGeneratedPeriod()]);

    expect(component.experiodCtr.value).toEqual([]);
  });

  it('initializes experiodCtr from data.event.manualExperiod on edit', async () => {
    const data = makeDialogData(null, [], {manualExperiod: [11, 12]});

    await setup([makeGeneratedPeriod()], data);

    expect(component.experiodCtr.value).toEqual([11, 12]);
  });

  it('writes experiodCtr changes back to data.event.manualExperiod', async () => {
    await setup([makeGeneratedPeriod()]);

    component.experiodCtr.setValue([20, 30]);

    expect(component.data.event.manualExperiod).toEqual([20, 30]);
  });

  it('writes null to data.event.manualExperiod when selection is cleared', async () => {
    const data = makeDialogData(null, [], {manualExperiod: [11]});
    await setup([makeGeneratedPeriod()], data);

    component.experiodCtr.setValue([]);

    expect(component.data.event.manualExperiod).toBeNull();
  });

  it('experiodOptions excludes the currently selected mass period', async () => {
    const evkozi = makeGeneratedPeriod({id: 1, periodId: 10, name: 'Évközi'});
    const nyari  = makeGeneratedPeriod({id: 2, periodId: 11, name: 'Nyári szünet'});
    const oszi   = makeGeneratedPeriod({id: 3, periodId: 12, name: 'Őszi szünet'});

    await setup([evkozi, nyari, oszi]);
    // Simulate the user picking the "Évközi" (periodId=10) as the mass period.
    component.periodCtr.setValue(evkozi);

    const optionPeriodIds = component.experiodOptions.map(p => p.periodId);
    expect(optionPeriodIds).not.toContain(10);
    expect(optionPeriodIds).toContain(11);
    expect(optionPeriodIds).toContain(12);
  });

  it('experiodOptions returns all periods when no mass period is selected', async () => {
    const evkozi = makeGeneratedPeriod({id: 1, periodId: 10});
    const nyari  = makeGeneratedPeriod({id: 2, periodId: 11});

    await setup([evkozi, nyari]);
    // #308 óta a periodCtr auto-fill-elődik az első elérhető periódusra,
    // ezért a „nincs kiválasztva" ágat explicit reset-tel hozzuk létre.
    component.periodCtr.setValue(null);

    expect(component.experiodOptions.length).toBe(2);
  });

  it('removes a period from manualExperiod if the user later selects it as the mass period', async () => {
    const evkozi = makeGeneratedPeriod({id: 1, periodId: 10, name: 'Évközi'});
    const nyari  = makeGeneratedPeriod({id: 2, periodId: 11, name: 'Nyári szünet'});
    const data = makeDialogData(null, [], {manualExperiod: [11]});

    await setup([evkozi, nyari], data);

    // Sanity: nyari (11) is in the manual exclusion list
    expect(component.experiodCtr.value).toContain(11);

    // User switches mass period to nyari
    component.periodCtr.setValue(nyari);

    // The "exclude yourself" guard kicks in
    expect(component.experiodCtr.value).not.toContain(11);
  });
});

describe('AddFullEventDialogComponent (#450 egyszeri alkalom nem kap időszakot)', () => {
  let component: AddFullEventDialogComponent;
  let fixture: ComponentFixture<AddFullEventDialogComponent>;
  let periodServiceMock: { getSelectableGeneratedPeriodsByDate: jasmine.Spy; getPeriodById: jasmine.Spy; getSpecialPeriodType: jasmine.Spy };

  async function setup(periods: GeneratedPeriod[], data: any) {
    periodServiceMock = {
      getSelectableGeneratedPeriodsByDate: jasmine.createSpy().and.returnValue(of(periods)),
      getPeriodById: jasmine.createSpy().and.returnValue(null),
      getSpecialPeriodType: jasmine.createSpy().and.returnValue(null),
    };

    await TestBed.configureTestingModule({
      imports: [AddFullEventDialogComponent],
      providers: [
        provideTranslateService(),
        {provide: MAT_DIALOG_DATA, useValue: data},
        {provide: MatDialogRef, useValue: {close: jasmine.createSpy()}},
        {provide: PeriodService, useValue: periodServiceMock},
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(AddFullEventDialogComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  }

  // #450: egyszeri alkalomnál (renum === NONE) NEM szabad alapértelmezett
  // időszakot rendelni — ez okozta, hogy az időszak első napjára került az esemény.
  it('does NOT assign a default period for a single event (renum NONE), even if periods exist', async () => {
    const evkozi = makeGeneratedPeriod({id: 1, periodId: 10, name: 'Iskolaidő'});

    await setup([evkozi], makeDialogData(null, [10], {renum: Renum.NONE}));

    expect(component.singleEvent).toBeTrue();
    expect(component.periodCtr.value).toBeNull();
    expect(component.data.event.period).toBeFalsy();
  });

  // #450: ha a felhasználó ismétlődőről egyszerire vált (onRecurrenceModChange),
  // a korábban beállított időszakot ki kell üríteni.
  it('clears the period when switching from recurring to single (onRecurrenceModChange)', async () => {
    const evkozi = makeGeneratedPeriod({id: 1, periodId: 10, name: 'Iskolaidő'});

    await setup([evkozi], makeDialogData(null, [10], {renum: Renum.EVERY_WEEK}));
    expect(component.periodCtr.value).toEqual(evkozi);

    component.singleEvent = true;
    component.onRecurrenceModChange();

    expect(component.data.event.period).toBeNull();
    expect(component.periodCtr.value).toBeNull();
  });
});

describe('AddFullEventDialogComponent (#453 havi-n.-napja nap visszatöltése)', () => {
  let component: AddFullEventDialogComponent;
  let fixture: ComponentFixture<AddFullEventDialogComponent>;

  // #453: létező mise szerkesztéskor a selectedDays a mentett byweekday TÖMBJÉBŐL
  // jön. A teszt-data ezt szimulálja egy adott renum + selectedDays párral.
  function dataWith(renum: Renum, selectedDays: any) {
    return {
      title: 'EDIT_MASS',
      existingPeriodIds: [],
      event: {
        period: null,
        rite: Rite.ROMAN_CATHOLIC,
        types: [],
        title: 'Szentmise',
        start: new Date('2026-03-15T10:00:00'),
        duration: {hours: 1},
        language: LanguageCode.HU,
        renum,
        selectedDays,
        comment: '',
        editOne: false,
      },
    };
  }

  async function setup(data: any) {
    const periodServiceMock = {
      getSelectableGeneratedPeriodsByDate: jasmine.createSpy().and.returnValue(of([])),
      getPeriodById: jasmine.createSpy().and.returnValue(null),
      getSpecialPeriodType: jasmine.createSpy().and.returnValue(null),
    };

    await TestBed.configureTestingModule({
      imports: [AddFullEventDialogComponent],
      providers: [
        provideTranslateService(),
        {provide: MAT_DIALOG_DATA, useValue: data},
        {provide: MatDialogRef, useValue: {close: jasmine.createSpy()}},
        {provide: PeriodService, useValue: periodServiceMock},
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(AddFullEventDialogComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  }

  // #453: havi n. napja (FOURTH_WEEK) — a byweekday tömbből (['MO']) a single-day
  // mat-select EGYETLEN 'MO'-ra normalizálódik, így a mező nem marad üresen.
  it('normalizes selectedDays from array to single value for a monthly nth-weekday recurrence', async () => {
    await setup(dataWith(Renum.FOURTH_WEEK, [Day.MO]));

    expect(Array.isArray(component.selectedDays)).toBeFalse();
    expect(component.selectedDays).toBe(Day.MO);
  });

  // #453 regresszió-védelem: heti (több napos) misénél a tömb ÉRINTETLEN marad
  // — a normalizálás nem dobhat el napokat.
  it('keeps the array intact for a weekly multi-day recurrence', async () => {
    await setup(dataWith(Renum.EVERY_WEEK, [Day.MO, Day.WE]));

    expect(Array.isArray(component.selectedDays)).toBeTrue();
    expect(component.selectedDays).toEqual([Day.MO, Day.WE]);
  });
});

describe('AddFullEventDialogComponent (#458 szerkesztéskor nincs dátum-alapú default időszak)', () => {
  let component: AddFullEventDialogComponent;
  let fixture: ComponentFixture<AddFullEventDialogComponent>;

  function editData(title: string) {
    return {
      title,
      existingPeriodIds: [],
      event: {
        period: null,                 // a hívó nem oldotta fel (generatedPeriods$-ban épp nincs)
        rite: Rite.ROMAN_CATHOLIC,
        types: [],
        title: 'Szentmise',
        start: new Date('2026-01-01T08:00:00'),
        duration: {hours: 1},
        language: LanguageCode.HU,
        renum: Renum.EVERY_WEEK,       // ismétlődő → NEM singleEvent
        selectedDays: [Day.TU, Day.TH],
        comment: '',
        editOne: false,
      },
    };
  }

  async function setup(periods: GeneratedPeriod[], data: any) {
    const periodServiceMock = {
      getSelectableGeneratedPeriodsByDate: jasmine.createSpy().and.returnValue(of(periods)),
      getPeriodById: jasmine.createSpy().and.returnValue(null),
      getSpecialPeriodType: jasmine.createSpy().and.returnValue(null),
    };

    await TestBed.configureTestingModule({
      imports: [AddFullEventDialogComponent],
      providers: [
        provideTranslateService(),
        {provide: MAT_DIALOG_DATA, useValue: data},
        {provide: MatDialogRef, useValue: {close: jasmine.createSpy()}},
        {provide: PeriodService, useValue: periodServiceMock},
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(AddFullEventDialogComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  }

  // #458: létező mise szerkesztésekor (EDIT_MASS) NEM szabad dátum-alapú default
  // időszakot találgatni, ha a period nincs feloldva — különben pl. a „Téli idő"
  // jelenik meg egy egész-éves mise helyett.
  it('does NOT auto-pick a date-based period when editing an existing mass (EDIT_MASS)', async () => {
    const teli = makeGeneratedPeriod({id: 7, periodId: 7, name: 'Téli időszak', weight: 5});
    const evesEv = makeGeneratedPeriod({id: 10, periodId: 10, name: 'Egész évben', weight: 1});

    await setup([teli, evesEv], editData('EDIT_MASS'));

    expect(component.periodCtr.value).toBeNull();
    expect(component.data.event.period).toBeFalsy();
  });

  // Kontroll: ÚJ mise létrehozásakor (ADD_NEW_MASS) az auto-default továbbra is fut
  // — a #458 fix nem rontja el a #308 viselkedést.
  it('still auto-picks a default period when creating a NEW mass (ADD_NEW_MASS)', async () => {
    const teli = makeGeneratedPeriod({id: 7, periodId: 7, name: 'Téli időszak', weight: 5});

    await setup([teli], editData('ADD_NEW_MASS'));

    expect(component.periodCtr.value).toEqual(teli);
  });
});
