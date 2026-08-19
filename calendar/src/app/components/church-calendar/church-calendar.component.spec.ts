import {ComponentFixture, TestBed} from '@angular/core/testing';
import {provideHttpClient} from '@angular/common/http';
import {provideHttpClientTesting} from '@angular/common/http/testing';
import {provideTranslateService} from '@ngx-translate/core';
import {BehaviorSubject, of} from 'rxjs';

import {ChurchCalendarComponent} from './church-calendar.component';
import {PeriodService} from '../../services/period.service';
import {SpinnerService} from '../../services/spinner.service';
import {MatSnackBarService} from '../../services/mat-snack-bar.service';
import {UserService} from '../../services/user.service';
import {EditConfirmationService} from '../../services/edit-confirmation.service';
import {Church} from '../../model/church';
import {Mass} from '../../model/mass';
import {Rite} from '../../enum/rites';

/**
 * #436: CLI-generált csonk volt (`should create`), `xdescribe`-bal kikapcsolva.
 *
 * Ez a naptár maga — a rendszer legnagyobb és legkritikusabb komponense. Teljes körű
 * lefedést egyetlen spec nem adhat, de két dolgot itt is érdemes rögzíteni, mert
 * mindkettő ADATVESZTÉSSEL jár, ha elromlik:
 *
 *  1. A MENTETLEN VÁLTOZÁS jelzése. Erre épül a „biztosan elnavigálsz?" figyelmeztetés
 *     (ChurchComponent.handleBeforeUnload). Ha tévesen hamis, a gondnok elnavigál, és a
 *     félórás munkája nyomtalanul elvész — hibaüzenet nélkül.
 *  2. A kategória-szűrő. Alapból MINDEN kategória aktív; ha üresen indulna, a naptár
 *     üresnek látszana, és a felhasználó jóhiszeműen újra felvinné a miséket.
 */
describe('ChurchCalendarComponent', () => {

  let fixture: ComponentFixture<ChurchCalendarComponent>;
  let component: ChurchCalendarComponent;

  const templom: Church = {
    id: 1, name: 'Teszt templom', rite: Rite.ROMAN_CATHOLIC, timeZone: 'Europe/Budapest', masses: [],
  };

  const mise = (id: number): Mass => ({id, churchId: 1, title: 'Szentmise'} as Mass);

  beforeEach(async () => {
    const periodService = jasmine.createSpyObj(
      'PeriodService',
      ['getPeriodNameById', 'getGeneratedPeriodsByPeriodId', 'getPeriodById', 'isEasterPeriod', 'isChristmasPeriod'],
      // A komponens `getValue()`-t hív a generált időszakokon, tehát BehaviorSubject kell,
      // nem sima Observable.
      {periods$: new BehaviorSubject<any[]>([]), generatedPeriods$: new BehaviorSubject<any[]>([])},
    );
    periodService.getGeneratedPeriodsByPeriodId.and.returnValue([]);

    await TestBed.configureTestingModule({
      imports: [ChurchCalendarComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideTranslateService(),
        SpinnerService,
        {provide: PeriodService, useValue: periodService},
        {provide: MatSnackBarService, useValue: jasmine.createSpyObj('MatSnackBarService', ['error', 'success'])},
        {provide: UserService, useValue: (() => {
          const spy = jasmine.createSpyObj('UserService', ['loadUser']);
          spy.loadUser.and.returnValue(of({uid: 0, name: '*vendeg*'}));
          return spy;
        })()},
        {provide: EditConfirmationService, useValue: jasmine.createSpyObj('EditConfirmationService', ['isConfirmed', 'confirm', 'getMessage'])},
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(ChurchCalendarComponent);
    component = fixture.componentInstance;
    component.currentChurch = templom;
    component.masses = new Map();

    // Minden teszt inicializált komponenssel dolgozik: enélkül a lebontás hasal el,
    // mert az `ngOnDestroy` olyan feliratkozásokat bontana, amik létre sem jöttek.
    fixture.detectChanges();
  });

  describe('mentetlen változás jelzése', () => {

    it('érintetlen naptárban nincs mentetlen változás', () => {
      expect(component.hasUnsavedChanges).toBeFalse();
    });

    it('módosított mise mentetlen változás', () => {
      component.changes.set(1, mise(1));

      expect(component.hasUnsavedChanges).toBeTrue();
    });

    /**
     * A törlés is mentetlen változás — enélkül a gondnok kitörölne egy misét, elnavigálna
     * figyelmeztetés nélkül, és a mise visszatérne.
     */
    it('törölt mise is mentetlen változás', () => {
      component.deletedMasses = [5];

      expect(component.hasUnsavedChanges).toBeTrue();
    });

    it('törlés és módosítás együtt is jelez', () => {
      component.changes.set(1, mise(1));
      component.deletedMasses = [5];

      expect(component.hasUnsavedChanges).toBeTrue();
    });
  });

  describe('kategória-szűrő', () => {

    /** Üres szűrővel a naptár üresnek LÁTSZANA — pedig tele van misével. */
    it('induláskor minden kategória aktív', () => {
      expect(component.activeFilterCategories.size).toBeGreaterThan(0);
      expect(component.activeFilterCategories.size).toBe(component.categories.length);
    });

    it('minden ismert kategória szerepel a szűrőben', () => {
      for (const kategoria of component.categories) {
        expect(component.activeFilterCategories.has(kategoria))
          .withContext(String(kategoria))
          .toBeTrue();
      }
    });
  });
});
