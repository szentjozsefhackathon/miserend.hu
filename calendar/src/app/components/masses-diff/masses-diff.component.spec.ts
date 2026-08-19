import {ComponentFixture, TestBed} from '@angular/core/testing';
import {provideTranslateService} from '@ngx-translate/core';

import {MassesDiffComponent} from './masses-diff.component';
import {ReadableMass} from '../../model/readable-mass';

/**
 * #436: CLI-generált csonk volt (`should create`), `xdescribe`-bal kikapcsolva.
 *
 * A komponens a javaslat-jóváhagyás felületén mutatja, mi változna egy misén. Egyetlen
 * saját logikája van, és az fontos: a KIZÁRT IDŐSZAKOK (`experiod`) eltérését külön
 * jelzi, mert az a naptárban nem látszik közvetlenül — a kezelő enélkül úgy hagyhatna
 * jóvá egy javaslatot, hogy nem tűnik fel neki, hogy a mise láthatósága változik.
 *
 * Az összehasonlítás MÉLY: az `experiod` tömb, tehát a referencia-egyezés nem elég.
 */
describe('MassesDiffComponent', () => {

  let fixture: ComponentFixture<MassesDiffComponent>;
  let component: MassesDiffComponent;

  const mise = (experiod?: number[]): ReadableMass =>
    ({title: 'Szentmise', experiod} as unknown as ReadableMass);

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [MassesDiffComponent],
      providers: [provideTranslateService()],
    }).compileComponents();

    fixture = TestBed.createComponent(MassesDiffComponent);
    component = fixture.componentInstance;
  });

  it('azonos kizárásoknál nem jelez változást', () => {
    component.origMass = mise([1, 2]);
    component.newMass = mise([1, 2]);

    fixture.detectChanges();

    expect(component.experiodChanged).toBeFalse();
  });

  /** Külön tömb, azonos tartalom — a referencia-összehasonlítás itt tévedne. */
  it('a tartalmat hasonlítja össze, nem a referenciát', () => {
    const kizarasok = [3, 4];
    component.origMass = mise([...kizarasok]);
    component.newMass = mise([...kizarasok]);

    fixture.detectChanges();

    expect(component.experiodChanged).toBeFalse();
  });

  it('eltérő kizárásoknál változást jelez', () => {
    component.origMass = mise([1]);
    component.newMass = mise([1, 2]);

    fixture.detectChanges();

    expect(component.experiodChanged).toBeTrue();
  });

  it('a kizárás megjelenése változás', () => {
    component.origMass = mise(undefined);
    component.newMass = mise([5]);

    fixture.detectChanges();

    expect(component.experiodChanged).toBeTrue();
  });

  it('a kizárás eltűnése is változás', () => {
    component.origMass = mise([5]);
    component.newMass = mise(undefined);

    fixture.detectChanges();

    expect(component.experiodChanged).toBeTrue();
  });

  /** A bemenet változásakor újra kell számolni — enélkül a régi állapot ragadna be. */
  it('bemenet-változásnál újraszámol', () => {
    component.origMass = mise([1]);
    component.newMass = mise([1]);
    fixture.detectChanges();
    expect(component.experiodChanged).toBeFalse();

    component.newMass = mise([1, 9]);
    component.ngOnChanges({} as any);

    expect(component.experiodChanged).toBeTrue();
  });

  // ---- #431: helyszín-változás ------------------------------------------------

  /**
   * borazslo kérése: a „Javasolt változások" összefoglalóban a módosított miséknél
   * jelenjen meg a helyszín is. Az üres érték olvasható alakja „a templomban" —
   * üres cella mellett nem derülne ki, hogy a mise VISSZAKERÜLT a templomba.
   */
  function helyszinSor(): string {
    return (fixture.nativeElement as HTMLElement).textContent ?? '';
  }

  it('a helyszín felvételét megmutatja', () => {
    component.origMass = mise();
    component.newMass = {...mise(), ownLocation: 'Röszkei puszta'};

    fixture.detectChanges();

    expect(helyszinSor()).toContain('Helyszín:');
    expect(helyszinSor()).toContain('Röszkei puszta');
  });

  it('a templomba visszakerülést is megmutatja', () => {
    component.origMass = {...mise(), ownLocation: 'Röszkei puszta'};
    component.newMass = mise();

    fixture.detectChanges();

    expect(helyszinSor()).toContain('a templomban');
  });

  it('változatlan helyszínnél nem ír ki semmit', () => {
    component.origMass = {...mise(), ownLocation: 'Röszkei puszta'};
    component.newMass = {...mise(), ownLocation: 'Röszkei puszta'};

    fixture.detectChanges();

    expect(helyszinSor()).not.toContain('Helyszín:');
  });
});
