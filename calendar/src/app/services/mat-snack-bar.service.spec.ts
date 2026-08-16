import {TestBed} from '@angular/core/testing';
import {MatSnackBar} from '@angular/material/snack-bar';

import {MatSnackBarService} from './mat-snack-bar.service';

/**
 * Minden felhasználói visszajelzés ezen a szolgáltatáson megy át — a naptár összes
 * hibaüzenete, mentés-visszaigazolása és figyelmeztetése.
 *
 * A lényeg a CSS-osztály: a színt (zöld/piros/sárga) a `panelClass` adja, és ha az
 * elcsúszik, a hibaüzenet zöld dobozban jelenik meg. Ezt a felületen csak szemre lehet
 * észrevenni, kódból nem — ezért érdemes rögzíteni. A másik a hossz: a hibaüzenet
 * hívója felülírhatja, de az alapértelmezettnek stabilnak kell lennie.
 */
describe('MatSnackBarService', () => {

  let service: MatSnackBarService;
  let snackBar: jasmine.SpyObj<MatSnackBar>;

  beforeEach(() => {
    snackBar = jasmine.createSpyObj('MatSnackBar', ['open']);

    TestBed.configureTestingModule({
      providers: [
        MatSnackBarService,
        {provide: MatSnackBar, useValue: snackBar},
      ],
    });

    service = TestBed.inject(MatSnackBarService);
  });

  /** @return a `matSnackBar.open()` utolsó hívásának argumentumai */
  function utolsoHivas(): [string, string, {panelClass: string; duration: number}] {
    return snackBar.open.calls.mostRecent().args as any;
  }

  it('a siker zöld dobozba megy', () => {
    service.success('Elmentve');

    const [uzenet, gomb, opciok] = utolsoHivas();
    expect(uzenet).toBe('Elmentve');
    expect(gomb).toBe('x');
    expect(opciok.panelClass).toBe('success-snackbar');
  });

  it('a hiba piros dobozba megy', () => {
    service.error('Nem sikerült');
    expect(utolsoHivas()[2].panelClass).toBe('error-snackbar');
  });

  it('a figyelmeztetés sárga dobozba megy', () => {
    service.warning('Vigyázz');
    expect(utolsoHivas()[2].panelClass).toBe('warning-snackbar');
  });

  it('alapból 4 másodpercig látszik', () => {
    service.success('Elmentve');
    expect(utolsoHivas()[2].duration).toBe(4000);
  });

  it('a hívó felülírhatja a megjelenítés hosszát', () => {
    service.error('Hosszabb hiba', 6000);
    expect(utolsoHivas()[2].duration).toBe(6000);
  });

  /**
   * A figyelmeztetés SZÁNDÉKOSAN nem vesz át hosszt — ha valaki felvenné a paramétert,
   * itt derüljön ki, hogy a hívási felület megváltozott.
   */
  it('a figyelmeztetés az alapértelmezett hosszal megy', () => {
    service.warning('Vigyázz');
    expect(utolsoHivas()[2].duration).toBe(4000);
  });

  it('a végtelen hossz nulla, és ezt a kívülről hívók is látják', () => {
    expect(MatSnackBarService.INFINITE_DURATION).toBe(0);

    service.show('Marad', 'success-snackbar', MatSnackBarService.INFINITE_DURATION);
    expect(utolsoHivas()[2].duration).toBe(0);
  });
});
