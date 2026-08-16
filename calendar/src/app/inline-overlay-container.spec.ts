import {TestBed} from '@angular/core/testing';

import {InlineOverlayContainer} from './inline-overlay-container';

/**
 * A naptár beágyazható a miserend.hu oldalaiba, és ilyenkor shadow DOM-ban fut. A
 * Material lebegő elemei (párbeszédablak, legördülő, tooltip) viszont alapból a
 * `document.body` végére kerülnek — vagyis a shadow rooton KÍVÜLRE, ahol a naptár
 * stílusai nem érik el őket. Az eredmény: megjelenő, de stílus nélküli párbeszédablak.
 *
 * Ez az osztály ezt oldja meg: ha van shadow root, oda teszi a tárolót. Ha nincs
 * (önálló oldalként fut), a Material eredeti viselkedése marad. A visszaesés legalább
 * olyan fontos, mint maga a javítás — enélkül az önálló naptárban tűnne el minden
 * lebegő elem.
 */
describe('InlineOverlayContainer', () => {

  let container: InlineOverlayContainer;
  let appRoot: HTMLElement | null = null;

  beforeEach(() => {
    // A CDK OverlayContainer az ősében `inject(Platform)`-ot hív, tehát ez az osztály
    // csak injektálási környezetben példányosítható — sima `new`-val NG0203-mal elszáll.
    TestBed.configureTestingModule({providers: [InlineOverlayContainer]});
    container = TestBed.inject(InlineOverlayContainer);
  });

  afterEach(() => {
    appRoot?.remove();
    appRoot = null;
    document.querySelectorAll('.cdk-overlay-container').forEach(e => e.remove());
  });

  /** @param shadowal legyen-e shadow rootja a felvett app-root elemnek */
  function appRootFelvetel(shadowal: boolean): ShadowRoot | null {
    appRoot = document.createElement('app-root');
    document.body.appendChild(appRoot);
    return shadowal ? appRoot.attachShadow({mode: 'open'}) : null;
  }

  it('shadow rootba teszi a tárolót, ha van', () => {
    const shadow = appRootFelvetel(true)!;

    container._createContainer();

    const tarolo = shadow.querySelector('.cdk-overlay-container');
    expect(tarolo)
      .withContext('shadow DOM-ban a tárolónak is odabent a helye, különben stílus nélkül marad')
      .not.toBeNull();
  });

  it('a shadow rootos tároló megkapja a Material osztálynevét', () => {
    const shadow = appRootFelvetel(true)!;

    container._createContainer();

    expect(shadow.querySelector('div')!.classList.contains('cdk-overlay-container')).toBeTrue();
  });

  it('nem a body-ba teszi, ha van shadow root', () => {
    appRootFelvetel(true);

    container._createContainer();

    expect(document.body.querySelector(':scope > .cdk-overlay-container'))
      .withContext('a body-ba kerülő tárolót nem érnék el a naptár stílusai')
      .toBeNull();
  });

  /** Önálló oldalként futva maradjon a Material eredeti viselkedése. */
  it('shadow root nélkül a Material alapértelmezésére esik vissza', () => {
    appRootFelvetel(false);

    container._createContainer();

    expect(document.querySelector('.cdk-overlay-container'))
      .withContext('enélkül az önálló naptárban tűnne el minden lebegő elem')
      .not.toBeNull();
  });

  it('app-root nélkül is felépül a tároló', () => {
    container._createContainer();

    expect(document.querySelector('.cdk-overlay-container')).not.toBeNull();
  });
});
