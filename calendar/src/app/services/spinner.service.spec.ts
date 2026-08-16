import {TestBed} from '@angular/core/testing';
import {fakeAsync, tick} from '@angular/core/testing';

import {SpinnerService} from './spinner.service';

/**
 * A töltésjelző kapcsolója. Egy sor a lényeg, mégis van benne egy csapda: a
 * `show()`/`hide()` NEM azonnal állítja át a jelzőt, hanem `setTimeout`-ba teszi.
 *
 * Ez szándékos — a hívók a változásdetektálás közben kapcsolgatják, és a közvetlen
 * értékadás `ExpressionChangedAfterItHasBeenCheckedError`-t dobna. A késleltetésnek
 * viszont van egy következménye, amit rögzíteni kell: közvetlenül a `show()` UTÁN a
 * jelző MÉG hamis. Aki szinkron olvasná, rosszat lát.
 */
describe('SpinnerService', () => {

  let service: SpinnerService;

  beforeEach(() => {
    TestBed.configureTestingModule({providers: [SpinnerService]});
    service = TestBed.inject(SpinnerService);
  });

  it('alapból nem látszik', () => {
    expect(service.spinnerVisible).toBeFalse();
  });

  it('a show() nem azonnal kapcsol, hanem a következő körben', fakeAsync(() => {
    service.show();
    expect(service.spinnerVisible)
      .withContext('a show() szinkron módon még nem kapcsolhat át')
      .toBeFalse();

    tick();
    expect(service.spinnerVisible).toBeTrue();
  }));

  it('a hide() ugyanígy késleltetve kapcsol vissza', fakeAsync(() => {
    service.show();
    tick();

    service.hide();
    expect(service.spinnerVisible)
      .withContext('a hide() szinkron módon még nem kapcsolhat vissza')
      .toBeTrue();

    tick();
    expect(service.spinnerVisible).toBeFalse();
  }));

  /**
   * Egymásra torlódó kérésnél gyakori: több show() után egy hide(). A jelenlegi
   * viselkedés NEM számláló alapú, tehát az utolsó hívás dönt.
   */
  it('több show() után egyetlen hide() is eltünteti', fakeAsync(() => {
    service.show();
    service.show();
    service.hide();
    tick();

    expect(service.spinnerVisible).toBeFalse();
  }));
});
