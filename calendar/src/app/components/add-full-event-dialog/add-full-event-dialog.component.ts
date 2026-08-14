import {ChangeDetectionStrategy, ChangeDetectorRef, Component, inject} from '@angular/core';
import {MAT_DIALOG_DATA, MatDialogModule, MatDialogRef} from '@angular/material/dialog';
import {MatButtonModule} from '@angular/material/button';
import {DialogData} from '../church-calendar/church-calendar.component';
import {MatInputModule} from '@angular/material/input';
import {FormControl, FormsModule, ReactiveFormsModule} from '@angular/forms';
import {MatFormFieldModule} from '@angular/material/form-field';
import {MatMenuModule} from '@angular/material/menu';
import {MatDatepickerModule} from '@angular/material/datepicker';
import {MatTimepickerModule} from '@angular/material/timepicker';
import {provideNativeDateAdapter} from '@angular/material/core';
import {MatChipsModule} from '@angular/material/chips';
import {MatIconModule} from '@angular/material/icon';
import {MatAutocompleteModule} from '@angular/material/autocomplete';
import {Day} from '../../enum/day';
import {MatTooltip} from '@angular/material/tooltip';
import {PeriodService} from '../../services/period.service';
import {AsyncPipe, TitleCasePipe, CommonModule} from '@angular/common';
import {map, Observable, of, startWith} from 'rxjs';
import {MatSelectModule} from '@angular/material/select';
import {recurrences, Renum} from '../../enum/recurrence';
import {TranslatePipe, TranslateService} from '@ngx-translate/core';
import {Rite, RiteMassTypes} from '../../enum/rites';
import {MassUtil} from '../../util/mass-util';
import {LanguageCode} from '../../enum/language-code';
import {DialogResponse} from '../../enum/dialog-response';
import {MatExpansionModule} from '@angular/material/expansion';
import {GeneratedPeriod} from '../../model/generated-period';
import {ScriptUtil} from '../../util/script-util';
import {DateTimeUtil} from '../../util/date-time-util';
import {DateTime} from 'luxon';
import {MatRadioButton, MatRadioGroup} from "@angular/material/radio";
import {MatDivider} from "@angular/material/divider";
import {SpecialType} from "../../model/period";
import {EasterDay} from "../../enum/easter-day";
import {ChristmasDay} from "../../enum/christmas-day";

@Component({
  selector: 'app-event-edit-dialog',
  providers: [
    provideNativeDateAdapter()
  ],
  imports: [
    CommonModule,
    FormsModule,
    MatFormFieldModule,
    MatInputModule,
    MatDatepickerModule,
    MatTimepickerModule,
    MatDialogModule,
    MatMenuModule,
    MatButtonModule,
    MatChipsModule,
    MatIconModule,
    MatAutocompleteModule,
    MatTooltip,
    AsyncPipe,
    ReactiveFormsModule,
    MatSelectModule,
    TranslatePipe,
    TitleCasePipe,
    MatExpansionModule,
    MatRadioGroup,
    MatRadioButton,
    MatDivider,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './add-full-event-dialog.component.html',
  styleUrls: ['../../../styles.scss','./add-full-event-dialog.component.css']
})
export class AddFullEventDialogComponent {
  readonly dialogRef = inject(MatDialogRef<AddFullEventDialogComponent>);
  readonly data = inject<DialogData>(MAT_DIALOG_DATA);

  periodCtr = new FormControl<GeneratedPeriod | null>(this.data.event.period);
  filteredPeriods$: Observable<GeneratedPeriod[]> = of([]);

  // #428: a felhasználó manuálisan állíthat be kivétel-időszakokat. A FormControl
  // értéke periodId-kből álló tömb, ami a Mass.manualExperiod-be megy mentéskor
  // (KÜLÖN az automatikusan számolt experiod-tól, hogy az automatikák ne töröljék).
  experiodCtr = new FormControl<number[]>(this.data.event.manualExperiod ?? []);

  // #454: Új dátum hozzáadásához használt ideiglenes változó
  public newExceptionDate: Date | null = null;

  public singleEvent: boolean = this.data.event.renum === Renum.NONE;
  public specialPeriodType?: SpecialType | null = null;

  public selectableGenPeriods: GeneratedPeriod[] = [];
  public titles: string[] = MassUtil.getTitles(this.data.event.rite);

  public readonly allDays = Object.values(Day);
  public readonly easterDays = Object.values(EasterDay);
  public readonly christmasDays = Object.values(ChristmasDay);
  public readonly recurrences = recurrences;
  public readonly rites = Object.values(Rite);
  public readonly languages = Object.values(LanguageCode);
  public readonly Object = Object;

  public dayError: boolean = false;
  public christmasDayError: boolean = false;
  public easterDayError: boolean = false;
  public startTimeError: boolean = false;

  selectedDays: Day | Day[] = this.data.event.selectedDays;
  selectedChristmasDay?: ChristmasDay | null = this.data.event.selectedChristmasDay;
  selectedEasterDay?: EasterDay | null = this.data.event.selectedEasterDay;

  public selectedPeriodIsMultiday: boolean | null = null;

  constructor(
    readonly periodService: PeriodService,
    readonly translateService: TranslateService,
    readonly cdr: ChangeDetectorRef,
  ) {
    // #453: létező mise szerkesztésekor a selectedDays a mentett rrule.byweekday
    // TÖMBJÉBŐL jön (pl. ['MO']). A havi-n.-napja (FIRST_WEEK..FIFTH_WEEK,
    // LAST_DAY_OF_MONTH) és minden single-day renum template-je viszont EGYETLEN
    // Day-t vár (mat-select multiple nélkül) — egy tömb ott nem talál egyezést,
    // ezért üresen maradt az „Ezen a napon" mező újranyitáskor. Init-kor a
    // betöltött renum multiDays-e szerint normalizáljuk: single-day esetén
    // tömb→első elem; multi-day esetén a tömböt érintetlenül hagyjuk (nem
    // veszítünk napokat, mint az onRenumChange transition-logikája tenné).
    this.normalizeSelectedDaysForLoadedRenum();

    const hasPeriodId = !!(this.data.event.period && (this.data.event.period as any).periodId);

    // A választható időszakokat furcsa sorrendben jelenítjük meg direkt.
    periodService.getSelectableGeneratedPeriodsByDate(this.data.event.start).subscribe(generatedPeriods => {
      this.selectableGenPeriods = generatedPeriods;
      // #308 (borazslo review): új mise létrehozásánál próbáljuk a templom már
      // meglévő miserend-mintájához illeszteni az alapértelmezést.
      // A getSelectableGeneratedPeriodsByDate súly szerint rendezi a periódusokat,
      // így először a magas-súlyú (pl. húsvét, karácsony) jönnek, aztán a normál
      // (pl. évközi / tanítási idő).
      //
      // Ha a templomnak vannak már miséi, és valamelyiknek a periódusa
      // szerepel a sorrendben, AZT vegyük első ajánlatra - így egy szerdai
      // májusi kattintás inkább "tanítási idő"-t ajánl, nem "húsvét"-ot vagy
      // "május 1."-et.
      //
      // Ha nincs egyezés (új templom, vagy soha nem volt mise ilyen időszakra),
      // marad a régi viselkedés: [0]. elem.
      // Do NOT set default period for single events
      //
      // #458: az auto-default CSAK ÚJ mise létrehozásakor fusson. Létező mise
      // szerkesztésekor (EDIT_MASS) a periódust a hívó (church-calendar) a mise
      // tárolt periodId-jából állítja be; ha az valamiért nem oldódik fel
      // (a generatedPeriods$-ban épp nincs meg a megfelelő évi példány), AKKOR
      // SEM szabad dátum-alapú defaultot (pl. „Téli időszak") találgatni egy
      // létező misére — inkább maradjon üres, hogy a hiba látszódjon, ne pedig
      // hibás időszakot mentsünk. (Ez ugyanaz a hibafajta mint a #450.)
      const isEditingExisting = this.data.title === 'EDIT_MASS';
      if (!this.data.event.period && !this.singleEvent && !isEditingExisting && generatedPeriods.length > 0) {
        const existingPeriodIds = new Set(this.data.existingPeriodIds ?? []);
        const matched = existingPeriodIds.size > 0
          ? generatedPeriods.find(p => existingPeriodIds.has(p.periodId))
          : null;
        const selectedPeriod = matched ?? generatedPeriods[0];
        // Immediately sync to data.event.period to avoid async race condition with validation
        this.data.event.period = selectedPeriod;
        this.periodCtr.setValue(selectedPeriod);
      }
    });

    this.periodCtr.valueChanges.subscribe(value => {
      this.data.event.period = value;
      this.specialPeriodType = this.periodService.getPeriodById(value?.periodId)?.specialType;
      // Ensure titles are filtered when period changes
      this.applyTitleFilter();

      // Update multiday flag and warn if necessary
      this.maybeWarnIfNotMultiday(value);

      // #428: ha az időszak megváltozott és az új időszak már a kézi kivétel-listán van,
      // távolítsuk el - egy mise nem lehet egyszerre a "tartozik" és "kivétel" listában is.
      const currentExperiod = this.experiodCtr.value ?? [];
      if (value?.periodId && currentExperiod.includes(value.periodId)) {
        this.experiodCtr.setValue(currentExperiod.filter(id => id !== value.periodId));
      }
    });

    // #428: a multi-select módosításait átvezetjük a dialog event-re, hogy
    // mentéskor a MassUtil.createMass át tudja venni (a KÉZI mezőbe).
    this.experiodCtr.valueChanges.subscribe(value => {
      this.data.event.manualExperiod = value && value.length > 0 ? value : null;
    });
    this.filteredPeriods$ = this.periodCtr.valueChanges.pipe(
      startWith(''),
      map(value => {
        const filterValue = typeof value === 'string' ? value.toLowerCase() : value?.name.toLowerCase() ?? '';
        return this.selectableGenPeriods.filter(period => period.name.toLowerCase().includes(filterValue));
      })
    );

    if (this.data.event.period !== null) {
      this.specialPeriodType = this.periodService.getSpecialPeriodType(this.data.event.period.periodId);
      if (this.specialPeriodType !== null) {
        this.singleEvent = false;
      }

      // If the initial period is not multi-day, inform the user as well
      this.maybeWarnIfNotMultiday(this.data.event.period);
    }

    // Apply initial filtering of titles based on the current mode/period
    this.applyTitleFilter();
  }

  // Centralized check for multi-day flag and user notification
  private maybeWarnIfNotMultiday(selectedGeneratedPeriod: GeneratedPeriod | null | undefined): void {
    try {
      const period = selectedGeneratedPeriod ? this.periodService.getPeriodById(selectedGeneratedPeriod.periodId) : null;
      if (period) {
        // set the flag for template consumption
        this.selectedPeriodIsMultiday = !!period.multiDay;      
      } else {
        this.selectedPeriodIsMultiday = null;
      }
    } catch (e) {
      // ignore errors and clear flag
      this.selectedPeriodIsMultiday = null;
    }
  }

  onSave(): void {
    // Az időpontot eddig SENKI nem ellenőrizte. Ha a timepicker üres maradt vagy
    // értelmezhetetlent kapott, a modellben Invalid Date ült, amiből a mentés
    // `2026-01-01TNaN:NaN:NaN` kezdést csinált. Az ilyen mise a szerkesztőben látszik,
    // a keresőben viszont soha nem jelenik meg — a gondnok jogosan hiszi, hogy felvitte.
    this.startTimeError = !DateTimeUtil.isValidDate(this.data.event.start);
    if (this.startTimeError) {
      return;
    }

    // Ensure data.event.period is synced from FormControl if needed
    if (this.data.event.period === null && this.periodCtr.value !== null) {
      this.data.event.period = this.periodCtr.value;
    }

    if (!this.singleEvent && ScriptUtil.isNull(this.data.event.period)) {
      this.periodCtr.setErrors({required: true});
      return;
    }

    if (!this.singleEvent && this.specialPeriodType === SpecialType.CHRISTMAS && ScriptUtil.isNull(this.selectedChristmasDay)) {
      this.christmasDayError = true;
      return;
    }

    if (!this.singleEvent && this.specialPeriodType === SpecialType.EASTER && ScriptUtil.isNull(this.selectedEasterDay)) {
      this.easterDayError = true;
      return;
    }

    const selectedGeneratedPeriod = this.data.event.period ?? this.periodCtr.value;
    const period = selectedGeneratedPeriod ? this.periodService.getPeriodById(selectedGeneratedPeriod.periodId) : null;
    if (!this.singleEvent && (period && period.multiDay === false)) {
      this.data.event.selectedChristmasDay = null;
      this.data.event.selectedEasterDay = null;
      this.data.event.selectedDays = [];
      this.data.event.renum = Renum.YEARLY;
      
    }

    else if (!this.singleEvent && this.specialPeriodType === null && (ScriptUtil.isNull(this.selectedDays) || this.selectedDays.length < 1)) {
      this.dayError = true;
      return;
    }

    if (this.specialPeriodType === SpecialType.CHRISTMAS) {
      this.data.event.selectedChristmasDay = this.selectedChristmasDay;
      this.data.event.selectedEasterDay = null;
      this.data.event.selectedDays = [];
    } else if (this.specialPeriodType === SpecialType.EASTER) {
      this.data.event.selectedChristmasDay = null;
      this.data.event.selectedEasterDay = this.selectedEasterDay;
      this.data.event.selectedDays = [];
    } else {
      this.data.event.selectedChristmasDay = null;
      this.data.event.selectedEasterDay = null;
      this.data.event.selectedDays = Array.isArray(this.selectedDays) ? this.selectedDays : [this.selectedDays];
    }
    this.dialogRef.close(DialogResponse.SAVE);
  }

  onNoClick(): void {
    this.dialogRef.close();
  }

  /**
   * #453: a betöltött renum multiDays-e szerint hozza összhangba a selectedDays
   * alakját (tömb vs. egyetlen Day), ADATVESZTÉS NÉLKÜL. Az onRenumChange a
   * felhasználói VÁLTÁSKOR szándékosan egyetlen napra redukál — ez init-kor
   * hibás lenne (egy meglévő heti több-napos misénél eldobná a napokat), ezért
   * külön, óvatos normalizáló az induló állapothoz.
   */
  private normalizeSelectedDaysForLoadedRenum(): void {
    const renum = this.data.event.renum;
    if (ScriptUtil.isNull(renum) || ScriptUtil.isNull(recurrences[renum])) {
      return;
    }
    const multiDays = recurrences[renum].multiDays;

    if (!multiDays && Array.isArray(this.selectedDays)) {
      // single-day renum (pl. havi n. napja): tömb → első elem (vagy üres).
      this.selectedDays = this.selectedDays.length > 0 ? this.selectedDays[0] : [];
    } else if (multiDays && !Array.isArray(this.selectedDays) && ScriptUtil.isNotNull(this.selectedDays)) {
      // multi-day renum, de egyetlen érték jött: csomagoljuk tömbbe.
      this.selectedDays = [this.selectedDays];
    }
  }

  onRenumChange() {
    const multiDays = recurrences[this.data.event.renum].multiDays;

    const singleDay: Day | undefined = Array.isArray(this.selectedDays)
      ? this.selectedDays.length > 0
        ? this.selectedDays[0]
        : undefined
      : this.selectedDays;

    if (singleDay) {
      if (multiDays) {
        this.selectedDays = [singleDay];
      } else {
        this.selectedDays = singleDay;
      }
    } else {
      this.selectedDays = [];
    }
  }

  onRecurrenceModChange() {
    if (this.singleEvent) {
      this.data.event.renum = Renum.NONE;
      // Clear period for single events - they should not have a period
      this.data.event.period = null;
      this.periodCtr.setValue(null);
    } else {
      this.data.event.renum = Renum.EVERY_WEEK;
    }
    // titles may need to be refreshed when recurrence mode changes
    this.applyTitleFilter();
  }

  onRiteChange() {
    this.titles = MassUtil.getTitles(this.data.event.rite);
    this.data.event.title = this.titles && this.titles.length > 0 ? this.translateService.instant(this.titles.at(0)!) : "";
    this.data.event.types = [];
    // titles may have to be filtered depending on the selected period / recurrence mode
    this.applyTitleFilter();
  }

  onStartTimeChange() {
    //ha módosítják az órát, a kizárás (ha volt) akkor is maradjon meg.
    const exdate = this.data.event.exdate;
    const startJs: Date = this.data.event.start;
    if (ScriptUtil.isNotNull(exdate)) {
      const startDt: DateTime = DateTime.fromObject(
        {
          year: startJs.getFullYear(),
          month: startJs.getMonth() + 1,
          day: startJs.getDate(),
          hour: startJs.getHours(),
          minute: startJs.getMinutes()
        },
      );
      const startTime: string = startDt.toFormat("HH:mm");
      this.data.event.exdate = exdate.map(dateStr => {
        const [datePart] = dateStr.split("T");
        return `${datePart}T${startTime}`;
      });
    }
    // Recalculate duration range when start time changes
    this.cdr.markForCheck();
  }

  onSelectedDaysChange() {
    this.dayError = false;
  }

  onDurationChange(): void {
    // Trigger change detection to recalculate the duration range hint
    this.cdr.markForCheck();
  }

  onSelectedChristmasDayChange() {
    this.christmasDayError = false;
  }

  onSelectedEasterDayChange() {
    this.easterDayError = false;

    // If Roman Catholic, pick a sensible default title key for the selected Easter-related day
    if (this.data.event.rite === Rite.ROMAN_CATHOLIC && this.selectedEasterDay) {
      let titleKey = '';
      switch (this.selectedEasterDay) {
        case EasterDay.TH:
          titleKey = 'MASS_TITLE.MASS_OF_THE_LORD_S_SUPPER';
          break;
        case EasterDay.FR:
          titleKey = 'MASS_TITLE.GOOD_FRIDAY_LITURGY';
          break;
        case EasterDay.SA:
          titleKey = 'MASS_TITLE.EASTER_VIGIL';
          break;
        case EasterDay.SU:
        case EasterDay.MO:
          titleKey = 'MASS_TITLE.HOLY_MASS';
          break;
      }
      if (titleKey) {
        this.data.event.title = this.translateService.instant(titleKey);        
      }
    }
  }

  resetPeriod(event: any) {
    event.preventDefault();
    event.stopPropagation();
    this.periodCtr.setValue(null);
  }

  displayPeriod(period: GeneratedPeriod | null): string {
    return period ? period.name : '';
  }

  /**
   * #428: a kivétel-időszak választó opciói. A pillanatnyilag kiválasztott
   * mise-időszakot kihagyjuk - egy misét nem lehet kizárni a saját időszakából.
   * (Periodonként egy bejegyzést tartunk: a getSelectableGeneratedPeriodsByDate
   * már periodId szerint deduplikált.)
   */
  get experiodOptions(): GeneratedPeriod[] {
    const currentPeriodId = this.periodCtr.value?.periodId;
    return this.selectableGenPeriods.filter(p => p.periodId !== currentPeriodId);
  }

  // Filter out Easter-specific titles when in recurring mode and the selected period is NOT an Easter period
  private applyTitleFilter(): void {
    // Re-load the canonical list of titles for the current rite
    const originalTitles = MassUtil.getTitles(this.data.event.rite) || [];
    this.titles = [...originalTitles];

    if (this.titles.length === 0) return;

    const removals = [
      'MASS_TITLE.TRADITIONAL_MASS_OF_THE_LORD_S_SUPPER',
      'MASS_TITLE.TRADITIONAL_GOOD_FRIDAY_LITURGY',
      'MASS_TITLE.TRADITIONAL_EASTER_VIGIL',
      'MASS_TITLE.MASS_OF_THE_LORD_S_SUPPER',
      'MASS_TITLE.GOOD_FRIDAY_LITURGY',
      'MASS_TITLE.EASTER_VIGIL'
    ];

    // If not a single event (i.e. recurring) AND the selected period isn't Easter, remove Easter-specific titles
    const isEasterPeriod = this.specialPeriodType === SpecialType.EASTER;
    if (!this.singleEvent && !isEasterPeriod) {
      this.titles = this.titles.filter(t => !removals.includes(t));
      // If the currently selected title was removed, reset to first available
      if (this.data.event.title && removals.includes(this.data.event.title)) {
        this.data.event.title = this.titles && this.titles.length > 0 ? this.translateService.instant(this.titles[0]) : '';
      }
    }
  }

  protected readonly RiteMassTypes = RiteMassTypes;
   protected readonly Renum = Renum;
   protected readonly SpecialType = SpecialType;

   getFormattedDurationRange(): string {
     const startTime = this.data.event.start;
     const duration = this.data.event.duration;
     
     if (!startTime || !duration) {
       return '';
     }

     const startHours = startTime.getHours().toString().padStart(2, '0');
     const startMinutes = startTime.getMinutes().toString().padStart(2, '0');
     
     // Calculate end time by adding duration
     const durationMs =
       ((duration.days ?? 0) * 24 * 60 * 60 * 1000) +
       ((duration.hours ?? 0) * 60 * 60 * 1000) +
       ((duration.minutes ?? 0) * 60 * 1000);
     
     const endTime = new Date(startTime.getTime() + durationMs);
     const endHours = endTime.getHours().toString().padStart(2, '0');
     const endMinutes = endTime.getMinutes().toString().padStart(2, '0');
     
     // Check if event extends to the next day
     const startDate = new Date(startTime.getFullYear(), startTime.getMonth(), startTime.getDate());
     const endDate = new Date(endTime.getFullYear(), endTime.getMonth(), endTime.getDate());
     const dayDifference = Math.floor((endDate.getTime() - startDate.getTime()) / (24 * 60 * 60 * 1000));
     
     let result = `${startHours}:${startMinutes} - ${endHours}:${endMinutes}`;
     
     if (dayDifference > 0) {
       const dayLabel = this.translateService.instant('DURATION_EXTENDED_DAYS');
       result += ` +${dayDifference}\u00A0${dayLabel}`;
     }
     
     return result;
   }

  /**
   * #454: A datepicker dateChange eseményére reagál és automatikusan hozzáadja a dátumot.
   */
  onExceptionDateSelected(): void {
    this.addExceptionDate();
  }

  /**
   * #454: Új kizárt dátum hozzáadása az exdate listához.
   * A dátumot ISO formátumban tároljuk, a kezdési idővel kombinálva.
   */
  addExceptionDate(): void {
    if (!this.newExceptionDate) {
      return;
    }

    // Ensure exdate array exists
    if (!this.data.event.exdate) {
      this.data.event.exdate = [];
    }

    // Combine the selected date with the current start time
    const startTime = this.data.event.start;
    const exDateTime = new Date(this.newExceptionDate);
    exDateTime.setHours(startTime.getHours());
    exDateTime.setMinutes(startTime.getMinutes());
    exDateTime.setSeconds(0);
    exDateTime.setMilliseconds(0);

    // Format as ISO string (YYYY-MM-DDTHH:mm)
    const year = exDateTime.getFullYear();
    const month = String(exDateTime.getMonth() + 1).padStart(2, '0');
    const day = String(exDateTime.getDate()).padStart(2, '0');
    const hours = String(exDateTime.getHours()).padStart(2, '0');
    const minutes = String(exDateTime.getMinutes()).padStart(2, '0');
    const dateString = `${year}-${month}-${day}T${hours}:${minutes}`;

    // Add only if not already present
    if (!this.data.event.exdate.includes(dateString)) {
      this.data.event.exdate.push(dateString);
      // Sort dates in chronological order
      this.data.event.exdate.sort();
      this.cdr.markForCheck();
    }

    // Clear the input field
    this.newExceptionDate = null;
  }

  /**
   * #454: Kizárt dátum eltávolítása.
   */
  removeExceptionDate(dateString: string): void {
    if (this.data.event.exdate) {
      this.data.event.exdate = this.data.event.exdate.filter(d => d !== dateString);
      if (this.data.event.exdate.length === 0) {
        this.data.event.exdate = null;
      }
      this.cdr.markForCheck();
    }
  }

  /**
   * #454: Dátum formázása olvasható formátumba (pl. "2026.07.16.").
   */
  formatExceptionDate(dateString: string): string {
    const date = new Date(dateString);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}.${month}.${day}.`;
  }

  /**
   * #454: Getter a rendezett exdate tömb eléréséhez (fordított sorrendben).
   */
  get sortedExceptionDates(): string[] {
    if (!this.data.event.exdate || this.data.event.exdate.length === 0) {
      return [];
    }
    return [...this.data.event.exdate].sort().reverse();
  }

  /**
   * Generates the summary text for the Advanced settings panel.
   * Shows count of excluded dates and manual periods.
   */
  getAdvancedSettingsSummary(): string {
    const dateCount = this.data.event.exdate?.length ?? 0;
    const periodCount = this.experiodCtr.value?.length ?? 0;
    
    if (dateCount === 0 && periodCount === 0) {
      return this.translateService.instant('EXCLUDED_COUNT.NONE');
    }
    
    if (dateCount > 0 && periodCount > 0) {
      return this.translateService.instant('EXCLUDED_COUNT.BOTH', {
        dateCount: dateCount,
        periodCount: periodCount
      });
    }
    
    if (dateCount > 0) {
      const key = dateCount === 1 ? 'EXCLUDED_COUNT.DATES_SINGULAR' : 'EXCLUDED_COUNT.DATES_PLURAL';
      return this.translateService.instant(key, { count: dateCount });
    }
    
    if (periodCount > 0) {
      const key = periodCount === 1 ? 'EXCLUDED_COUNT.PERIODS_SINGULAR' : 'EXCLUDED_COUNT.PERIODS_PLURAL';
      return this.translateService.instant(key, { count: periodCount });
    }
    
    return '';
  }
}
