import {TestBed} from '@angular/core/testing';

import {EditConfirmationService} from './edit-confirmation.service';

/**
 * Ez tartja számon, hogy a látogató RÁBÓLINTOTT-e a szerkesztésre. A naptár ez alapján
 * dönti el, kell-e még egyszer megkérdezni, mielőtt javaslatot enged beküldeni.
 *
 * Két dolgot érdemes rögzíteni. Az egyik, hogy a jóváhagyás EGYIRÁNYÚ: nincs visszavonás,
 * tehát ha egyszer igent mondtak, a munkamenet végéig az marad — aki visszavonhatónak
 * hinné, hibás felületet építene rá. A másik, hogy a szolgáltatás `providedIn: 'root'`,
 * tehát a jóváhagyás az egész alkalmazásra szól, nem komponensenként külön.
 */
describe('EditConfirmationService', () => {

  let service: EditConfirmationService;

  beforeEach(() => {
    TestBed.configureTestingModule({providers: [EditConfirmationService]});
    service = TestBed.inject(EditConfirmationService);
  });

  it('alapból nincs jóváhagyás', () => {
    expect(service.isConfirmed()).toBeFalse();
  });

  it('a confirm() után jóváhagyottnak számít', () => {
    service.confirm();
    expect(service.isConfirmed()).toBeTrue();
  });

  it('a jóváhagyás nem vonható vissza, és ismételhető is', () => {
    service.confirm();
    service.confirm();

    expect(service.isConfirmed())
      .withContext('nincs visszavonó művelet — ha lesz, ezt a tesztet kell átírni')
      .toBeTrue();
  });

  it('van alapértelmezett kérdés, és az a szerkesztésről szól', () => {
    const uzenet = service.getMessage();

    expect(uzenet.length).toBeGreaterThan(0);
    expect(uzenet).toContain('javaslat');
  });

  it('a kérdés felülírható', () => {
    service.setMessage('Biztosan szerkeszted?');
    expect(service.getMessage()).toBe('Biztosan szerkeszted?');
  });

  /** A szöveg cseréje nem érintheti a jóváhagyás állapotát. */
  it('a kérdés cseréje nem hagy jóvá semmit', () => {
    service.setMessage('Más szöveg');
    expect(service.isConfirmed()).toBeFalse();
  });
});
