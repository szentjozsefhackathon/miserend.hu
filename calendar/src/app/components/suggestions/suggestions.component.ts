import {Component, OnInit, ViewChild} from '@angular/core';
import {ActivatedRoute, RouterLink} from '@angular/router';
import {EventService} from '../../event.service';
import {Mass} from '../../model/mass';
import {Church} from '../../model/church';
import {FullCalendarModule} from '@fullcalendar/angular';
import {SpinnerService} from '../../services/spinner.service';
import {SuggestionUtil} from '../../util/suggestion-util';
import {TranslatePipe, TranslateService} from '@ngx-translate/core';
import {MatButtonToggle, MatButtonToggleGroup} from '@angular/material/button-toggle';
import {MatIcon} from '@angular/material/icon';
import {MatTooltip} from '@angular/material/tooltip';
import {MatFormField, MatLabel, MatOption, MatSelect} from '@angular/material/select';
import {SuggestionPackage, SuggestionState} from '../../model/suggestion-package';
import {ChurchCalendarComponent} from '../church-calendar/church-calendar.component';
import {MatButton} from '@angular/material/button';
import {MatCheckbox} from '@angular/material/checkbox';
import {FormsModule} from '@angular/forms';
import {DatePipe, NgClass} from '@angular/common';
import {MatSnackBarService} from '../../services/mat-snack-bar.service';
import {PeriodService} from '../../services/period.service';
import {ScriptUtil} from '../../util/script-util';
import {MassUtil} from '../../util/mass-util';
import {TextUtil} from '../../util/text-util';
import {ReadableMass} from '../../model/readable-mass';
import {DateTimeUtil} from '../../util/date-time-util';
import {MassRowComponent} from '../mass-row/mass-row.component';
import {MassesDiffComponent} from '../masses-diff/masses-diff.component';
import {SearchService} from '../../services/search.service';
import {SpecialType} from "../../model/period";

@Component({
  selector: 'app-suggestions',
  imports: [
    FullCalendarModule,
    TranslatePipe,
    MatButtonToggle,
    MatButtonToggleGroup,
    MatIcon,
    MatTooltip,
    MatOption,
    MatSelect,
    ChurchCalendarComponent,
    MatButton,
    MatCheckbox,
    MatFormField,
    MatLabel,
    FormsModule,
    DatePipe,
    NgClass,
    MassRowComponent,
    MassesDiffComponent,
    RouterLink,
  ],
  templateUrl: './suggestions.component.html',
  styleUrl: './suggestions.component.css'
})
export class SuggestionsComponent implements OnInit {

  @ViewChild('calendarOrig') calendarOrig!: ChurchCalendarComponent;
  @ViewChild('calendarNew') calendarNew!: ChurchCalendarComponent;

  public origDataLoaded: boolean = false;
  public newDataLoaded: boolean = false;

  public currentChurch?: Church | null = null;

  public origMasses: Map<number, Mass> = new Map();
  public newMasses: Map<number, Mass> = new Map();
  public changes: Map<number, Mass> = new Map();
  public deletedMasses: number[] = [];
  public deletedDates: Map<number, string[]> = new Map();
  public changedMasses: number[] = [];

  public hasSuggestion: boolean = true;

  public suggestionPackages: SuggestionPackage[] = [];
  public selectedSuggestionPackage?: SuggestionPackage;

  // #543: elutasításkor a kezelő eldöntheti, kapjon-e a beküldő email-értesítést. Default: ne.
  public notifySenderOnReject: boolean = false;

  public calendarsTitle: string = '';

  public readableFullyDeletedMasses: ReadableMass[] = [];
  public readableOccasionalDeletedMasses: ReadableMass[] = [];
  public readableNewMasses: ReadableMass[] = [];
  public readableModifiedMasses: {origMass: ReadableMass, newMass: ReadableMass}[] = [];

  constructor(
    private readonly activatedRoute: ActivatedRoute,
    private readonly eventService: EventService,
    private readonly searchService: SearchService,
    private readonly spinnerService: SpinnerService,
    private readonly snackBarService: MatSnackBarService,
    private readonly translateService: TranslateService,
    private readonly periodService: PeriodService,
  ) {
  }

  ngOnInit() {
    this.spinnerService.show();
    this.initEvents();
  }

  private initEvents() {
    const churchId: number = +this.activatedRoute.snapshot.params['id'];
    this.eventService.getChurch(churchId).subscribe((church: Church) => {
      this.currentChurch = church;
      this.origMasses = new Map(church.masses.map(e => [e.id!, e]));
      this.origDataLoaded = true;
      this.initSuggestions();
    });
  }

  private initSuggestions() {
    const churchId: number = +this.activatedRoute.snapshot.params['id'];
    this.eventService.getSuggestions(churchId).subscribe(suggestionPackages => {
      this.suggestionPackages = suggestionPackages.map(pkg => ({
        ...pkg,
        createdAt: new Date(pkg.createdAt)
      }));
      if (!suggestionPackages || suggestionPackages.length <= 0) {
        this.hasSuggestion = false;
        this.selectedSuggestionPackage = undefined;
        this.spinnerService.hide();
      } else {
        this.suggestionPackages.sort((a, b) =>
          b.createdAt.getTime() - a.createdAt.getTime()
        );

        // choose initial package: prefer one specified in URL query param (pkg|packageId|suggestionId) or route param
        const pkgIdParam = this.activatedRoute.snapshot.queryParams['packageId'];
        const pkgId = pkgIdParam ? Number(pkgIdParam) : NaN;
        if (!isNaN(pkgId)) {
          const found = this.suggestionPackages.find(p => p.id === pkgId);
          if (found) {
            this.selectedSuggestionPackage = found;
          } else {
            this.selectedSuggestionPackage = this.suggestionPackages[0];
          }
        } else {
          this.selectedSuggestionPackage = this.suggestionPackages[0];
        }
        this.initSuggestionPackage();
      }
    });
  }

  private initSuggestionPackage() {
    this.changes =  new Map();
    this.changedMasses =  [];
    this.deletedMasses =  [];
    this.deletedDates = new Map();
    this.newMasses = new Map();
    if (this.selectedSuggestionPackage) {
      this.spinnerService.show();

      const newMassesList = SuggestionUtil.mergeMasses(Array.from(this.origMasses.values()), this.selectedSuggestionPackage.suggestions);

      const iSuggest = SuggestionUtil.generateInverseSuggestions(this.origMasses, this.selectedSuggestionPackage.suggestions);

      this.changes = iSuggest.changes;
      this.changedMasses = iSuggest.changedMasses;
      this.deletedMasses = iSuggest.deletedMasses;
      this.deletedDates = iSuggest.deletedDates;
      this.newMasses = new Map(newMassesList.map(e => [e.id!, e]));

      this.readableFullyDeletedMasses = [];
      for (let deletedMassId of this.deletedMasses) {
        const readableDeletedMass = this.getReadableMass(this.origMasses.get(deletedMassId));
        if (readableDeletedMass) {
          this.readableFullyDeletedMasses.push(readableDeletedMass);
        }
      }

      this.readableOccasionalDeletedMasses = [];
      for (const [key, value] of this.deletedDates) {
        const dates = value.map(DateTimeUtil.getReadableDateFromIso);
        const readableDeletedMass = this.getReadableMass(this.origMasses.get(key), dates);
        if (readableDeletedMass) {
          this.readableOccasionalDeletedMasses.push(readableDeletedMass);
        }
      }

      this.readableNewMasses = [];
      this.readableModifiedMasses = [];
      for (let changedMass of this.changes.values()) {
        if (changedMass.id < 0) {
          const readableNewMass = this.getReadableMass(changedMass);
          if (readableNewMass) {
            this.readableNewMasses.push(readableNewMass);
          }
        } else {
          const origMass = this.getReadableMass(this.origMasses.get(changedMass.id));
          const newMass = this.getReadableMass(changedMass);
          if (origMass && newMass) {
            this.readableModifiedMasses.push({origMass: origMass, newMass: newMass});
          }
        }
      }

      this.newDataLoaded = true;
    }
  }

  public onSuggestionPackageChange() {
    this.initSuggestionPackage();
  }

  public setCalendarsTitle(title: string) {
    setTimeout(() => {
      this.calendarsTitle = title;
    });
  }

  next() {
    this.calendarOrig?.next();
    this.calendarNew?.next();
  }

  prev() {
    this.calendarOrig?.prev();
    this.calendarNew?.prev();
  }

  changeView(view: 'dayGridDay' | 'dayGridMonth' | 'timeGridWeek') {
    this.calendarOrig.changeView(view);
    this.calendarNew.changeView(view);
  }


  isSuggestionPending(): boolean {
    return this.selectedSuggestionPackage?.state === SuggestionState.PENDING;
  }

  getStateClass(state: SuggestionState | string): string {
    return 'state-' + (state || '').toLowerCase();
  }

  onApprove() {
    this.spinnerService.show();
    this.calendarNew.onAcceptSuggestion(this.selectedSuggestionPackage!, this.origMasses).subscribe(
      res => {
        this.snackBarService.success('Sikeres jóváhagyás!');

        //TODO: EZT MAJD HÁTTÉRBEN
        const currentYear = new Date().getFullYear();
        const years: number[] = [currentYear - 1, currentYear, currentYear + 1];
        this.searchService.generateMasses(years, this.currentChurch!.id).subscribe();

        this.suggestionPackages = res.suggestionPackages.map(pkg => ({
          ...pkg,
          createdAt: new Date(pkg.createdAt)
        }));

        if (this.suggestionPackages.length > 0) {
          this.suggestionPackages.sort((a, b) => b.createdAt.getTime() - a.createdAt.getTime());
          // Keep the current package selected if it still exists, otherwise select the first one
          const currentId = this.selectedSuggestionPackage?.id;
          const currentStillExists = this.suggestionPackages.some(pkg => pkg.id === currentId);
          if (currentStillExists) {
            this.selectedSuggestionPackage = this.suggestionPackages.find(pkg => pkg.id === currentId)!;
          } else {
            this.selectedSuggestionPackage = this.suggestionPackages[0];
          }
        } else {
          this.hasSuggestion = false;
          this.selectedSuggestionPackage = undefined;
          this.newMasses.clear?.();
          this.clearReadableMasses();
        }

        let masses : Map<number, Mass> = new Map();
        res.calendarMasses.forEach(mass => masses.set(
          mass.id,
          mass
        ));
        this.origMasses = masses;
        this.initSuggestionPackage();
        this.spinnerService.hide();
      },
      error => {
        console.error('Error accepting suggestion:', error);
        this.spinnerService.hide();
        // snackBarService.error már meghívódott az event.service-ben
      }
    );
  }

  onReject() {
    this.spinnerService.show();
    this.calendarNew.onRejectSuggestion(this.selectedSuggestionPackage!, this.notifySenderOnReject).subscribe(
      res => {
        this.snackBarService.success('Sikeres elutasítás!');
        this.notifySenderOnReject = false;

        this.suggestionPackages = res.suggestionPackages.map(pkg => ({
          ...pkg,
          createdAt: new Date(pkg.createdAt)
        }));

        if (this.suggestionPackages.length > 0) {
          this.suggestionPackages.sort((a, b) => b.createdAt.getTime() - a.createdAt.getTime());
          // Keep the current package selected if it still exists, otherwise select the first one
          const currentId = this.selectedSuggestionPackage?.id;
          const currentStillExists = this.suggestionPackages.some(pkg => pkg.id === currentId);
          if (currentStillExists) {
            this.selectedSuggestionPackage = this.suggestionPackages.find(pkg => pkg.id === currentId)!;
          } else {
            this.selectedSuggestionPackage = this.suggestionPackages[0];
          }
          this.initSuggestionPackage();
        } else {
          this.hasSuggestion = false;
          this.selectedSuggestionPackage = undefined;
          this.newMasses.clear?.();
          this.clearReadableMasses();
        }
        this.spinnerService.hide();
      },
      error => {
        console.error('Error rejecting suggestion:', error);
        this.spinnerService.hide();
        // snackBarService.error már meghívódott az event.service-ben
      }
    );
  }

  private getPeriod(mass: Mass): string {
    const period = this.periodService.getPeriodById(mass.periodId);
    return period ? period.name : '';
  }

  private getExPeriodNames(experiod?: number[] | null): string[] {
    const exPeriodNames: string[] = [];
    if (ScriptUtil.isNotNull(experiod)) {
      experiod.forEach(periodId => {
        const period = this.periodService.getPeriodById(periodId);
        if (period) {
          exPeriodNames.push(period.name);
        }
      });
    }
    return exPeriodNames;
  }

  private getDays(mass: Mass): string {
    const days = mass.rrule?.byweekday;
    if (ScriptUtil.isNotNull(days)) {
      const translatedDays: string[] = [...days].map(d => this.translateService.instant('DAYS.ON.' + d));
      return TextUtil.concatDays(translatedDays, ', ', this.translateService.instant('SEPARATOR_AND'));
    }
    return '';
  }

  private getWeek(mass: Mass): string | null {
    const rrule = mass.rrule;
    if (ScriptUtil.isNull(rrule) || rrule.freq !== 'weekly') {
      return null;
    }

    if (rrule.byweekno && rrule.byweekno.length > 0) {
      const isEven = rrule.byweekno.every(n => n % 2 === 0);
      const isOdd = rrule.byweekno.every(n => n % 2 === 1);
      const week: string = this.translateService.instant(isEven ? 'RRULE.ON.EVEN' : isOdd ? 'RRULE.ON.ODD' : '');

      if (!isEven && !isOdd) {
        console.error('se nem páros, se nem páratlan heteken ismétlődik...');
        alert('se nem páros, se nem páratlan heteken ismétlődik...');
      }

      return week;
    }

    return this.translateService.instant('RRULE.ON.EVERY_WEEK');
  }

  private getMonth(mass: Mass): string | null {
    const rrule = mass.rrule;
    if (ScriptUtil.isNotNull(rrule) && ScriptUtil.isNotNull(rrule.bysetpos)) {
      const renumByPos = MassUtil.renumByPos(rrule.bysetpos);
      if (renumByPos != null) {
        return this.translateService.instant('RRULE.ON.' + renumByPos);
      }
    }
    return null;
  }

  private getEaster(mass: Mass): string | null {
    if (ScriptUtil.isNotNull(mass.periodId)) {
      const specialPeriodType = this.periodService.getSpecialPeriodType(mass.periodId);
      if (specialPeriodType === SpecialType.EASTER) {
        const rrule = mass.rrule;
        if (ScriptUtil.isNotNull(rrule) && ScriptUtil.isNotNull(rrule.byweekday) && rrule.byweekday.length === 1) {
          let easterDay = rrule.byweekday[0];
          if(easterDay != null) {
            return this.translateService.instant("EASTER_DAYS." + easterDay);
          }
        }
      }
    }
    return null;
  }

  private getChristmas(mass: Mass): string | null {
    const rrule = mass.rrule;
    if (ScriptUtil.isNotNull(rrule) && rrule.bymonth === 12 && ScriptUtil.isNotNull(rrule.bymonthday)) {
      let christmasDay = MassUtil.christmasDayByMonthday(rrule.bymonthday);
      if(christmasDay != null) {
        return this.translateService.instant("CHRISTMAS_DAYS." + christmasDay);
      }
    }
    return null;
  }

  private getReadableMass(mass?: Mass, dates?: string[]): ReadableMass | null {
    if (!mass) {
      return null;
    }

    const period: string = this.getPeriod(mass);
    // #428: az auto + kézi kizárt időszakok uniója jelenjen meg az összefoglalóban/diffben
    const exPeriodNames: string[] = this.getExPeriodNames(MassUtil.getEffectiveExperiod(mass));
    const days: string = this.getDays(mass);
    const christmas: string | null = this.getChristmas(mass);
    const easter: string | null = this.getEaster(mass);
    const week: string | null = this.getWeek(mass);
    const month: string | null = this.getMonth(mass);
    const time: string = DateTimeUtil.getOnlyTimeString(mass.startDate);
    const rite: string = this.translateService.instant(mass.rite);
    const types: string = ScriptUtil.isNotNull(mass.types) && mass.types.length > 0 ?
      mass.types.map(type => this.translateService.instant(type)).join(', ') : '';
    const duration: string = ScriptUtil.isNotNull(mass.duration) ? TextUtil.getReadableDuration(mass.duration) : '';

    return {
      massId: mass.id,
      ...(period && period.length > 0 && {period: period}),
      ...(exPeriodNames && exPeriodNames.length > 0 && {experiod: exPeriodNames}),
      ...(days && days.length > 0 && {days: days}),
      ...(easter && easter.length > 0 && {easter: easter}),
      ...(christmas && christmas.length > 0 && {christmas: christmas}),
      ...(week && week.length > 0 && {week: week}),
      ...(month && month.length > 0 && {month: month}),
      ...(dates && dates.length > 0 && {mDates: dates}),
      ...(types && types.length > 0 && {types: types}),
      ...(rite && rite.length > 0 && {rite: rite}),
      ...(duration && duration.length > 0 && {duration: duration}),
      ...(mass.comment && mass.comment.length > 0 && {comment: mass.comment}),
      lang: this.translateService.instant(`LANGUAGES.${mass.lang}`),
      title: mass.title,
      time: time,
      startDate: DateTimeUtil.getReadableDateTimeFromIso(mass.startDate)
    };
  }

  private clearReadableMasses() {
    this.readableFullyDeletedMasses = [];
    this.readableOccasionalDeletedMasses = [];
    this.readableNewMasses = [];
    this.readableModifiedMasses = [];
  }
}
