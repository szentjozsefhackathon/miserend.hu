import {Component, HostListener, OnInit, ViewChild} from '@angular/core';
import {CommonModule} from '@angular/common';
import {ChurchCalendarComponent} from '../church-calendar/church-calendar.component';
import {Church, ChurchFamilyMember} from '../../model/church';
import {Mass} from '../../model/mass';
import {SensorEvent} from '../../model/sensor-event';
import {ActivatedRoute} from '@angular/router';
import {EventService} from '../../event.service';
import {SpinnerService} from '../../services/spinner.service';

@Component({
  selector: 'app-church',
  imports: [
    CommonModule,
    ChurchCalendarComponent
  ],
  templateUrl: './church.component.html',
  styleUrl: './church.component.css'
})
export class ChurchComponent implements OnInit {

  public dataLoaded: boolean = false;
  public currentChurch?: Church;
  public masses: Map<number, Mass> = new Map();
  public sensorEvents: SensorEvent[] = [];
  public loadError: string | null = null;

  /**
   * #506: a plébánia és fíliái, ha az útvonalon `?csalad=1` szerepel.
   *
   * Külön kérni kell, nem alapértelmezés: a szerkesztő így ugyanúgy viselkedik, mint
   * eddig, amíg valaki tudatosan nem családban akar dolgozni.
   */
  public family: ChurchFamilyMember[] = [];

  @ViewChild('churchCalendar') churchCalendar!: ChurchCalendarComponent;

  constructor(
    private readonly activatedRoute: ActivatedRoute,
    private readonly eventService: EventService,
    private readonly spinnerService: SpinnerService,
    ) {
  }

  ngOnInit() {
    this.spinnerService.show();
    this.initEvents();
  }

  /**
   * #830: család módban vagyunk?
   *
   * Kétféleképpen kerülhetünk ide: a `?csalad=1` paraméterrel (ez volt az első
   * megoldás, és a régi linkek miatt marad), vagy a `/plebania/:id` útvonalon —
   * borazslo kérésére, mert a paraméter nem osztható meg jól és nem is beszédes.
   *
   * Az útvonalat SZÁNDÉKOSAN a teljes címből nézzük, nem az Angular útvonal-mintából:
   * a naptár egy PHP-lapba ágyazva fut, és így akkor is helyesen dönt, ha a
   * beágyazás miatt a router csak részleges címet lát.
   *
   * @param csaladParam a `?csalad` paraméter értéke (vagy `null`)
   * @param utvonal a böngésző címsorának útvonal-része
   */
  public static isFamilyRoute(csaladParam: string | null, utvonal: string): boolean {
    if (csaladParam === '1') {
      return true;
    }
    return /(^|\/)plebania\/[0-9]+(\/|$)/.test(utvonal ?? '');
  }

  private initEvents() {
    const churchId: number = +this.activatedRoute.snapshot.params['id'];
    const familyMode: boolean = ChurchComponent.isFamilyRoute(
      this.activatedRoute.snapshot.queryParamMap.get('csalad'),
      window.location.pathname
    );

    this.eventService.getChurch(churchId, familyMode).subscribe({
      next: (church: Church) => {
        console.log('[ChurchComponent] Church data loaded:', church);
        console.log('[ChurchComponent] Sensor events count:', church.eventsFromSensor?.length || 0);
        this.currentChurch = church;
        this.family = church.family ?? [];
        this.masses = this.collectMasses(church);
        this.sensorEvents = church.eventsFromSensor || [];
        console.log('[ChurchComponent] sensorEvents set to:', this.sensorEvents);
        this.loadError = null;
        this.dataLoaded = true;
        this.spinnerService.hide();
      },
      error: (err: any) => {
        console.error('Failed to load church data', err);
        // Show a concise, user-friendly error message. Keep dataLoaded true so template can render the message.
        this.loadError = 'Nem sikerült betölteni a templom adatait, mert az adatszolgáltató oldal nem elérhető. Elnézést kérünk.';
        this.currentChurch = undefined;
        this.family = [];
        this.masses = new Map();
        this.sensorEvents = [];
        this.dataLoaded = true;
        this.spinnerService.hide();
      }
    });
  }

  /**
   * A saját és — család módban — a rokon templomok miséi egy naptárban.
   *
   * Minden mise hordozza a saját `churchId`-jét, és a mentés azzal megy vissza, tehát a
   * rokon templom miséje a SAJÁT templomához íródik. A szerver misénként ellenőrzi a
   * jogosultságot, így a felület tévedése sem tud rossz helyre írni.
   */
  private collectMasses(church: Church): Map<number, Mass> {
    const osszes: Mass[] = [...church.masses];

    for (const tag of church.family ?? []) {
      if (tag.isCurrent) {
        continue; // a saját miséi már benne vannak
      }
      osszes.push(...tag.masses);
    }

    return new Map(osszes.map(e => [e.id!, e]));
  }

  @HostListener('window:beforeunload', ['$event'])
  handleBeforeUnload(event: BeforeUnloadEvent) {
    if (this.churchCalendar.hasUnsavedChanges) {
      event.preventDefault();
    }
  }
}
