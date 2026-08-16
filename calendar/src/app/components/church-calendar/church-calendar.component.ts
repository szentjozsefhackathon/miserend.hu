import {
  AfterViewInit,
  Component,
  inject,
  Input,
  OnChanges,
  OnInit,
  output,
  SimpleChanges,
  ViewChild,
  TemplateRef,
  ViewContainerRef,
  EmbeddedViewRef
} from '@angular/core';
import {AsyncPipe, CommonModule} from '@angular/common';
import {FullCalendarComponent, FullCalendarModule} from '@fullcalendar/angular';
import {CalendarOptions, EventInput} from '@fullcalendar/core';
import {EventService} from '../../event.service';
import {MatDialog} from '@angular/material/dialog';
import {AddFullEventDialogComponent} from '../add-full-event-dialog/add-full-event-dialog.component';
import {PeriodService} from '../../services/period.service';
import {AddSimpleEventDialogComponent} from '../add-simple-event-dialog/add-simple-event-dialog.component';
import {MassUtil} from '../../util/mass-util';
import {Mass} from '../../model/mass';
import {CalendarEvent} from '../../model/calendar/calendar-event';
import {Church, ChurchFamilyMember} from '../../model/church';
import {SensorEvent} from '../../model/sensor-event';
import {LiturgicalDay} from '../../model/liturgical-day';
import {TranslatePipe, TranslateService} from '@ngx-translate/core';
import {MatButton} from '@angular/material/button';
import {DialogEvent} from '../../model/dialog-event';
import {DialogResponse} from '../../enum/dialog-response';
import {EventViewerDialogComponent} from '../event-viewer-dialog/event-viewer-dialog.component';
import {SensorEventPopupComponent, SensorEventDialogData} from '../sensor-event-popup/sensor-event-popup.component';
import {DateTimeUtil} from '../../util/date-time-util';
import {SuggestionPackage, SuggestionState} from '../../model/suggestion-package';
import {SuggestionUtil} from '../../util/suggestion-util';
import {ScriptUtil} from '../../util/script-util';
import {SpinnerService} from '../../services/spinner.service';
import {CalendarUtil} from '../../util/calendar-util';
import {MatSnackBarService} from '../../services/mat-snack-bar.service';
import {filter, Observable, take} from 'rxjs';
import {MatFormField, MatInput, MatLabel} from '@angular/material/input';
import {FormControl, FormsModule, ReactiveFormsModule} from '@angular/forms';
import {UserService} from '../../services/user.service';
import {PeriodExclusionDialogComponent} from '../period-exclusion-dialog/period-exclusion-dialog.component';
import {AddMessageDialogComponent} from '../add-message-dialog/add-message-dialog.component';
import {MatButtonToggle, MatButtonToggleGroup} from '@angular/material/button-toggle';
import {MatIcon} from '@angular/material/icon';
import {MatTooltip} from '@angular/material/tooltip';
import {SearchService} from '../../services/search.service';
import {GeneratedPeriod} from "../../model/generated-period";
import {SpecialType} from "../../model/period";
import { eventListTemplate, EventListTemplateVars } from './event-list-template';
import {EditConfirmationService} from '../../services/edit-confirmation.service';
import {CopyPeriodDialogComponent, CopyPeriodDialogData} from '../copy-period-dialog/copy-period-dialog.component';
import {DeletePeriodDialogComponent, DeletePeriodDialogData} from '../delete-period-dialog/delete-period-dialog.component';
import {DeleteWarningDialogComponent} from '../delete-warning-dialog/delete-warning-dialog.component';
import {MassTitleCategory} from '../../enum/mass-categories';
import {MassTitleCategoryConfig} from '../../util/mass-title-category-config';
import {CompressionResult, WeekCompressionUtil, WeekEvent} from '../../util/week-compression-util';

export interface SimpleDialogData {
  dateTime: Date;
  title: string;
}

export interface EventViewerDialogData {
  churchName: string;
  mass: Mass;
  suggestOrEditable: boolean;
  start: Date;
  country?: string;
}

export interface DeleteDialogData {
  eventData: EventViewerDialogData;
  deleteOne: boolean;
}

export interface DialogData {
  title: string;
  event: DialogEvent;
  // #308 (borazslo review): a templom már létező miséinek periódus-azonosítói
  // (a betöltött + a folyamatban lévő változások egyesítve, deduplikálva).
  // A dialog az alapértelmezett periódus-választás során előnyben részesíti
  // ezeket: olyan miséhez ne ajánljunk új időszakot (pl. húsvét), ha a
  // templomnak van már „illeszthető" rendszeres miserendje (pl. tanítási idő).
  existingPeriodIds?: number[];
  country?: string;
}

@Component({
  selector: 'app-church-calendar',
  imports: [CommonModule, FullCalendarModule, AsyncPipe, MatButton, TranslatePipe, MatInput, MatFormField, MatLabel, FormsModule, ReactiveFormsModule, MatButtonToggle, MatButtonToggleGroup, MatIcon, MatTooltip],
  templateUrl: './church-calendar.component.html',
  styleUrls: ['../../../styles.scss', './church-calendar.component.css']
})
export class ChurchCalendarComponent implements OnInit, AfterViewInit, OnChanges {
  @Input() showHeader: boolean = true;
  @Input() showFooter: boolean = true;
  @Input() editable: boolean = false;
  @Input() suggestible: boolean = true;
  @Input({ required: true }) currentChurch!: Church;
  @Input({ required: true }) masses!: Map<number, Mass>;
  @Input() changes: Map<number, Mass> = new Map();
  @Input() deletedMasses: number[] = [];
  @Input() deletedDates: Map<number, string[]> = new Map();

  @Input() changedMasses: number[] = [];
  @Input() sensorEvents: SensorEvent[] = [];

  /**
   * #506: a plébánia és fíliái. Üres marad az egy-templomos szerkesztőben, tehát a
   * mostani viselkedés semmiben nem változik.
   */
  @Input() family: ChurchFamilyMember[] = [];

  /**
   * A ROKON templomok neve azonosító szerint — a saját templom szándékosan kimarad.
   *
   * Így a naptárban csak az idegen esemény kap templomnevet a címe elé; a sajátjaink
   * ugyanúgy néznek ki, mint eddig.
   */
  private otherChurchNames(): Map<number, string> {
    const nevek = new Map<number, string>();
    for (const tag of this.family) {
      if (tag.isCurrent) {
        continue;
      }
      nevek.set(tag.id, tag.name);
    }
    return nevek;
  }

  // Szűrő komponens megjelenítése - kikapcsolt javaslatok oldalon
  showFilterComponent: boolean = true;


  datesSet = output<string>();
  private edit = false;
  @ViewChild('calendar') calendarComponent!: FullCalendarComponent;
  // Template for rendering list-view event HTML via Angular bindings
  @ViewChild('eventListTemplate', { read: TemplateRef }) eventListTemplateRef!: TemplateRef<any>;
  @ViewChild('eventListTemplateContainer', { read: ViewContainerRef }) eventListTemplateContainer!: ViewContainerRef;

  private dialogEvent?: DialogEvent;
  readonly dialog = inject(MatDialog);
  calendarOptions?: CalendarOptions;
  eventsPromise?: Promise<EventInput[]>;

  private calEvents: CalendarEvent[] = [];

  // Loading state for events to control the empty-view text
  private loadingEvents: boolean = false;
  private loadedEvents: boolean = false;
  private everHadEvents: boolean = false;

  selectedEvent?: any;
  selectedEventStart?: Date;
  selectedMassId?: number;
  selectedDate?: Date;
  suggestionSenderName: FormControl<string> = new FormControl();
  suggestionSenderEmail: FormControl<string> = new FormControl();
  suggestionSenderID: FormControl<number> = new FormControl();
  suggestionSenderMessage: FormControl<string> = new FormControl();

  public calendarsTitle: string = '';

  // Kategóriák az új badge szűrőhöz
  categories: MassTitleCategory[] = [];
  categoryColors: Record<MassTitleCategory, string> = {} as any;

  // Show a simple mass list under the calendar in edit/admin contexts (editschedule)
  public showMassListInEdit: boolean = false;
  public massListGrouped: Array<{
    weight: number,
    periodName: string,
    masses: any[],
    startMonthDay?: string | null,
    endMonthDay?: string | null,
    startPeriodName?: string | null,
    endPeriodName?: string | null,
    color?: string | null,
    hasOldMasses?: boolean,
    oldMasses?: any[],
    expandOldMasses?: boolean
  }> = [];

  // Szűrés a kategóriák alapján
  activeFilterCategories: Set<MassTitleCategory> = new Set();

  // Liturgical days data
  private liturgicalDays: {[date: string]: LiturgicalDay} = {};

  // #358: heti nézet idősáv-tömörítés.
  //
  // borazslo issue-leírása: „A hét nézet nehezen fér el egy képernyőn, mert hát
  // a reggeli misék és az esti misék között jó nagy a távolság. De nem lehet
  // simán kiiktatni a közepét sem az éjszakát, mert van olyan hogy nagyszombat,
  // és van olyan hogy valami extra."
  //
  // Felhasználói toggle (default: bekapcsolva). Csak `timeGridWeek` nézetben aktív.
  // A `weekCompressionResult` a legutóbbi heti nézethez elvégzett analízist tárolja,
  // hogy a footer-panel és a tooltip onnan tudjon olvasni.
  public weekCompressionEnabled = true;
  public weekCompressionResult: CompressionResult | null = null;
  // #358: a middle-collapse-hoz - a slotLaneClassNames hook ebből dönti el, mely
  // slot-lane-t jelölje meg (fc-empty-slot). minute-of-day (slot-kezdet) halmaz.
  public collapsedSlotMinutes = new Set<number>();
  // Aláírás a felesleges re-render elkerülésére (slotMin|slotMax|collapsed).
  private lastCompressionSignature = '';
  public currentViewType: string = '';

  constructor(
    private readonly eventService: EventService,
    private readonly searchService: SearchService,
    private readonly periodService: PeriodService,
    private readonly snackBarService: MatSnackBarService,
    private readonly spinnerService: SpinnerService,
    private readonly userService: UserService,
    private readonly translateService: TranslateService,
    private readonly editConfirmation: EditConfirmationService,
  ) {}

  ngOnInit() {
    this.initializeCalendar();
    
    // Alapértelmezésben mindegyik kategória aktív
    this.activeFilterCategories = new Set(MassUtil.getAllCategories());
    
    // Kategóriák és szín inicializálása a badge szűrőhöz
    this.categories = MassUtil.getAllCategories();
    this.categories.forEach((category: MassTitleCategory) => {
      this.categoryColors[category] = MassTitleCategoryConfig.getColorByCategory(category);
    });
    
    // determine whether we should render the mass list under the calendar
    const pathname: string = (typeof window !== 'undefined' && window.location && window.location.pathname) ? String(window.location.pathname) : '';
    this.showMassListInEdit = !!this.editable || pathname.indexOf('editschedule') !== -1;

    // Detektáljuk, hogy a javaslatok oldalon vagyunk-e
    // Ha 'javaslatok' vagy 'suggestionpackages' van az URL-ben, akkor elrejtjük az old filter-t
    const isSuggestionsPage = pathname.indexOf('javaslatok') !== -1 || pathname.indexOf('suggestionpackages') !== -1;
    this.showFilterComponent = !isSuggestionsPage;
    console.log('[DEBUG] Pathname:', pathname, 'isSuggestionsPage:', isSuggestionsPage, 'showFilterComponent:', this.showFilterComponent);

    // default edit mode: enable immediately for the dedicated editschedule route,
    // otherwise keep false so users see the confirmation dialog on first edit attempt
    this.edit = pathname.indexOf('editschedule') !== -1;

    // If we're on the editschedule route, treat the app as already confirmed for editing
    if (this.edit) {
      this.editConfirmation.confirm();
    }

    this.userService.loadUser().subscribe(user => {
      if (user) {
        // A suggestionSenderID deklarálva volt és el is ment a szerverre, de SOHA nem
        // kapott értéket — ezért maradt a `sender_user_id` mindig NULL, és ezért nem
        // lehetett az adminfelületen a beküldőt felhasználóhoz kötni.
        if (user.uid) {
          this.suggestionSenderID.setValue(user.uid);
          this.suggestionSenderID.updateValueAndValidity();
        }
        this.suggestionSenderName.setValue(user.username);
        this.suggestionSenderName.updateValueAndValidity();
        this.suggestionSenderEmail.setValue(user.email);
        this.suggestionSenderEmail.updateValueAndValidity();
      }
    });
  }

  ngOnChanges(changes: SimpleChanges): void {
      if (changes['sensorEvents']) {
      }
      this.reLoadCalendar();
  }


  ngAfterViewInit() {
    this.reLoadCalendar();
  }

  private loadEventsIntoCalendar(): Promise<CalendarEvent[]> {
    return new Promise(resolve => {
      this.periodService.generatedPeriods$
        .pipe(
          filter(periods => periods.length > 0),
          take(1) // Unsubscribe after first emission to prevent memory leaks
        )
        .subscribe(
          periods => {
            const events = MassUtil.createCalendarEvents(
              Array.from(this.masses.values()),
              periods,
              this.changedMasses,
              this.deletedMasses,
              this.deletedDates,
              this.translateService,
              this.otherChurchNames()
            );
            
            // Add sensor events to the calendar
            const sensorCalendarEvents = this.convertSensorEventsToCalendarEvents();
            events.push(...sensorCalendarEvents);
            
            resolve(events);
          },
          error => {
            console.error('[Church Calendar] generatedPeriods$ error:', error);
          }
        );
      
      // Safety timeout: if subscription doesn't resolve within 10 seconds, resolve with empty array
      setTimeout(() => {
        resolve([]);
      }, 10000);
    });
  }

  // IMPROVED: Synchronous version that combines all mass sources and regenerates events
  private generateFreshCalendarEvents(): CalendarEvent[] {
    // Combine masses from both original and changes maps (changes override original)
    const combinedMasses = new Map<number, Mass>();
    
    // Add all original masses
    for (const mass of this.masses.values()) {
      combinedMasses.set(mass.id!, mass);
    }
    
    // Override with any pending changes
    for (const [id, changedMass] of this.changes.entries()) {
      combinedMasses.set(id, changedMass);
    }
    
    // Remove deleted masses
    for (const deletedId of this.deletedMasses) {
      combinedMasses.delete(deletedId);
    }
    
    // Get current periods - use getValue() to get the latest emitted value
    const periods = this.periodService.generatedPeriods$.getValue();
    if (!periods || periods.length === 0) {      
      return [];
    }

    // Generate calendar events from the combined mass set
    const events = MassUtil.createCalendarEvents(
      Array.from(combinedMasses.values()),
      periods,
      [], // changedMasses is empty since we're generating from combined set
      [], // deletedMasses is empty since we've already removed them
      this.deletedDates,
      this.translateService,
      this.otherChurchNames()
    );

    // Add sensor events to the calendar
    const sensorCalendarEvents = this.convertSensorEventsToCalendarEvents();
    events.push(...sensorCalendarEvents);

    // Szűrés a kategóriák alapján
    return this.filterCalendarEventsByCategory(events);
  }

  /**
   * Convert sensor events to FullCalendar events
   * Sensor events have no rrule and are single occurrences
   */
  private convertSensorEventsToCalendarEvents(): CalendarEvent[] {
    if (!this.sensorEvents || this.sensorEvents.length === 0) {
      return [];
    }

    return this.sensorEvents.map(sensor => {
    // Convert seconds to minutes and hours
    let duration = undefined;
    if (sensor.duration) {
      const totalMinutes = Math.floor(sensor.duration / 60);
      const hours = Math.floor(totalMinutes / 60);
      const minutes = totalMinutes % 60;
      duration = { hours, minutes };
    }

    return {
      title: sensor.title,
      duration: duration,
      rrule: {
        // Single event, no recurrence
        freq: 'daily',
        count: 1,
        dtstart: sensor.startDate
      },
      backgroundColor: 'white',
      borderColor: '#3788d9',
      textColor: '#3788d9',
      className: 'fc-event-sensor',
      extendedProps: {
        isSensorEvent: true,
        sensorEventId: sensor.id
      } as any
    } as CalendarEvent;
  });
  }

  private initializeCalendar(): void {
    const timeZone: string = this.currentChurch.timeZone;
    // use the options without FullCalendar's built-in toolbar so the custom Angular/Material header is the only visible header
    this.calendarOptions = CalendarUtil.getSimpleCalendarOptionsWithoutHeader(timeZone);

    // replace default no-events content to show loading / empty messages
    this.calendarOptions.noEventsContent = () => {
      return this.renderNoEventsContent();
    };

    this.calendarOptions = {
      ...this.calendarOptions,
      eventClick: (arg: any) => this.handleEventClick(arg),
      datesSet: (arg: any) => this.onDatesSet(arg),
      // #358: az események csak a datesSet UTÁN érkeznek be, ezért minden
      // event-set változásnál is újra-számoljuk a tömörítést.
      eventsSet: () => this.onEventsSetForCompression(),
      // #358: a KÖZÉPSŐ üres slotok megjelölése. A lane-td MINDEN sorban renderel
      // (a :30-as axis-cellával ellentétben, ami class-generator nélküli bare td),
      // ezért a slotLaneClassNames a megbízható horog. A faliidő kinyerése UGYANAZ,
      // mint a utilban (minuteOfDay) — a naptár időzóna-plugin nélkül UTC-mezőkben
      // tartja a faliidőt, ezért getHours() helyett getUTCHours() kell.
      slotLaneClassNames: (arg: any) =>
        (this.weekCompressionEnabled
          && this.currentViewType === 'timeGridWeek'
          && this.collapsedSlotMinutes.has(WeekCompressionUtil.minuteOfDay(arg.date)))
          ? ['fc-empty-slot'] : [],
      // Render custom event content so we can append a language flag ant types in list views
      eventContent: (info: any) => this.renderEventContent(info),
      noEventsContent: () => this.renderNoEventsContent(),
      ...((this.editable || this.suggestible) && {dateClick: (arg: any) => this.handleDateClick(arg)} ),
      eventDidMount: (info: any) => {
        const eventDate = info.event.startStr.slice(0, 10);
        const recentExDates:string[] = info.event.extendedProps['recentExDates'];
        const massTitleCategory = info.event.extendedProps['massTitleCategory'];
        
        if (recentExDates?.includes(eventDate)) {
            info.el.style.backgroundColor = '#ff4d4d';
            info.el.style.borderColor = '#ff4d4d';
        } else if (massTitleCategory) {
          // Kategória szín alkalmazása csak a title szövegre
          const color = MassUtil.getColorByCategory(massTitleCategory);
          
          const titleElement = info.el.querySelector('.fc-event-title');
          if (titleElement) {
            (titleElement as HTMLElement).style.color = color;
          }
        }
      },
      // Oszlopfők feliratainak testreszabása a nézet típusától függően
      dayHeaderContent: (arg: any) => {
        const viewType = this.calendarComponent?.getApi?.().view?.type || 'dayGridMonth';
        
        if (viewType.startsWith('list')) {
          // LIST VIEW: show day name, liturgical day, and formatted date
          return this.renderDayHeaderListView(arg);
        } else if (viewType === 'timeGridWeek') {
          // WEEK VIEW: show liturgical day and formatted date (without day name)
          return this.renderDayHeaderWeekView(arg);
        } else if (viewType === 'dayGridMonth') {
          // MONTH VIEW: show only day abbreviation (hétfő, kedd, szerda, etc.)
          return this.renderDayHeaderMonthView(arg);
        }
        
        // Fallback to month view rendering for unknown views
        return this.renderDayHeaderMonthView(arg);
      },
      // Oszlopfők osztályainak testreszabása a liturgikus nap szintje alapján (piros keret, ha level < 5)
      dayHeaderClassNames: (arg: any) => {
        const classNames: string[] = [];
        const date = arg.date;
        const dateStr = date.toISOString().split('T')[0]; // Format as YYYY-MM-DD
        const liturgicalDay = this.liturgicalDays[dateStr];

        // If liturgicalDay.level < 5: add red border
        if (liturgicalDay?.level !== undefined && liturgicalDay.level < 5) {
          classNames.push('liturgical-cell-bg-light-red');
        }

        return classNames;
      },
      // Hónap nézetben a nap cellájának fejlécének testreszabása a liturgikus nap szintje alapján
      dayCellContent: (arg: any) => {

        const viewType = this.calendarComponent?.getApi?.().view?.type || 'dayGridMonth';
        if(viewType !== 'dayGridMonth') {
          return { html: '' };
        }

        const date = arg.date;
        const dateStr = date.toISOString().split('T')[0]; // Format as YYYY-MM-DD
        const liturgicalDay = this.liturgicalDays[dateStr];
        const dayOfMonth = date.getDate(); // Get day of month (1-31)
        
        // If no liturgical day or level >= 5, just show the number (right-aligned, smaller)
        if (!liturgicalDay?.level || liturgicalDay.level >= 5) {
          const html = `<div style="text-align: right; font-size: 0.9em; padding: 2px 4px;">${dayOfMonth}</div>`;
          return { html };
        }
        
        // If liturgicalDay.level < 5, show number (floated right) and name (centered, multiple lines below)
        const html = `
          <div style="padding: 2px 4px;">
            <div style="float: right; font-size: 0.9em; padding-left: 4px;">
              ${dayOfMonth}
            </div>
            <div style="clear: both; text-align: center; font-size: 0.75em; line-height: 1.2; color: #666; word-wrap: break-word; overflow-wrap: break-word; white-space: normal;">
              ${liturgicalDay.name}
            </div>
          </div>
        `;
        return { html };
      
      },
      // Hónap és heti nézetben a nap cellájának osztályait is testreszabjuk a liturgikus nap szintje alapján)
      dayCellClassNames: (arg: any) => {
        const date = arg.date;
        const dateStr = date.toISOString().split('T')[0]; // Format as YYYY-MM-DD
        const liturgicalDay = this.liturgicalDays[dateStr];
        
        const classNames: string[] = [];

        // If liturgicalDay.level < 3: add red border
        if (liturgicalDay?.level !== undefined && liturgicalDay.level < 3) {
          classNames.push('liturgical-cell-bg-light-red');
        }
        
        // If liturgicalDay.level < 5: also add light red background
        if (liturgicalDay?.level !== undefined && liturgicalDay.level < 5) {
          classNames.push('liturgical-cell-bg-light-red');
        }
        
        return classNames;
      }
    };
  }

  /**
   * LIST VIEW: render day name, liturgical day, and full formatted date
   * Example: "hétfő  Hétfői napok  2026. május 18."
   */
  private renderDayHeaderListView(arg: any): { html: string } {
    const date = arg.date;

    // Get day name (vasárnap, hétfő, etc.)
    const dayOfWeekFormatter = new Intl.DateTimeFormat('hu-HU', { weekday: 'long' });
    const dayName = dayOfWeekFormatter.format(date);
    
    // Format date as "YYYY. hónap DD."
    const dateFormatter = new Intl.DateTimeFormat('hu-HU', { year: 'numeric', month: 'long', day: 'numeric' });
    const formattedDate = dateFormatter.format(date);
    
    // Get liturgical day for this date
    const dateStr = date.toISOString().split('T')[0]; // Format as YYYY-MM-DD
    const liturgicalDay = this.liturgicalDays[dateStr];
    const liturgicalDayText = liturgicalDay?.name || '';
    const shouldShowLiturgicalDay = liturgicalDay?.level !== undefined && liturgicalDay.level < 10;
    const html = `
      <a class="fc-list-day-text" aria-label="${formattedDate}">${dayName}</a>
      ${shouldShowLiturgicalDay ? `<a class="fc-list-day-center-text" style="font-weight:400">${liturgicalDayText}</a>` : ''}
      <a aria-hidden="true" class="fc-list-day-side-text " aria-label="${formattedDate}">${formattedDate}</a>
    `;
    
    return { html };
  }

  /**
   * WEEK VIEW: render liturgical day and formatted date (no day name)
   * Example: "Hétfői napok  2026. május 18."
   */
  private renderDayHeaderWeekView(arg: any): { html: string } {
    const date = arg.date;
    
    // Get day name (vasárnap, hétfő, etc.)
    const dayOfWeekFormatter = new Intl.DateTimeFormat('hu-HU', { weekday: 'long' });
    const dayName = dayOfWeekFormatter.format(date);

    // Format date as "YYYY. hónap DD."
    const dateFormatter = new Intl.DateTimeFormat('hu-HU', { year: 'numeric', month: 'long', day: 'numeric' });
    const formattedDate = dateFormatter.format(date);
    
    // Get liturgical day for this date
    const dateStr = date.toISOString().split('T')[0]; // Format as YYYY-MM-DD
    const liturgicalDay = this.liturgicalDays[dateStr];
    const shouldShowLiturgicalDay = liturgicalDay?.level !== undefined && liturgicalDay.level < 7;
    const liturgicalDayText = shouldShowLiturgicalDay ? (liturgicalDay?.name || '') : '';
    
    const html = `
      <a aria-hidden="true" class="fc-list-day-center-text" aria-label="${formattedDate}" >${dayName}</a><br/>
      ${shouldShowLiturgicalDay ? `<a class="fc-list-day-center-text" style="font-weight:400">${liturgicalDayText}</a>` : ''}
      
    `;
    
    return { html };
  }

  /**
   * MONTH VIEW: render only day abbreviation (hétfő, kedd, szerda, etc.)
   * Example: "H" for hétfő, "K" for kedd, "Sze" for szerda
   */
  private renderDayHeaderMonthView(arg: any): { html: string } {
    const date = arg.date;

    // Get day name (vasárnap, hétfő, etc.)
    const dayOfWeekFormatter = new Intl.DateTimeFormat('hu-HU', { weekday: 'long' });
    const dayName = dayOfWeekFormatter.format(date);
    
    const html = `
      <a class="fc-col-header-cell">${dayName}</a>
    `;
    
    return { html };
  }

  private handleEventClick(arg: any) {
    this.selectedDate = undefined;
    const extendedProps = arg.event.extendedProps;
    
    // Check if this is a sensor event
    if (extendedProps.isSensorEvent && extendedProps.sensorEventId) {
      this.openSensorEventPopup(extendedProps.sensorEventId);
    } else {
      // Regular mass event
      this.selectedMassId = extendedProps.massId;
      this.selectedEvent = arg.event;
      this.selectedEventStart = new Date(arg.event.startStr);
      this.openEventViewerDialog();
    }
  }

  private openSensorEventPopup(sensorEventId: string): void {
    const sensorEvent = this.sensorEvents.find(e => e.id === sensorEventId);
    if (!sensorEvent) {
      console.error('Sensor event not found:', sensorEventId);
      return;
    }

    const dialogRef = this.dialog.open(SensorEventPopupComponent, {
      data: {
        sensorEvent: sensorEvent,
        churchName: this.currentChurch.name
      } as SensorEventDialogData
    });
  }

  handleEventMount(info: any) {
    const massId = info.event.extendedProps['massId'];
    if (ScriptUtil.isNull(massId) || massId < 0) {
      info.el.style.border = '2px dashed #ff9800';
    }
    info.el.setAttribute('title', info.event.title);
  }

  openEventViewerDialog() {
    if (this.selectedMassId === undefined) {
      return;
    }

    //először megnézzük, hogy az újak/változottak közt ott van-e már
    let mass: Mass | undefined = undefined;
    if (this.changes.has(this.selectedMassId)) {
      mass = this.changes.get(this.selectedMassId);
    }

    //ha nincs ott, akkor megnézzük a többin
    if (!mass && this.masses.has(this.selectedMassId)) {
      mass = this.masses.get(this.selectedMassId);
    }

    if (!mass) {
      console.error('NINCS ILYEN MISE ID: ' + this.selectedMassId);
      alert('NINCS ILYEN MISE ID: ' + this.selectedMassId);
      return;
    }

    const dialogRef = this.dialog.open(EventViewerDialogComponent, {
      data: {churchName: this.currentChurch.name, mass: mass, suggestOrEditable: this.editable || this.suggestible, start: this.selectedEventStart, country: this.currentChurch.country}
    });

    dialogRef.afterClosed().subscribe(result => {
      this.processEventViewerDialogResult(result);
    });
  }

  /*
   * #592: a külső naptárból importált liturgiákat a napi szinkron teljes cserével írja
   * újra, ezért itt nem szerkeszthetők — a szerkesztés úgyis elveszne. A templom többi,
   * kézzel felvitt miséje viszont változatlanul szerkeszthető: a kettő megfér egymás mellett.
   */
  public isImported(m: any): boolean {
    return !!(m && m.imported);
  }

  private refuseImportedEdit(): void {
    this.snackBarService.warning(this.translateService.instant('EVENT_VIEWER.IMPORTED_NOT_EDITABLE'));
  }

  // Open the same event viewer popup when a mass row title is clicked in the editable mass list
  public openMassFromList(m: any): void {
    if (!m || !m.id) return;
    if (this.isImported(m)) {
      this.refuseImportedEdit();
      return;
    }
    this.selectedMassId = m.id;
    this.selectedEventStart = m.startDate ? new Date(m.startDate) : undefined;

    // Ensure selectedMassId is defined for TS-safe map access
    if (this.selectedMassId === undefined || this.selectedMassId === null) {
      return;
    }
    const id: number = this.selectedMassId as number;

    // Instead of opening the EventViewer, open the full editor for the existing liturgy
    let mass: Mass | undefined = undefined;
    if (this.changes.has(id)) {
      mass = this.changes.get(id);
    } else if (this.masses.has(id)) {
      // clone to avoid mutating original until saved
      mass = ScriptUtil.clone(this.masses.get(id)!);
    }

    if (!mass) {
      console.error('NINCS ILYEN MISE ID: ' + id);
      return;
    }

    // Prepare dialog event for editing the existing liturgy (full editor)
    this.dialogEvent = MassUtil.massToDialogEvent(mass);
    if (mass.periodId) {
      this.dialogEvent.period =
        this.periodService.getCurrentGeneratedPeriodByPeriodId(mass.periodId, new Date(mass.startDate));
      this.setSpecialPeriodDays(mass);
    }
    this.openFullDialog('EDIT_MASS');
  }

  // Open copy dialog for a mass from the mass list
  public openCopyMassDialog(m: any): void {
    if (!m || !m.id) return;
    
    // Get the original Mass object
    let mass: Mass | undefined;
    if (this.changes.has(m.id)) {
      mass = this.changes.get(m.id);
    } else if (this.masses.has(m.id)) {
      mass = this.masses.get(m.id);
    }

    if (!mass) {
      console.error('NINCS ILYEN MISE ID: ' + m.id);
      return;
    }

    // Clone the mass and prepare it for creating a new one
    const clonedMass = ScriptUtil.clone(mass);
    
    // Prepare dialog event from the cloned mass (for "new mass" mode)
    this.dialogEvent = MassUtil.massToDialogEvent(clonedMass);
    
    // If the mass has a period, set it in the dialog event
    if (clonedMass.periodId) {
      this.dialogEvent.period =
        this.periodService.getCurrentGeneratedPeriodByPeriodId(clonedMass.periodId, new Date(clonedMass.startDate));
      this.setSpecialPeriodDays(clonedMass);
    }
    
    // Open the dialog with "COPY_NEW_MASS" title to indicate it's a new mass creation
    this.openFullDialog('COPY_NEW_MASS');
  }

  private processEventViewerDialogResult(result: any) {
    if (this.selectedMassId === undefined) {
      return;
    }

    if (result === DialogResponse.DELETE_ONE) {
      if (!this.changes.has(this.selectedMassId)) {
        const mass = this.masses.get(this.selectedMassId);
        if (mass) {
          this.changes.set(this.selectedMassId, ScriptUtil.clone(mass));
        }
      }
      if (this.changes.has(this.selectedMassId)) {
        const mass = this.changes.get(this.selectedMassId);
        if (mass) {
          if (this.selectedEventStart) {
            const currentStartStr = DateTimeUtil.getIsoString(this.selectedEventStart);
            if (mass.exdate) {
              mass.exdate.push(currentStartStr);
            } else {
              mass.exdate = [currentStartStr];
            }

            // IMPROVED: No manual calEvents manipulation - refreshCalendarAndMassList() handles regeneration
            this.refreshCalendarAndMassList();
          }
        }
      }
    } else if(result === DialogResponse.DELETE_ALL) {
      if (this.selectedMassId >= 0) {
        this.deletedMasses.push(this.selectedMassId);
      }
      if (this.changes.has(this.selectedMassId)) {
        this.changes.delete(this.selectedMassId);
      }

      // IMPROVED: No manual calEvents manipulation - refreshCalendarAndMassList() handles regeneration
      this.refreshCalendarAndMassList();

    } else if(result === DialogResponse.EVENT_VIEWER_EDIT_ALL || result === DialogResponse.EVENT_VIEWER_EDIT_ONE) {
      const editOne: boolean = result === DialogResponse.EVENT_VIEWER_EDIT_ONE;

      let mass: Mass | undefined;
      if (this.changes.has(this.selectedMassId)) {
        mass = this.changes.get(this.selectedMassId);
      } else if (this.masses.has(this.selectedMassId)) {
        mass = this.masses.get(this.selectedMassId);
      }

      if (mass) {
        this.dialogEvent = editOne ? MassUtil.massToDialogEventEditOne(mass, this.selectedEventStart!) : MassUtil.massToDialogEvent(mass);
        if (!editOne && mass.periodId) {
          this.dialogEvent.period =
            this.periodService.getCurrentGeneratedPeriodByPeriodId(mass.periodId, new Date(mass.startDate));
          this.setSpecialPeriodDays(mass);
        }
        this.openFullDialog('EDIT_MASS');
      }
    }
  }

  private handleDateClick(arg: any) {
    const viewType = this.calendarComponent.getApi().view.type;
    this.selectedMassId = undefined;
    this.selectedEvent = undefined;
    this.selectedEventStart = undefined;
    this.selectedDate = new Date(arg.dateStr);

    // For month and other calendar views use the confirmation -> simple add flow
    // so users are asked once and then shown the simple add dialog instead of the full editor.
    this.openEditDialog();
  }

  openEditDialog() {
    if (this.selectedDate === undefined) {
      return;
    }

    if(!this.edit) {
      // If the user already confirmed in this app instance, enable edit immediately
      if (this.editConfirmation.isConfirmed()) {
        this.edit = true;
        this.openSimpleDialog();
        return;
      }

      const messageDialogRef = this.dialog.open(AddMessageDialogComponent, {
        data: {message: this.editConfirmation.getMessage(), decision: true}
      });

      messageDialogRef.afterClosed().subscribe(result => {
        if (result === DialogResponse.CONTINUE) {
          this.edit = true;
          this.editConfirmation.confirm();
          this.openSimpleDialog();
        }
      });
    } else{
      this.openSimpleDialog();
    }
  }

  /**
   * Megnyit egy egyszerű, csak olvasható felugró ablakot, amiben a paraméterül kapott dátumnak megfelelően kiírásra
   * kerül az újonnan felvenni kívánt mise időpontja
   * Ezt vagy el lehet menteni, vagy a továbbnavigálni egy részletes beállítási felületre
   */
  openSimpleDialog() {
    if (this.selectedDate === undefined) {
      return;
    }

    const dialogRef = this.dialog.open(AddSimpleEventDialogComponent, {
      data: {dateTime: this.selectedDate, title: MassUtil.getSimpleTitle4Church(this.currentChurch!)}
    });

    dialogRef.afterClosed().subscribe(result => {
      if (result === DialogResponse.SAVE_SIMPLE) {
        this.saveSimpleEvent();
      } else if(result === DialogResponse.MORE_DETAILS) {
        this.dialogEvent = CalendarUtil.generateDialogEvent(this.currentChurch, this.translateService, this.selectedDate);
        this.openFullDialog('ADD_NEW_MASS', this.selectedDate);
      }
    });
  }

  private saveSimpleEvent() {
    if (this.selectedDate === undefined) {
      return;
    }

    const newMassId = MassUtil.generateTmpMassId();
    const simpleMass: Mass = MassUtil.createSimpleMassByDate(this.selectedDate, this.currentChurch!, newMassId, this.translateService);

    this.changes.set(simpleMass.id!, simpleMass);

    // IMPROVED: No manual calEvents manipulation - refreshCalendarAndMassList() handles regeneration
    this.refreshCalendarAndMassList();
  }

  openFullDialog(title: string, date?: Date) {
    if (date) {
      this.dialogEvent!.start = date;
    }

    // #308 (borazslo review): a templom már létező miséinek periódusait
    // előnyben részesítjük az alapértelmezett periódus-választáskor.
    // A betöltött masses + folyamatban lévő changes összesítve, hogy a
    // még el nem mentett új miserend is számítson.
    const existingPeriodIds: number[] = [];
    const seenPeriodIds = new Set<number>();
    const addPeriodId = (periodId?: number | null) => {
      if (ScriptUtil.isNotNull(periodId) && !seenPeriodIds.has(periodId)) {
        seenPeriodIds.add(periodId);
        existingPeriodIds.push(periodId);
      }
    };
    for (const m of this.masses.values()) {
      addPeriodId(m.periodId);
    }
    for (const m of this.changes.values()) {
      addPeriodId(m.periodId);
    }

    const dialogRef = this.dialog.open(AddFullEventDialogComponent, {
      data: {title: title, event: this.dialogEvent, existingPeriodIds: existingPeriodIds, country: this.currentChurch.country}
    });

    dialogRef.afterClosed().subscribe(result => {

      if (ScriptUtil.isNull(this.dialogEvent)) {
        return;
      }

      if (result === 'SAVE') {
        if (this.dialogEvent.editOne) {
          //EBBEN AZ ESETBEN LÉTREHOZUNK EGY TELJESEN ÚJ MISÉT, AMI NEM TARTOZIK A SZÜLŐHÖZ
          const parentMassId: number | undefined = this.selectedMassId;
          const newMassId: number = MassUtil.generateTmpMassId();
          const newSingleMass: Mass = MassUtil.createMass(
            MassUtil.createEventByType(this.dialogEvent, newMassId, undefined, this.translateService),
            this.dialogEvent,
            this.currentChurch!,
            newMassId
          );

          if (parentMassId) {
            let parentMass: Mass | undefined;
            if (this.changes.has(parentMassId)) {
              parentMass = this.changes.get(parentMassId);
            } else if(this.masses.has(parentMassId)) {
              parentMass = ScriptUtil.clone(this.masses.get(parentMassId)!);
            }

            if (parentMass) {
              const startStr = DateTimeUtil.getIsoString(this.selectedEventStart!);
              if (parentMass.exdate) {
                parentMass.exdate.push(startStr);
              } else {
                parentMass.exdate = [startStr];
              }

              this.changes.set(parentMassId, parentMass);
            }
          }

          this.changes.set(newSingleMass.id!, ScriptUtil.clone(newSingleMass));

          // IMPROVED: No manual calEvents manipulation - refreshCalendarAndMassList() handles regeneration
          this.refreshCalendarAndMassList();

        } else {
          const newMassId: number = this.selectedMassId ? this.selectedMassId : MassUtil.generateTmpMassId();
          const periodId = this.dialogEvent.period?.periodId;
          const periodWeight = this.dialogEvent.period?.weight;
          const specialPeriodType = this.periodService.getSpecialPeriodType(periodId);
          const calendarEvent: CalendarEvent = MassUtil.createEventByType(this.dialogEvent, newMassId, specialPeriodType, this.translateService);
          const mass: Mass = MassUtil.createMass(calendarEvent, this.dialogEvent, this.currentChurch!, newMassId);

          // #352: ha a felhasználó csak rányomott egy meglévő mise módosítására és semmit nem
          // változtatott, ne tegyük változási javaslattá - nem küldünk fantom javaslatot, és
          // nem futtatjuk feleslegesen az exclusion oldalhatásokat sem.
          if (this.selectedMassId) {
            const original = this.changes.get(this.selectedMassId) ?? this.masses.get(this.selectedMassId);
            if (original && SuggestionUtil.isMassUnchanged(original, mass)) {
              return;
            }
          }

          const recentlyExclusionSourcePeriodIds: number[] = this.excludeNewMassFromLowerPeriodMasses(periodId, periodWeight);

          const recentlyExcludedPeriodIds = this.excludeHigherPeriodMassesFromNewMass(mass, periodId, periodWeight);

          this.showExclusionDialogIfNeed(periodId!, recentlyExclusionSourcePeriodIds, recentlyExcludedPeriodIds);

          this.changes.set(mass.id!, ScriptUtil.clone(mass));

          // IMPROVED: No manual calEvents manipulation - refreshCalendarAndMassList() handles regeneration
          this.refreshCalendarAndMassList();
        }
      }
    });
  }

  public onSaveCalendar() {
    // Prepare combined view of masses after pending changes/deletes so we can check for Easter-period masses
    const combined = new Map<number, Mass>();
    for (const m of this.masses.values()) {
      combined.set(m.id!, m);
    }
    for (const [id, changed] of this.changes.entries()) {
      combined.set(id, changed);
    }
    for (const del of this.deletedMasses) {
      if (combined.has(del)) combined.delete(del);
    }
    // Check whether any remaining mass belongs to a period that is an Easter or Christmas period
    let hasEasterMass = false;
    let hasChristmasMass = false;
    for (const m of combined.values()) {
      if (ScriptUtil.isNotNull(m.periodId)) {
      if (this.periodService.isEasterPeriod(m.periodId)) {
        hasEasterMass = true;
      }
      if (this.periodService.isChristmasPeriod(m.periodId)) {
        hasChristmasMass = true;
      }
      if (hasEasterMass && hasChristmasMass) break;
      }
    }

    const proceedWithSave = () => {
      this.spinnerService.show();
      const changesArray = Array.from(this.changes.values());
      this.eventService.saveChanges(this.currentChurch!.id, changesArray, this.deletedMasses).subscribe(masses => {
      this.changes.clear();
      this.deletedMasses = [];
      this.masses = new Map(masses.map(e => [e.id!, e]));
      this.reLoadCalendar();
      this.snackBarService.success('Sikeres mentés!');

      //TODO: EZT MAJD HÁTTÉRBEN
      const currentYear = new Date().getFullYear();
      const years: number[] = [currentYear - 1, currentYear, currentYear + 1];
      this.searchService.generateMasses(years, this.currentChurch!.id).subscribe();
      });
    };

    // If either Easter or Christmas is missing, ask confirmation with appropriate message(s)
    if (!hasEasterMass || !hasChristmasMass) {
      let msg = '';
      if (!hasEasterMass && !hasChristmasMass) {
      msg = 'Ehhez a templomhoz nincs húsvéti és karácsonyi misrend megadva. Mentsük így?';
      } else if (!hasEasterMass) {
      msg = 'Ehhez a templomhoz nincs húsvéti misrend megadva. Mentsük így?';
      } else { // !hasChristmasMass
      msg = 'Ehhez a templomhoz nincs karácsonyi misrend megadva. Mentsük így?';
      }

      const dialogRef = this.dialog.open(AddMessageDialogComponent, {
      data: { message: msg, decision: true }
      });
      dialogRef.afterClosed().subscribe(result => {
      if (result === DialogResponse.CONTINUE) {
        proceedWithSave();
      }
      });
      return;
    }
    
    // Normal path: Easter masses exist, proceed with save immediately
    proceedWithSave();
  }

  public onSendToApprove() {
    const generatedSuggestions = SuggestionUtil.generateSuggestions(this.masses, this.changes, this.deletedMasses);

    if (generatedSuggestions.length === 0) {
      this.snackBarService.error('Üres javaslatot nem lehet beküldeni.');
      return;
    }

    this.spinnerService.show();

    const suggestionPackage: SuggestionPackage = {
      churchId: this.currentChurch!.id,
      senderName: this.suggestionSenderName.value,
      senderEmail: this.suggestionSenderEmail.value,
      senderUserId: this.suggestionSenderID.value,
      senderMessage: this.suggestionSenderMessage.value,
      suggestions: generatedSuggestions,
      state: SuggestionState.PENDING,
      createdAt: new Date()
    }

    this.eventService.sendToApprove(this.currentChurch!.id, suggestionPackage).subscribe(
      res => {
        this.changes.clear();
        this.deletedMasses = [];
        this.deletedDates.clear();
        this.reLoadCalendar();
        this.dialog.open(AddMessageDialogComponent, {
          data: {message: "Javaslatod sikeresen beküldve! Amint jóváhagyják, megjelenik a naptárban.", decision: false}
        });
      },
      error => {
        console.error('Error sending suggestion to approve:', error);
        this.spinnerService.hide();
        // snackBarService.error már meghívódott az event.service-ben
      }
    );
  }

  public onAcceptSuggestion(selectedSuggestionPackage: SuggestionPackage, origMasses: Map<number, Mass>): Observable<{
    suggestionPackages: SuggestionPackage[];
    calendarMasses: Mass[]
  }> {

    selectedSuggestionPackage.suggestions.forEach(suggestion => {
      if (suggestion.changes && suggestion.changes.id && suggestion.changes.id < 0) {
        delete suggestion.changes.id;
      }
    });

      return this.eventService.simpleAcceptSuggestionPackage(selectedSuggestionPackage);
  }

  onRejectSuggestion(selectedSuggestionPackage: SuggestionPackage, notifySender: boolean = false) : Observable<{
    suggestionPackages: SuggestionPackage[];
    calendarMasses: Mass[]
  }>  {
    return this.eventService.simpleRejectSuggestionPackage(selectedSuggestionPackage, notifySender);
  }

  public reLoadCalendar() {
    if (this.calendarComponent) {
      // mark loading state so the calendar can show the 'loading' placeholder
      this.loadingEvents = true;
      this.loadedEvents = false;

      this.loadEventsIntoCalendar().then(events => {
        this.calEvents = events;
        this.calendarComponent.getApi().removeAllEvents();
        this.calendarComponent.getApi().removeAllEventSources();
        this.calendarComponent.getApi().addEventSource(events);
        this.spinnerService.hide();

        // update loading flags
        this.loadingEvents = false;
        this.loadedEvents = true;
        if (events && events.length > 0) this.everHadEvents = true;
        
        // Force calendar re-render to update noEventsContent when events are empty
        // This is critical when API returns empty masses: [] - without this, the loading message gets stuck
        try {
          this.calendarComponent.getApi().render();
        } catch (e) {
          // calendar not initialized yet - ignore
        }

        // rebuild the editable mass list when in edit/admin context
        if (this.showMassListInEdit) {
          this.buildMassList();
        }
      });
    }
  }

  // Ensure FullCalendar shows current calEvents and rebuild editable list when visible
  // IMPROVED: Now regenerates events from current masses/changes/deletedMasses to guarantee consistency
  private refreshCalendarAndMassList(): void {
    // Regenerate all calendar events from the current data model (combines masses/changes/deletedMasses)
    const freshEvents = this.generateFreshCalendarEvents();
    this.calEvents = freshEvents; // Update with freshly generated events

    // Update the calendar with the fresh events
    if (this.calendarComponent && this.calendarComponent.getApi) {
      try {
        this.calendarComponent.getApi().removeAllEvents();
        this.calendarComponent.getApi().removeAllEventSources();
        this.calendarComponent.getApi().addEventSource(freshEvents);
      } catch (e) {
        // calendar not initialized yet or api error - ignore
      }
    }

    // Rebuild the editable mass list
    if (this.showMassListInEdit) {
      this.buildMassList();
    }
  }

  onResetCalendar() {
    this.spinnerService.show();
    this.changes.clear();
    this.deletedMasses = [];
    this.reLoadCalendar();
  }

  public next() {
    this.calendarComponent.getApi().next();
  }

  public prev() {
    this.calendarComponent.getApi().prev();
  }

  // Accept listWeek as well so template buttons can call changeView('listWeek') without type errors
  public changeView(view: 'dayGridDay' | 'dayGridMonth' | 'timeGridWeek' | 'listWeek') {
    this.calendarComponent.getApi().changeView(view as any);
  }

  // #323: ha a látható nézet húsvét/karácsony időszakot is tartalmaz, és a
  // templomnak nincs ilyen periódusú miséje, valószínű hibás/hiányos a
  // miserend. UI flag-ek a template banner-éhez.
  public missingEasterMassWarning = false;
  public missingChristmasMassWarning = false;

  private onDatesSet(arg : any) {
    const title: string = arg.view.title;
    this.datesSet.emit(title);
    this.setCalendarsTitle(title);

    // #358: a heti nézet idősáv-tömörítés újra-számolása minden dátum-váltáskor.
    this.currentViewType = arg.view.type;
    this.evaluateWeekCompression(arg.view);

    // Fetch liturgical days for the current view date range
    const start = arg.view.currentStart;
    const end = arg.view.currentEnd;
    this.fetchLiturgicalDays(start, end);

    // #323: ellenőrizzük, hogy a nézet érint-e karácsonyi vagy húsvéti
    // periódust, és a templomnak van-e ilyen mise. Ha érinti, de nincs
    // mise → warning banner.
    this.checkSpecialPeriodCoverage(start, end);
  }

  private checkSpecialPeriodCoverage(viewStart: Date, viewEnd: Date): void {
    // Csak ott jelezzünk, ahol értelmes (a felhasználó éppen szerkeszti vagy
    // megnézi a templomot - suggestion review pl. nem).
    if (ScriptUtil.isNull(this.currentChurch)) {
      this.missingEasterMassWarning = false;
      this.missingChristmasMassWarning = false;
      return;
    }

    const normalize = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate());
    const vStart = normalize(viewStart);
    const vEnd = normalize(viewEnd);

    let viewIntersectsEaster = false;
    let viewIntersectsChristmas = false;

    for (const gp of this.periodService['generatedPeriods$'].getValue()) {
      const specialType = this.periodService.getSpecialPeriodType(gp.periodId);
      if (specialType !== SpecialType.EASTER && specialType !== SpecialType.CHRISTMAS) {
        continue;
      }
      const gpStart = normalize(new Date(gp.startDate));
      const gpEnd = normalize(new Date(gp.endDate));
      // intervallum-átfedés: nem (vEnd < gpStart vagy gpEnd < vStart)
      if (vEnd < gpStart || gpEnd < vStart) {
        continue;
      }
      if (specialType === SpecialType.EASTER) viewIntersectsEaster = true;
      if (specialType === SpecialType.CHRISTMAS) viewIntersectsChristmas = true;
    }

    let churchHasEasterMass = false;
    let churchHasChristmasMass = false;
    for (const m of this.masses.values()) {
      if (ScriptUtil.isNull(m.periodId)) continue;
      if (this.periodService.isEasterPeriod(m.periodId)) churchHasEasterMass = true;
      if (this.periodService.isChristmasPeriod(m.periodId)) churchHasChristmasMass = true;
      if (churchHasEasterMass && churchHasChristmasMass) break;
    }

    this.missingEasterMassWarning = viewIntersectsEaster && !churchHasEasterMass;
    this.missingChristmasMassWarning = viewIntersectsChristmas && !churchHasChristmasMass;
  }

  /**
   * #358: a FullCalendar API-jából kiolvassuk a megjelenített eseményeket,
   * a WeekCompressionUtil-lel kiszámoljuk az ajánlott slot-tartományt, majd
   * alkalmazzuk vagy visszaállítjuk a default 00:00-24:00 ablakot.
   *
   * Csak `timeGridWeek` nézetben fut, és csak ha a felhasználói toggle aktív.
   * Egyébként visszaáll a default tartomány (ne ragadjon be egy korábbi tömörítés).
   */
  private evaluateWeekCompression(view: any): void {
    if (!this.calendarComponent) {
      this.weekCompressionResult = null;
      this.collapsedSlotMinutes = new Set<number>();
      return;
    }
    const api = this.calendarComponent.getApi();

    if (view.type !== 'timeGridWeek' || !this.weekCompressionEnabled) {
      this.weekCompressionResult = null;
      this.collapsedSlotMinutes = new Set<number>();
      this.applyCompression(api, '00:00:00', '24:00:00', [], false);
      return;
    }

    // #358 fix: a misék pont-események, gyakran `end` NÉLKÜL — a régi
    // `!!e.start && !!e.end` szűrő mindet kidobta, így a toggle némán nem
    // csinált semmit. A leképezés a WeekCompressionUtil-ban él, hogy tesztelhető
    // legyen (pont ez a glue-kód volt fedetlen, amikor a hiba bekerült).
    const weekEvents: WeekEvent[] = WeekCompressionUtil.toWeekEvents(api.getEvents());

    const result = WeekCompressionUtil.analyze({
      weekStart: view.currentStart,
      weekEnd: view.currentEnd,
      events: weekEvents,
      options: {slotDurationMinutes: 30},
    });

    this.weekCompressionResult = result;

    if (result.shouldCompress) {
      this.collapsedSlotMinutes = new Set<number>(result.collapsedSlotMinutes);
      this.applyCompression(api, result.slotMinTime, result.slotMaxTime, result.collapsedSlotMinutes, true);
    } else {
      this.collapsedSlotMinutes = new Set<number>();
      this.applyCompression(api, '00:00:00', '24:00:00', [], false);
    }
  }

  /**
   * #358: slotMin/Max + height + a collapsed-lane újrarajzolás egy helyen.
   * A slotLaneClassNames csak re-render-kor fut újra: a setOption(slotMinTime/
   * height) a szokásos úton kikényszeríti; ha viszont CSAK a collapsed-halmaz
   * változott (a slotMin/Max ugyanaz), explicit api.render() kell — az aláírás
   * alapján pontosan egyszer, hogy ne rendereljünk kétszer.
   */
  private applyCompression(api: any, slotMin: string, slotMax: string, collapsed: number[], compress: boolean): void {
    try {
      let optionChanged = false;
      if (api.getOption('slotMinTime') !== slotMin) { api.setOption('slotMinTime', slotMin); optionChanged = true; }
      if (api.getOption('slotMaxTime') !== slotMax) { api.setOption('slotMaxTime', slotMax); optionChanged = true; }
      // height:'auto' a tömörített nézetnél (különben ~340px üres sáv alul); egyébként
      // a default 600px scroller. expandRows-t NEM állítunk (false marad, különben
      // vissza-nyújtaná az összehúzott sorokat).
      const targetHeight = compress ? 'auto' : '600px';
      if (api.getOption('height') !== targetHeight) { api.setOption('height', targetHeight); optionChanged = true; }

      const sig = slotMin + '|' + slotMax + '|' + collapsed.join(',');
      if (sig !== this.lastCompressionSignature) {
        this.lastCompressionSignature = sig;
        // ha egy setOption már re-render-t váltott, ne rendereljünk kétszer;
        // ha csak a collapsed-halmaz változott, itt kényszerítjük ki.
        if (!optionChanged) {
          api.render();
        }
      }
    } catch (e) {
      // API not ready / runtime error — biztonságos no-op
    }
  }

  /**
   * #358: az events:set callback-jéből hívva — újra-számolja a tömörítést
   * az aktuális heti nézet eseményeivel. (A `datesSet` egyszer csak a nézet
   * megnyitásakor fut, de az események később, async módon érkeznek.)
   */
  private onEventsSetForCompression(): void {
    if (!this.calendarComponent || !this.calendarComponent.getApi) return;
    const view = this.calendarComponent.getApi().view;
    if (view) {
      this.evaluateWeekCompression(view);
    }
  }

  /**
   * #358: a felhasználó által ki-/bekapcsolható tömörítés.
   * Toggle után újraértékeljük az aktuális heti nézet alapján.
   */
  public toggleWeekCompression(): void {
    this.weekCompressionEnabled = !this.weekCompressionEnabled;
    if (this.calendarComponent && this.calendarComponent.getApi) {
      const api = this.calendarComponent.getApi();
      this.evaluateWeekCompression(api.view);
    }
  }

  /** Tooltip a toggle-gombhoz: a diagnostics-ot emberi olvasásra fordítja. */
  public getWeekCompressionTooltip(): string {
    if (!this.weekCompressionEnabled) {
      return 'Heti nézet idősáv-tömörítés: kikapcsolva (kattintsd bekapcsoláshoz)';
    }
    const r = this.weekCompressionResult;
    if (!r) {
      return 'Heti nézet idősáv-tömörítés: bekapcsolva';
    }
    if (r.shouldCompress) {
      return `Tömörítve: ${r.slotMinTime.slice(0, 5)}–${r.slotMaxTime.slice(0, 5)}, `
        + `a középső üres sáv (${r.diagnostics.gapStart}–${r.diagnostics.gapEnd}, ${r.diagnostics.gapSizeHours} ó) összehúzva. `
        + `Kattintsd kikapcsoláshoz.`;
    }
    const reasonMap: Record<string, string> = {
      'no-events': 'nincs esemény ezen a héten',
      'too-few-events': 'túl kevés esemény a tömörítéshez',
      'no-gap-detected': 'nincs felismerhető üres sáv (reggel-este)',
      'gap-too-small': 'a felismert üres sáv túl kicsi',
      'compressed': '',
    };
    return `Nincs tömörítés: ${reasonMap[r.diagnostics.reason] || r.diagnostics.reason}. `
      + `Kattintsd kikapcsoláshoz.`;
  }

  private fetchLiturgicalDays(start: Date, end: Date): void {
    // Convert dates to ISO 8601 format (without milliseconds)
    const formatDate = (date: Date): string => {
      const iso = date.toISOString();
      return iso.replace(/\.\d{3}Z$/, ''); // Remove .000Z suffix
    };
    
    const fromDate = formatDate(start);
    const untilDate = formatDate(end);
    
    this.eventService.getLiturgicalDays(fromDate, untilDate).subscribe(
      (days) => {
        this.liturgicalDays = days || {};
        
        // Force re-render of the calendar view to show day headers with liturgical day data
        // This is needed because dayHeaderContent callback is called before API response arrives
        if (this.calendarComponent && this.calendarComponent.getApi) {
          try {
            this.calendarComponent.getApi().render();
          } catch (e) {
            console.log('[Liturgical Days] Could not trigger calendar render:', e);
          }
        }
      },
      (error) => {
        console.error('[Liturgical Days] Error fetching liturgical days:', error);
        this.liturgicalDays = {};
      }
    );
  }

  /**
   * Ütközésvizsgálat - csak ha ismétlődő esemény - csak a kisebb súlyúakból zárunk ki
   * új mise felvételénél, nézzük meg, hogy van-e periódusa
   * ha van, akkor az összes kisebb periódussúlyú miséhez adjuk hozzá ezt, mint egy eleme az experiodnak
   */
  private excludeNewMassFromLowerPeriodMasses(periodId?: number, periodWeight?: number): number[] {
    const recentlyExclusionSourcePeriodIds: number[] = [];

    if (ScriptUtil.isNotNull(periodId) && ScriptUtil.isNotNull(periodWeight) && periodWeight > 1) {
      const lowerPeriodWeightMassIds: number[] = [];
      for (const m of this.masses.values()) {
        if (ScriptUtil.isNotNull(m.periodId)) {
          const mPeriod = this.periodService.getPeriodById(m.periodId);
          if (ScriptUtil.isNotNull(mPeriod) && mPeriod.weight < periodWeight) {
            lowerPeriodWeightMassIds.push(m.id);
          }
        }
      }

      for (const m of this.changes.values()) {
        if (ScriptUtil.isNotNull(m.periodId)) {
          const mPeriod = this.periodService.getPeriodById(m.periodId);
          if (ScriptUtil.isNotNull(mPeriod) && mPeriod.weight < periodWeight) {
            if (!lowerPeriodWeightMassIds.includes(m.id)) {
              lowerPeriodWeightMassIds.push(m.id);
            }
          }
        }
      }

      let globalChanged: boolean = false;

      for (let mId of lowerPeriodWeightMassIds) {
        let m = this.changes.get(mId);
        if (ScriptUtil.isNull(m) && this.masses.has(mId)) {
          m = ScriptUtil.clone(this.masses.get(mId));
        }
        if (ScriptUtil.isNull(m)) {
          console.error(`Hiányzó mise: ${mId}`);
          continue;
        }

        let changed: boolean = false;
        if (ScriptUtil.isNotNull(m.periodId) && m.periodId === periodId) {
          changed = false;
        } else if (ScriptUtil.isNotNull(m.experiod)) {
          if (!m.experiod.includes(periodId) && m.periodId !== periodId) {
            m.experiod.push(periodId);
            changed = true;
          }
        } else if (m.periodId !== periodId) {
          m.experiod = [periodId];
          changed = true;
        }
        if (changed) {
          this.changes.set(mId, m);
          globalChanged = true;

          //ha még nem volt hasonló üzenet, hogy a most hozzáadott mise periódusát kizárjuk ebből az időszakból, akkor majd most megtesszük
          if (ScriptUtil.isNotNull(m.periodId) && !recentlyExclusionSourcePeriodIds.includes(m.periodId) &&
              this.hasPreviouslySentNotification(m.periodId, periodId)) {
            recentlyExclusionSourcePeriodIds.push(m.periodId);
          }
        }
      }

      if (globalChanged) {
        // IMPROVED: No manual calEvents manipulation - refreshCalendarAndMassList() handles regeneration
        this.refreshCalendarAndMassList();
      }
    }
    return recentlyExclusionSourcePeriodIds;
  }

  /**
   * Itt végignézzük, hogy milyen ennél nagyobb periódussúlyú misék vannak, és azokat kizárjuk ebből
   * Ha pl. volt már nyár felvéve, akkor ha most egész éveset hozok létre, akkor a nyarat kizárjuk ebből
   */
  private excludeHigherPeriodMassesFromNewMass(mass: Mass, periodId?: number, periodWeight?: number): number[] {
    const recentlyExcludedPeriodIds: number[] = [];

    if (ScriptUtil.isNotNull(periodId) && ScriptUtil.isNotNull(periodWeight)) {
      const higherPeriodIds: number[] = [];
      for (const m of this.masses.values()) {
        if (ScriptUtil.isNotNull(m.periodId)) {
          const mPeriod = this.periodService.getPeriodById(m.periodId);
          if (ScriptUtil.isNotNull(mPeriod) && mPeriod.weight > periodWeight && !higherPeriodIds.includes(m.periodId)) {
            higherPeriodIds.push(m.periodId);
          }
        }
      }

      for (const m of this.changes.values()) {
        if (ScriptUtil.isNotNull(m.periodId)) {
          const mPeriod = this.periodService.getPeriodById(m.periodId);
          if (ScriptUtil.isNotNull(mPeriod) && mPeriod.weight > periodWeight) {
            if (!higherPeriodIds.includes(m.periodId)) {
              higherPeriodIds.push(m.periodId);
            }
          }
        }
      }

      let globalChanged: boolean = false;

      if (higherPeriodIds.length > 0 && ScriptUtil.isNull(mass.experiod)) {
        mass.experiod = [];
      }
      higherPeriodIds.forEach(higherPeriodId => {
        if (!mass.experiod!.includes(higherPeriodId) && mass.periodId !== higherPeriodId) {
          mass.experiod!.push(higherPeriodId);
          globalChanged = true;

          //ha még nem volt hasonló üzenet, hogy most hozzáadott mise periódusából kizárjuk ezt az időszakot, akkor majd most megtesszük
          if (!recentlyExcludedPeriodIds.includes(higherPeriodId) && !this.hasPreviouslySentNotification(periodId, higherPeriodId)) {
            recentlyExcludedPeriodIds.push(higherPeriodId);
          }
        }
      });

      if (globalChanged) {
        this.changes.set(mass.id, mass);

        // IMPROVED: No manual calEvents manipulation - refreshCalendarAndMassList() handles regeneration
        this.refreshCalendarAndMassList();
      }
    }
    return recentlyExcludedPeriodIds;
  }

  private hasPreviouslySentNotification(periodId: number, excludedPeriodId: number): boolean {
    for (const mass of this.masses.values()) {
      if (mass.periodId === periodId && ScriptUtil.isNotNull(mass.experiod) && mass.experiod.includes(excludedPeriodId) ) {
       return true;
      }
    }
    for (const mass of this.changes.values()) {
      if (mass.periodId === periodId && ScriptUtil.isNotNull(mass.experiod) && mass.experiod.includes(excludedPeriodId) ) {
        return true;
      }
    }
    return false;
  }

  public openCopyPeriodDialog(group: any): void {
    if (!group || !group.weight || group.weight <= 0) {
      return;
    }

    const sourcePeriodId = group.weight ? this.getGroupPeriodId(group) : null;
    if (!sourcePeriodId) {
      return;
    }

    // Get the source period info
    const sourcePeriodInfo = this.periodService.getPeriodById(sourcePeriodId);

    // Get all available periods for selection (exclude the source period)
    const allPeriods = this.periodService.periods$.getValue();
    const availablePeriods = allPeriods.filter(p => p.id !== sourcePeriodId && p.selectable);

    // Count masses with this period
    const massCount = group.masses ? group.masses.length : 0;

    const dialogData: CopyPeriodDialogData = {
      sourcePeriodId: sourcePeriodId,
      sourcePeriodName: group.periodName,
      sourcePeriodInfo: sourcePeriodInfo || undefined,
      availablePeriods: availablePeriods,
      massCount: massCount
    };

    const dialogRef = this.dialog.open(CopyPeriodDialogComponent, {
      data: dialogData,
      width: '500px'
    });

    dialogRef.afterClosed().subscribe(result => {
      if (result && result.targetPeriodId) {
        this.copyMassesToNewPeriod(sourcePeriodId, result.targetPeriodId);
      }
    });
  }

  public openDeletePeriodDialog(group: any): void {
    if (!group || !group.weight || group.weight <= 0) {
      return;
    }

    const periodId = this.getGroupPeriodId(group);
    if (!periodId) {
      return;
    }

    // Get the period info
    const periodInfo = this.periodService.getPeriodById(periodId);
    if (!periodInfo) {
      return;
    }

    // Get generated periods for the color
    const generatedPeriods = this.periodService.getGeneratedPeriodsByPeriodId(periodId);

    // Count masses with this period
    const massCount = group.masses ? group.masses.length : 0;

    const dialogData: DeletePeriodDialogData = {
      period: periodInfo,
      generatedPeriods: generatedPeriods || [],
      massCount: massCount
    };

    const dialogRef = this.dialog.open(DeletePeriodDialogComponent, {
      data: dialogData,
      width: '500px'
    });

    dialogRef.afterClosed().subscribe(result => {
      if (result === true) {
        this.deletePeriodMasses(periodId);
      }
    });
  }

  // Open delete dialog for a mass from the mass list
  public openDeleteMassDialog(m: any): void {
    if (!m || !m.id) return;
    if (this.isImported(m)) {
      this.refuseImportedEdit();
      return;
    }

    // Get the original Mass object
    let mass: Mass | undefined;
    if (this.changes.has(m.id)) {
      mass = this.changes.get(m.id);
    } else if (this.masses.has(m.id)) {
      mass = this.masses.get(m.id);
    }

    if (!mass) {
      console.error('NINCS ILYEN MISE ID: ' + m.id);
      return;
    }

    // Prepare the dialog data for delete all
    const eventViewerData: EventViewerDialogData = {
      churchName: this.currentChurch.name,
      mass: mass,
      suggestOrEditable: this.editable || this.suggestible,
      start: new Date(m.startDate)
    };

    const deleteDialogData: DeleteDialogData = {
      eventData: eventViewerData,
      deleteOne: false
    };

    const messageDialogRef = this.dialog.open(DeleteWarningDialogComponent, {
      data: deleteDialogData
    });

    messageDialogRef.afterClosed().subscribe(result => {
      if (result === DialogResponse.CONTINUE) {
        // Delete all occurrences of this mass
        if (m.id >= 0) {
          this.deletedMasses.push(m.id);
        }
        if (this.changes.has(m.id)) {
          this.changes.delete(m.id);
        }

        // IMPROVED: No manual calEvents manipulation - refreshCalendarAndMassList() handles regeneration
        this.refreshCalendarAndMassList();
      }
    });
  }

  private getGroupPeriodId(group: any): number | null {
    // The group's period ID is stored implicitly in the massListGrouped structure
    // We need to find it by looking at the masses' periodId values
    if (group.masses && group.masses.length > 0) {
      const firstMass = group.masses[0];
      return firstMass.periodId || null;
    }
    return null;
  }

  private deletePeriodMasses(periodId: number): void {
    const massesToDelete: number[] = [];
    
    // Find all masses with this period ID
    for (const mass of this.masses.values()) {
      if (mass.periodId === periodId) {
        massesToDelete.push(mass.id);
      }
    }
    
    // Also check in changes
    for (const mass of this.changes.values()) {
      if (mass.periodId === periodId && !massesToDelete.includes(mass.id)) {
        massesToDelete.push(mass.id);
      }
    }
    
    // Remove from changes and add to deletedMasses if not a temporary mass
    for (const massId of massesToDelete) {
      if (this.changes.has(massId)) {
        this.changes.delete(massId);
      }
      
      // Only add to deletedMasses if it's not a temporary ID (negative numbers)
      if (massId >= 0) {
        if (!this.deletedMasses.includes(massId)) {
          this.deletedMasses.push(massId);
        }
      }
    }
    
    // IMPROVED: No manual calEvents manipulation - refreshCalendarAndMassList() handles regeneration
    this.refreshCalendarAndMassList();
    
    const periodName = this.periodService.getPeriodNameById(periodId);
    this.snackBarService.success(`${massesToDelete.length} mise törölve az "${periodName}" időszakból.`);

    // Check if there are any remaining masses with this periodId
    const remainingMassesWithPeriod = Array.from(this.masses.values()).some(m => m.periodId === periodId) ||
                                     Array.from(this.changes.values()).some(m => m.periodId === periodId);
    
    // If no remaining masses with this periodId, remove it from all other masses' experiod lists
    if (!remainingMassesWithPeriod) {
      this.removeExcludedPeriodFromAllMasses(periodId);
    }
  }

  /**
   * Eltávolítja az adott periodId-t az összes mise experiod listájából
   * Ezt akkor kell meghívni, amikor egy periódusból már nincs mise
   */
  private removeExcludedPeriodFromAllMasses(periodId: number): void {
    let changed = false;

    // Check all masses in the original masses map
    for (const mass of this.masses.values()) {
      if (ScriptUtil.isNotNull(mass.experiod) && mass.experiod.includes(periodId)) {
        mass.experiod = mass.experiod.filter(id => id !== periodId);
        this.changes.set(mass.id, ScriptUtil.clone(mass));
        changed = true;
      }
    }

    // Check all masses in the changes map
    for (const mass of this.changes.values()) {
      if (ScriptUtil.isNotNull(mass.experiod) && mass.experiod.includes(periodId)) {
        mass.experiod = mass.experiod.filter(id => id !== periodId);
        changed = true;
      }
    }

    // Refresh the calendar if any changes were made
    if (changed) {
      this.refreshCalendarAndMassList();
    }
  }

  private copyMassesToNewPeriod(sourcePeriodId: number, targetPeriodId: number): void {
    // Get all masses with the source period ID
    const massesToCopy: Mass[] = [];

    // From original masses
    for (const mass of this.masses.values()) {
      if (mass.periodId === sourcePeriodId) {
        massesToCopy.push(ScriptUtil.clone(mass));
      }
    }

    // From changes (pending edits)
    for (const mass of this.changes.values()) {
      if (mass.periodId === sourcePeriodId) {
        // Check if this mass is not already copied from originals
        const alreadyIncluded = massesToCopy.some(m => m.id === mass.id);
        if (!alreadyIncluded) {
          massesToCopy.push(ScriptUtil.clone(mass));
        } else {
          // Replace with the changed version
          const index = massesToCopy.findIndex(m => m.id === mass.id);
          if (index !== -1) {
            massesToCopy[index] = ScriptUtil.clone(mass);
          }
        }
      }
    }

    if (massesToCopy.length === 0) {
      this.snackBarService.warning('Nincs mise a kiválasztott időszakban.');
      return;
    }

    // Clone masses for the new period
    const targetPeriodWeight = this.periodService.getPeriodById(targetPeriodId)?.weight;
    let globalChanged = false;

    massesToCopy.forEach(massToClone => {
      // Create a new mass with the target period ID but without an ID (so API treats it as new)
      const newMass: Mass = ScriptUtil.clone(massToClone);
      newMass.id = MassUtil.generateTmpMassId(); // Generate new temporary ID
      newMass.periodId = targetPeriodId; // Set to new period
      // Keep all other properties: rrule, exdate, types, lang, comment, etc.

      // Remove the new period from the experiod list if it exists (a mise nem zárhatja ki magát)
      if (ScriptUtil.isNotNull(newMass.experiod)) {
        newMass.experiod = newMass.experiod.filter(id => id !== targetPeriodId);
        if (newMass.experiod.length === 0) {
          newMass.experiod = null;
        }
      }

      // #428: ugyanez a KÉZI kivétel-listára is – a másolt mise ne zárja ki saját új időszakát
      if (ScriptUtil.isNotNull(newMass.manualExperiod)) {
        newMass.manualExperiod = newMass.manualExperiod.filter(id => id !== targetPeriodId);
        if (newMass.manualExperiod.length === 0) {
          newMass.manualExperiod = null;
        }
      }

      // Add to changes map
      this.changes.set(newMass.id, newMass);
      globalChanged = true;
    });

    if (globalChanged) {
      // Recalculate excluded periods for the newly copied masses
      // This ensures proper experiod values based on period weights
      massesToCopy.forEach(sourceMass => {
        const newMasses = Array.from(this.changes.values()).filter(m =>
          m.periodId === targetPeriodId && m.id! < 0 // Temporary IDs are negative
        );
        
        newMasses.forEach(newMass => {
          const recentlyExclusionSourcePeriodIds = this.excludeNewMassFromLowerPeriodMasses(targetPeriodId, targetPeriodWeight);
          const recentlyExcludedPeriodIds = this.excludeHigherPeriodMassesFromNewMass(newMass, targetPeriodId, targetPeriodWeight);
          this.showExclusionDialogIfNeed(targetPeriodId, recentlyExclusionSourcePeriodIds, recentlyExcludedPeriodIds);
        });
      });

      // IMPROVED: Use refreshCalendarAndMassList() instead of reLoadCalendar() for immediate sync refresh
      this.refreshCalendarAndMassList();
      this.snackBarService.success(`${massesToCopy.length} mise sikeresen másolt az új időszakra.`);
    }
  }

  private showExclusionDialogIfNeed(periodId: number, recentlyExclusionSourcePeriodIds: number[], recentlyExcludedPeriodIds: number[]) {
    if (ScriptUtil.isNotNull(periodId) &&
      (recentlyExclusionSourcePeriodIds.length > 0 || recentlyExcludedPeriodIds.length > 0)) {

      const recentlyExclusionSourcePeriods = this.periodService.getGeneratedPeriodsByPeriodIds(recentlyExclusionSourcePeriodIds);
      const recentlyExcludedPeriods = this.periodService.getGeneratedPeriodsByPeriodIds(recentlyExcludedPeriodIds);
      const generatedPeriods = this.periodService.getGeneratedPeriodsByPeriodId(periodId);

      const filteredRecentlyExclusionSourcePeriodIds = this.filterOverlappingPeriodIds(
          generatedPeriods,
          recentlyExclusionSourcePeriods,
          recentlyExclusionSourcePeriodIds
      );

      const filteredRecentlyExcludedPeriodIds = this.filterOverlappingPeriodIds(
          generatedPeriods,
          recentlyExcludedPeriods,
          recentlyExcludedPeriodIds
      );

      if (filteredRecentlyExclusionSourcePeriodIds.length > 0 || filteredRecentlyExcludedPeriodIds.length > 0) {
        this.dialog.open(PeriodExclusionDialogComponent, {
          data: {
            periodName: this.periodService.getPeriodNameById(periodId),
            recentlyExcludedPeriodNames: this.periodService.getPeriodNamesByIds(filteredRecentlyExcludedPeriodIds),
            recentlyExclusionSourcePeriodNames: this.periodService.getPeriodNamesByIds(filteredRecentlyExclusionSourcePeriodIds)
          }
        });
      }
    }
  }

  get hasUnsavedChanges(): boolean {
    return this.changes.size > 0 || this.deletedMasses.length > 0;
  }

  public setCalendarsTitle(title: string) {
    setTimeout(() => {
      this.calendarsTitle = title;
    });
  }

  private setSpecialPeriodDays(mass: Mass) {
    if (this.periodService.isChristmasPeriod(mass.periodId!)) {
      this.dialogEvent!.selectedChristmasDay = MassUtil.getChristmasDayByMass(mass);
    }
    if (this.periodService.isEasterPeriod(mass.periodId!)) {
      this.dialogEvent!.selectedEasterDay = MassUtil.getEasterDayByMass(mass);
    }
  }

  private periodsOverlap(a: GeneratedPeriod, b: GeneratedPeriod): boolean {
    const aStart = new Date(a.startDate);
    const aEnd = new Date(a.endDate);
    const bStart = new Date(b.startDate);
    const bEnd = new Date(b.endDate);
    return aStart < bEnd && aEnd > bStart;
  }

  private filterOverlappingPeriodIds(
      currentPeriods: GeneratedPeriod[],
      targetPeriods: GeneratedPeriod[],
      targetIds: number[]
  ): number[] {
    return targetIds.filter(id => {
      const targetGroup = targetPeriods.filter(p => p.periodId === id);
      return targetGroup.some(t =>
          currentPeriods.some(c => this.periodsOverlap(c, t))
      );
    });
  }

  // Create event HTML that includes time, title, a flag, and other icons (if available)
  renderEventContent(info: any) {
    try {
      // Check if this is a sensor event - if so, apply special styling
      const isSensorEvent = info.event.extendedProps?.isSensorEvent === true;

      // determine current view type (use info.view when available)
      const viewType = info.view?.type || (this.calendarComponent ? this.calendarComponent.getApi().view.type : '');
      const isListView = typeof viewType === 'string' && viewType.startsWith('list');

      // helper to escape attribute values
      const escapeAttr = (s: any) => {
        if (s === null || s === undefined) return '';
        return String(s)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;')
          .replace(/\r?\n/g, ' ');
      };

      // Resolve mass info (if available) so both list and non-list views can show flags/types/comment
      const massId = info.event.extendedProps?.massId;
      let lang = null as string | null;
      let types: string[] = [];
      let comment: string | null = null;
      let mass: any | undefined = undefined;
      if (massId != null) {
        if (this.changes && this.changes.has(massId)) {
          mass = this.changes.get(massId);
        } else if (this.masses && this.masses.has(massId)) {
          mass = this.masses.get(massId);
        }
        if (mass && mass.lang) lang = mass.lang;
        if (mass && mass.types) types = mass.types;
        if (mass && mass.comment) comment = mass.comment;
      }

      const flagMap: Record<string, string> = { hu: '🇭🇺', en: '🇬🇧', de: '🇩🇪', sk: '🇸🇰', ro: '🇷🇴' };

      let flagHtml = '';
      // #334: lang can be a comma-separated list (e.g. "hu,fr"), so split it
      // and render a separate flag for each language — same as the list view template.
      if (lang) {
        for (const l of this.languagesOf(lang)) {
          if (this.shouldShowFlag(l)) {
            const lLower = String(l).toLowerCase();
            const src = `/cal_images/flags/${lLower}.svg`;
            const tooltip = this.translateService.instant('LANGUAGES.' + l);
            flagHtml += `<img class="type-icon" style="height:18px; margin-left:6px" title="${escapeAttr(tooltip)}" src="${src}" alt="${escapeAttr(tooltip)}" />`;
          }
        }
      }

      let typesHtml = '';
      if (Array.isArray(types) && types.length > 0) {
        for (const t of types) {
          const tLower = String(t).toLowerCase();
          typesHtml += `<img class="type-icon" style="height:18px; margin-left:6px" title="${escapeAttr(t)}" src="/cal_images/types/${tLower}.png" alt="${escapeAttr(t)}" />`;
        }
      }

      let commentHtml = '';
      if (comment) {
        const escaped = escapeAttr(comment);
        // mat-icon and Angular directives won't be processed when inserting raw HTML,
        // so use the Material Icons ligature (or a simple <i> / <span> with the icon font) and title for tooltip.
        commentHtml = `<span class="material-icons" title="${escaped}" style="height:22px; font-size:22px; vertical-align:middle;">info</span>`;
      }

      // For list views build a list-style row (with optional flag)
      if (isListView) {
        // Render the Angular template into DOM nodes and serialize to HTML so translation pipes
        // and other Angular bindings work.
        if (this.eventListTemplateRef && this.eventListTemplateContainer) {
          const ctx = { timeText: info.timeText || '', title: info.event.title || '', lang: lang, types: types, comment: comment };
          const view: EmbeddedViewRef<any> = this.eventListTemplateContainer.createEmbeddedView(this.eventListTemplateRef, ctx);
          view.detectChanges();
          // serialize root nodes
          const html = view.rootNodes.map((n: any) => n.nodeType === 1 ? (n as HTMLElement).outerHTML : n.textContent || '').join('');
          view.destroy();
          return { html };
        }
      }

      // For non-list views: in month view (dayGridMonth) we intentionally omit extra icons
      // (flag/types/comment). Other non-list views (day/time) may show icons.
      const isMonthView = viewType === 'dayGridMonth';

      // Format time: in month view show hours:minutes (e.g. 9:00 or 17:15).
      // For other views use FullCalendar's info.timeText so it remains consistent with view settings.
      let timeHtml = '';
      if (info.timeText) {
        if (isMonthView) {
          // Try to derive precise minutes from the event start if available
          let startDate: Date | null = null;                    
          let s = String(info.event.startStr);
          startDate = new Date(s);          
          if (startDate) {
            const h = startDate.getHours();
            const m = startDate.getMinutes();
            const mins = ('0' + m).slice(-2);
            // Bold time in month view
            timeHtml = `<span class="fc-event-time" style="font-weight:700">${h}:${mins}</span>`;
          } else {
            // Fallback to the provided timeText
            timeHtml = `<span class="fc-event-time" style="font-weight:700">${escapeAttr(info.timeText)}</span>`;
          }
        } else {
          timeHtml = `<span class="fc-event-time">${info.timeText}</span>`;
        }
      }
    
      // Regular mass event rendering
      const dotHtml = `<span class="fc-list-event-dot" style="background-color:${info.event.backgroundColor || '#3788d8'}; border-color:${info.event.borderColor || '#3788d8'};"></span>`;
      const titleHtml = `<span class="fc-event-title">${info.event.title}</span>`;
      
      if (isMonthView) {
        // Minimal markup for month view: bold time, normal-weight title, no icons by default
        const detailsHtml = `<span class="material-icons" title="További információ" style="margin-left:6px; height:18px; font-size:18px; vertical-align:top;">info</span>`;
        const monthHtml = `${timeHtml} ${dotHtml} <span class="fc-event-title" style="font-weight:400">${escapeAttr(info.event.title)}</span>`;
        const shouldShowDetails =
          (lang && this.languagesOf(lang).some(l => this.shouldShowFlag(l))) ||
          (Array.isArray(types) && types.length > 0) ||
          !!comment;
        return { html: shouldShowDetails ? `${monthHtml} ${detailsHtml}` : monthHtml };
      }

      // #358: az ikonok az IDŐ sorába kerülnek, nem a cím mellé.
      //
      // A heti rácsban egy nap-oszlop ~75px széles: a „Szentmise" cím kitölti, mellé
      // a zászló és a típus-ikonok már nem férnek. Korábban ezért új sorba törtek és
      // kilógtak a színes blokkból (63px tartalom 48px dobozban); ha meg nowrappal
      // levágtuk őket, egyszerűen eltűntek. Az idő („18:00") viszont rövid — ott
      // elférnek mellette, és a cím kap egy saját, teljes sort.
      const combinedHtml =
        `<span class="mcal-event-head">${timeHtml}${flagHtml}${typesHtml}${commentHtml}</span>`
        + `<span class="fc-event-title-wrap">${titleHtml}</span>`;
      return { html: combinedHtml };
    } catch (e) {
      return { html: info.event.title };
    }
  }

// RRule parsing helpers for human-readable recurrence description
// A masslist használja
  private getDaysFromRRule(mass: Mass): string {
    const days = mass.rrule?.byweekday;
    if (ScriptUtil.isNotNull(days)) {
      const translatedDays: string[] = [...days].map(d => this.translateService.instant('DAYS.ON.' + d));
      return translatedDays.join(', ');
    }
    return '';
  }

  private getWeekFromRRule(mass: Mass): string | null {
    const rrule = mass.rrule;
    if (ScriptUtil.isNull(rrule) || rrule.freq !== 'weekly') {
      return null;
    }

    if (rrule.byweekno && rrule.byweekno.length > 0) {
      const isEven = rrule.byweekno.every((n: number) => n % 2 === 0);
      const isOdd = rrule.byweekno.every((n: number) => n % 2 === 1);
      const week: string = this.translateService.instant(isEven ? 'RRULE.ON.EVEN' : isOdd ? 'RRULE.ON.ODD' : '');
      return week || null;
    }

    return this.translateService.instant('RRULE.ON.EVERY_WEEK');
  }

  private getMonthFromRRule(mass: Mass): string | null {
    const rrule = mass.rrule;
    if (ScriptUtil.isNotNull(rrule) && ScriptUtil.isNotNull(rrule.bysetpos)) {
      const renumByPos = MassUtil.renumByPos(rrule.bysetpos);
      if (renumByPos != null) {
        return this.translateService.instant('RRULE.ON.' + renumByPos);
      }
    }
    return null;
  }

  private getYearFromRRule(mass: Mass): string | null {
    const rrule = mass.rrule;    
    if (ScriptUtil.isNotNull(rrule) && rrule.freq === 'yearly') {
      return this.translateService.instant('RRULE.ON.EVERY_YEAR');
    }
    return null;
  }

  private getMonthsFromRRule(mass: Mass): string | null {
    const rrule = mass.rrule;
    if (ScriptUtil.isNotNull(rrule) && ScriptUtil.isNotNull(rrule.bymonth)) {
      const bymonth = rrule.bymonth;
      const months: number[] = Array.isArray(bymonth) ? bymonth as number[] : [bymonth as number];
      const translatedMonths: string[] = months.map(m => this.translateService.instant('MONTHS.' + m));
      return translatedMonths.join(', ');
    }
    return null;
  }

  private getMonthDaysFromRRule(mass: Mass): string | null {
    const rrule = mass.rrule;
    if (ScriptUtil.isNotNull(rrule) && ScriptUtil.isNotNull(rrule.bymonthday)) {
      const monthDays: number[] = rrule.bymonthday;      
      return monthDays.join(', ');
    }
    return null;
  }

  private getEasterFromMass(mass: Mass): string | null {
    if (ScriptUtil.isNotNull(mass.periodId)) {
      const specialPeriodType = this.periodService.getSpecialPeriodType(mass.periodId);
      // SpecialType enum isn't imported here; periodService method returns something comparable to suggestions logic
      if (specialPeriodType === (window as any).SpecialType?.EASTER || specialPeriodType === 'EASTER') {
        const rrule = mass.rrule;
        if (ScriptUtil.isNotNull(rrule) && ScriptUtil.isNotNull(rrule.byweekday) && rrule.byweekday.length === 1) {
          let easterDay = rrule.byweekday[0];
          if (easterDay != null) {
            return this.translateService.instant("EASTER_DAYS." + easterDay);
          }
        }
      }
    }
    return null;
  }

  private getChristmasFromRRule(mass: Mass): string | null {
    const rrule = mass.rrule;
    if (ScriptUtil.isNotNull(rrule) && rrule.bymonth === 12 && ScriptUtil.isNotNull(rrule.bymonthday)) {
      let christmasDay = MassUtil.christmasDayByMonthday(rrule.bymonthday);
      if (christmasDay != null) {
        return this.translateService.instant("CHRISTMAS_DAYS." + christmasDay);
      }
    }
    return null;
  }

  
  private getSimpleEventFromRRule(mass: Mass): string | null {
      const rrule = mass.rrule;
      if (ScriptUtil.isNull(rrule)) return null;      
      // If daily with a single occurrence, return the DTSTART as YYYY.mm.dd
      const countIsOne = rrule.count === 1 || String(rrule.count) === '1';
      if (rrule.freq === 'daily' && countIsOne) {
        if (ScriptUtil.isNotNull(rrule.dtstart)) {
          const dtstartDate = new Date(rrule.dtstart);
          const year = dtstartDate.getFullYear();
          const month = ('0' + (dtstartDate.getMonth() + 1)).slice(-2);
          const day = ('0' + dtstartDate.getDate()).slice(-2);
          return this.translateService.instant(`RRULE.NO_RECURRENCE`) + `: ${year}.${month}.${day}`;
        }

      }

      return null;
  }

  private getReadableRRule(mass: Mass): string {
    if (!mass || ScriptUtil.isNull(mass.rrule)) return '';
    const parts: string[] = [];

    const easter = this.getEasterFromMass(mass);
    if (easter) parts.push(easter);
    const christmas = this.getChristmasFromRRule(mass);
    if (christmas) parts.push(christmas);

    if (!easter && !christmas) {
        const days = this.getDaysFromRRule(mass);
        if (days) parts.push(days);
        const week = this.getWeekFromRRule(mass);
        if (week) parts.push(week);
        const month = this.getMonthFromRRule(mass);
        if (month) parts.push(month);
        const year = this.getYearFromRRule(mass);    
        const months = this.getMonthsFromRRule(mass);
        const monthDays = this.getMonthDaysFromRRule(mass);
        if (year || months  || monthDays ) {
          const combined = [year, months, monthDays].filter(p => !!p).join(' ');
          parts.push(combined);
        } 
        const simpleEvent = this.getSimpleEventFromRRule(mass);
        if(simpleEvent) parts.push(simpleEvent);
    }       
    
    return parts.join(', ');
  }
  // Itt ért véget a masslist használta rész

  private buildMassList(): void {
    // Merge base masses and changes (changes override base), exclude deleted masses.
    const combined = new Map<number, Mass>();
    for (const m of this.masses.values()) {
      combined.set(m.id!, m);
    }
    for (const [id, changed] of this.changes.entries()) {
      combined.set(id, changed);
    }
    for (const del of this.deletedMasses) {
      if (combined.has(del)) combined.delete(del);
    }

    // groups now carry additional optional metadata for header rendering
    // key: groupId (period id or 0 for no period)
    const groups: {[key: number]: {weight: number, periodName: string, masses: any[], startMonthDay?: string | null, endMonthDay?: string | null, startPeriodName?: string | null, endPeriodName?: string | null, color?: string | null, hasOldMasses?: boolean, oldMasses?: any[], expandOldMasses?: boolean}} = {};

    // Calculate 7 days ago
    const now = new Date();
    const sevenDaysAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);

    combined.forEach(m => {
      const period = m.periodId ? this.periodService.getPeriodById(m.periodId) : null;
      // group by period id when available, otherwise groupId = 0
      const groupId = period && period.id ? period.id : 0;
      const weight = period && period.weight ? period.weight : 0;
      const pname = period && period.name ? period.name : '';

      if (!groups[groupId]) {
        // try to fetch a representative generated period to obtain a color
        let color: string | null = null;
        if (period && period.id) {
          const gen = this.periodService.getGeneratedPeriodsByPeriodId(period.id);
          if (Array.isArray(gen) && gen.length > 0) {
            color = gen[0].color || null;
          }
        }

        groups[groupId] = {
          weight: weight,
          periodName: pname,
          masses: [],
          startMonthDay: period ? period.startMonthDay : null,
          endMonthDay: period ? period.endMonthDay : null,
          startPeriodName: period && period.startPeriodId ? this.periodService.getPeriodNameById(period.startPeriodId) : null,
          endPeriodName: period && period.endPeriodId ? this.periodService.getPeriodNameById(period.endPeriodId) : null,
          color: color,
          hasOldMasses: false,
          oldMasses: [],
          expandOldMasses: false
        };
      } else {
        // fill missing group metadata from other masses' periods if available
        const g = groups[groupId];
        if ((!g.color || g.color === null) && period && period.id) {
          const gen = this.periodService.getGeneratedPeriodsByPeriodId(period.id);
          if (Array.isArray(gen) && gen.length > 0) {
            g.color = gen[0].color || g.color;
          }
        }
        if ((!g.startMonthDay || g.startMonthDay === null) && period && period.startMonthDay) {
          g.startMonthDay = period.startMonthDay;
        }
        if ((!g.endMonthDay || g.endMonthDay === null) && period && period.endMonthDay) {
          g.endMonthDay = period.endMonthDay;
        }
        if ((!g.startPeriodName || g.startPeriodName === null) && period && period.startPeriodId) {
          g.startPeriodName = this.periodService.getPeriodNameById(period.startPeriodId);
        }
        if ((!g.endPeriodName || g.endPeriodName === null) && period && period.endPeriodId) {
          g.endPeriodName = this.periodService.getPeriodNameById(period.endPeriodId);
        }
      }

      const flagMap: Record<string,string> = { hu: '🇭🇺', en: '🇬🇧', de: '🇩🇪', sk: '🇸🇰', ro: '🇷🇴' };
      const flag = flagMap[m.lang] || (m.lang ? String(m.lang).toUpperCase() : '');

      // Separate exDates into recent and old (older than 7 days)
      const now = new Date();
      const sevenDaysAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
      
      const exDatesNew: string[] = [];
      const exDatesOld: string[] = [];
      
      if (m.exdate && m.exdate.length > 0) {
        m.exdate.forEach((dateStr: string) => {
          const exDate = new Date(dateStr);
          if (exDate >= sevenDaysAgo) {
            exDatesNew.push(dateStr);
          } else {
            exDatesOld.push(dateStr);
          }
        });
      }
      
      // Sort in descending order (newest first)
      exDatesNew.sort().reverse();
      exDatesOld.sort().reverse();

      // #428: szétválasztjuk az automatikus és a kézi kizárt időszakokat
      const autoPeriodIds = m.experiod ?? [];
      const manualPeriodIds = m.manualExperiod ?? [];
      const autoExperiodNames = autoPeriodIds
        .map((pid: number) => this.periodService.getPeriodNameById(pid))
        .filter((n: any) => n);
      const manualExperiodNames = manualPeriodIds
        .map((pid: number) => this.periodService.getPeriodNameById(pid))
        .filter((n: any) => n);

      const massData = {
        id: m.id,
        title: m.title,
        rite: m.rite,
        startDate: m.startDate,
        periodId: m.periodId,
        rrule: m.rrule,
        readableRRule: this.getReadableRRule(m),
        lang: m.lang,
        flag: flag,
        types: m.types ? m.types : [],
        comment: m.comment,
        // #592: külső naptárból importált liturgia — a listában jelöljük, és nem szerkeszthető.
        imported: !!m.imported,
        // #428: a mise-listában az auto + kézi kizárt időszakok UNIÓJA jelenjen meg
        experiod: MassUtil.getEffectiveExperiod(m),
        experiodNames: MassUtil.getEffectiveExperiod(m).map((pid: number) => this.periodService.getPeriodNameById(pid)).filter((n: any) => n),
        autoExperiodNames: autoExperiodNames,
        manualExperiodNames: manualExperiodNames,
        exDates: m.exdate ? m.exdate : [],
        exDatesNew: exDatesNew,
        exDatesOld: exDatesOld,
        expandExDatesOld: false
      };

      // For no-period group (groupId = 0), check if this is an old mass
      if (groupId === 0 && !m.periodId && m.startDate) {
        const massStartDate = new Date(m.startDate);
        if (massStartDate < sevenDaysAgo) {
          // This is an old mass without a period
          groups[groupId].hasOldMasses = true;
          if (!groups[groupId].oldMasses) {
            groups[groupId].oldMasses = [];
          }
          groups[groupId].oldMasses!.push(massData);
        } else {
          // Recent mass without a period
          groups[groupId].masses.push(massData);
        }
      } else {
        // All other masses (with periods or the no-period group for recent masses)
        groups[groupId].masses.push(massData);
      }
    });

    // Convert to array and sort by weight desc, and sort masses by startDate
    this.massListGrouped = Object.keys(groups).map(k => groups[parseInt(k)]).sort((a, b) => b.weight - a.weight);
    this.massListGrouped.forEach(g => {
      g.masses.sort((x, y) => (x.startDate || '').localeCompare(y.startDate || ''));
      if (g.oldMasses) {
        g.oldMasses.sort((x, y) => (x.startDate || '').localeCompare(y.startDate || ''));
      }
    });
  }

  // Build the HTML shown when no events exist in the current view
  public toggleOldMasses(group: any): void {
    if (group) {
      group.expandOldMasses = !group.expandOldMasses;
    }
  }

  private renderNoEventsContent(): { html: string } | string {
    // Priority: if still loading show "betöltés folyamatban".
    if (this.loadingEvents && !this.loadedEvents) {
      return { html: `<div class="fc-no-events">Betöltés folyamatban...</div>` };
    }

    // If we've loaded but there are no events in the current range
    // Distinguish between "soha nincs esemény" and "nincs megjeleníthető esemény"
    const hasAnySourceMasses = (this.masses && this.masses.size > 0) || (this.changes && this.changes.size > 0);
    
    
    if (!this.everHadEvents && !hasAnySourceMasses) {
      return { html: `<div class="fc-no-events">Ehhez a misézőhelyhez egyáltalán nem tartozik esemény.</div>` };
    }

    if (!this.loadedEvents) {
      // defensive fallback
      return { html: `<div class="fc-no-events">Ebben az időszakban nincsenek események.</div>` };
    }

    return { html: `<div class="fc-no-events">Nincs megjeleníthető esemény ebben az időszakban.</div>` };
  }

  /**
   * Szűrési logika: meghatározza, hogy egy esemény megjelenjen-e az aktív kategóriák alapján
   */
  private isEventVisible(calEvent: CalendarEvent): boolean {
    // Ha nincs kategória info, akkor megjelenítjük (legacy events)
    if (!calEvent.extendedProps?.massTitleCategory) {
      return true;
    }
    
    // Csak akkor jelenítsük meg, ha a kategória aktív
    return this.activeFilterCategories.has(calEvent.extendedProps.massTitleCategory);
  }

  /**
   * Szűri az eseményeket a kategória szűrés alapján
   */
  private filterCalendarEventsByCategory(events: CalendarEvent[]): CalendarEvent[] {
    return events.filter(event => this.isEventVisible(event));
  }

  /**
   * Szűrési kategória módosítás kezelése
   */
  public onCategoriesChanged(newActiveCategories: Set<MassTitleCategory>): void {
    this.activeFilterCategories = newActiveCategories;
    // Naptár frissítése az új szűrés alapján
    this.refreshCalendarAndMassList();
  }

  /**
   * Badge szűrő metódusok
   */
  toggleCategory(category: MassTitleCategory): void {
    if (this.activeFilterCategories.has(category)) {
      this.activeFilterCategories.delete(category);
    } else {
      this.activeFilterCategories.add(category);
    }
    // Naptár frissítése az új szűrés alapján
    this.refreshCalendarAndMassList();
  }

  isChecked(category: MassTitleCategory): boolean {
    return this.activeFilterCategories.has(category);
  }

  getCategoryLabel(category: MassTitleCategory): string {
    return `MASS_TITLE_CATEGORY.${category}`;
  }

  /**
   * Determines if a language flag should be displayed based on country and language.
   * Returns false only when: country == 'HU' AND language == 'hu'
   * In all other cases, returns true.
   *
   * @param language The language code (e.g., 'hu', 'en', 'de')
   * @param country Optional country code. Uses currentChurch.country if not provided
   * @returns true if flag should be displayed, false otherwise
   */
  /**
   * #334: a mise `lang` mezője vesszővel elválasztva több nyelvet is tartalmazhat
   * ("sk,la"). A sablonok ezen keresztül kapják a listát, hogy ne az egész karakterlánccal
   * próbáljanak zászlót keresni (/cal_images/flags/sk,la.svg — nem létezik).
   */
  languagesOf(lang: string | null | undefined): string[] {
    return MassUtil.languageCodes(lang);
  }

  shouldShowFlag(language: string, country?: string): boolean {
    const churchCountry = country || this.currentChurch?.country;
    
    // Hide flag only if: country is 'HU' AND language is 'hu'
    if (churchCountry === 'HU' && language === 'hu') {
      return false;
    }
    
    // Show flag in all other cases
    return true;
  }
}
