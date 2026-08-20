import {
  AfterViewInit, Component, ElementRef, EventEmitter, Input, OnChanges,
  OnDestroy, Output, SimpleChanges, ViewChild
} from '@angular/core';
import {CommonModule} from '@angular/common';
import {TranslatePipe} from '@ngx-translate/core';
import {LeafletLoaderService} from '../../services/leaflet-loader.service';
import {CSEMPE_BEALLITAS, CSEMPE_URL} from '../../map-tiles';

export interface PickedLocation {
  lat: number;
  lon: number;
}

/**
 * #816: az alkalom helyszínének kiválasztása térképen.
 *
 * borazslo kérése a #813-ban: „külön issue lehet hogy egyszer térképen lehessen
 * kiválasztani, ahol az alapértelmezett a templom koordinátája". A szabadtéri alkalom
 * jellemzően a templom közelében van, tehát onnan indulva pár mozdulat az egész — a
 * gondnoknak ne kelljen kimennie az OpenStreetMapre két számot kimásolni.
 *
 * A kézi beírás MEGMARAD mellette: aki koordinátát kapott a szervezőtől, ne kelljen
 * térképen keresgélnie. A két felület ugyanazt az értéket írja, oda-vissza követve.
 *
 * A térkép sosem „dönt": magától nem állít be helyszínt. Ha nincs koordináta, a
 * jelölő meg sem jelenik — csak a templom körüli kivágat látszik. Enélkül a puszta
 * megnyitás elmozdítaná a misét, holott a felhasználó semmit nem választott.
 */
@Component({
  selector: 'app-location-picker',
  standalone: true,
  imports: [CommonModule, TranslatePipe],
  templateUrl: './location-picker.component.html',
  styleUrls: ['./location-picker.component.css'],
})
export class LocationPickerComponent implements AfterViewInit, OnChanges, OnDestroy {

  /** A kiválasztott helyszín. `null`, amíg nincs — ilyenkor nincs jelölő. */
  @Input() lat: number | null = null;
  @Input() lon: number | null = null;

  /** A templom koordinátája: a térkép kiindulópontja, ha még nincs választás. */
  @Input() churchLat: number | null = null;
  @Input() churchLon: number | null = null;

  @Output() picked = new EventEmitter<PickedLocation>();

  @ViewChild('mapContainer') mapContainer?: ElementRef<HTMLDivElement>;

  /** Hibaüzenet, ha a térkép nem tölthető be — a kézi mezők ilyenkor is működnek. */
  public hiba: string | null = null;

  private L: any;
  private map: any;
  private marker: any;
  private figyelo?: ResizeObserver;

  constructor(private leaflet: LeafletLoaderService) {}

  ngAfterViewInit(): void {
    this.leaflet.load()
      .then(L => {
        this.L = L;
        this.meretreVar();
      })
      .catch(() => {
        // Szándékosan nem dobjuk tovább: a térkép kiegészítés, nem feltétel. A
        // koordináta kézzel is beírható, és a szerkesztő többi része ép marad.
        this.hiba = 'MAP_UNAVAILABLE';
      });
  }

  /**
   * #816: a térkép csak akkor épül fel, amikor a doboznak VAN mérete.
   *
   * A helyszín-blokk egy alapból CSUKOTT „Tulajdonságok" panelben ül. Ha a térképet a
   * nézet elkészültekor építenénk meg, a doboz 0×0 lenne: a Leaflet ekkora területre
   * egyetlen csempét kér le, és a kinyitás után is az marad — borazslo pontosan ezt
   * kapta, „egy-egy a sarkában szinte találkozó négyzetnyi térképpel (többi fehér)".
   *
   * Az utólagos `invalidateSize()` erre nem elég megbízható válasz: a doboz mérete a
   * dialógus animációja, a panel kinyitása és a görgetés közben többször is változik.
   * Ezért inkább megvárjuk az első valódi méretet, és onnantól minden változásnál
   * újramérünk.
   */
  private meretreVar(): void {
    const elem = this.mapContainer?.nativeElement;
    if (!elem) {
      return;
    }

    if (typeof ResizeObserver === 'undefined') {
      // Régi böngésző: marad a régi, időzített megoldás.
      this.terkepetEpit();
      setTimeout(() => this.map?.invalidateSize(), 300);
      return;
    }

    this.figyelo = new ResizeObserver(() => {
      const {offsetWidth: szelesseg, offsetHeight: magassag} = elem;
      if (szelesseg === 0 || magassag === 0) {
        return;
      }
      if (!this.map) {
        this.terkepetEpit();
      } else {
        this.map.invalidateSize();
      }
    });
    this.figyelo.observe(elem);
  }

  ngOnChanges(changes: SimpleChanges): void {
    if ((changes['lat'] || changes['lon']) && this.map) {
      this.jeloloFrissit();
    }
  }

  ngOnDestroy(): void {
    // A Leaflet globális eseményfigyelőket akaszt az ablakra; a dialógus bezárásakor
    // ezeket el kell engedni, különben minden megnyitás hagy egy halott térképet.
    this.figyelo?.disconnect();
    this.figyelo = undefined;
    this.map?.remove();
    this.map = undefined;
    this.marker = undefined;
  }

  private terkepetEpit(): void {
    const elem = this.mapContainer?.nativeElement;
    if (!elem || !this.L) {
      return;
    }

    const kozep = this.kiindulopont();
    this.map = this.L.map(elem).setView([kozep.lat, kozep.lon], this.lat != null ? 16 : 14);

    /*
     * #817: a site kanonikus csempeforrása, nem a direkt OSM.
     *
     * borazslo tünete („a térkép még mindig ilyen foltos") nem a Leafletből jött: az
     * OpenStreetMap a blokkolt kérésre HTTP 200-at ad és egy valódi PNG-t, amire az van
     * írva, hogy „Access blocked". A Leaflet ezt nem tudja hibának látni, kirakja
     * csempeként. A #376 óta a site többi térképe ezért CARTO Voyagert használ — ezt
     * a #816 jegy szövege külön kérte, én meg elnéztem.
     */
    this.L.tileLayer(CSEMPE_URL, CSEMPE_BEALLITAS).addTo(this.map);

    this.map.on('click', (e: any) => this.valaszt(e.latlng.lat, e.latlng.lng));

    this.jeloloFrissit();

    // A méret további változásait a `meretreVar()` figyelője követi.
  }

  /** Ha még nincs választás, a templom a kiindulópont; annak híján Budapest. */
  private kiindulopont(): PickedLocation {
    if (this.lat != null && this.lon != null) {
      return {lat: this.lat, lon: this.lon};
    }
    if (this.churchLat != null && this.churchLon != null) {
      return {lat: this.churchLat, lon: this.churchLon};
    }
    return {lat: 47.4979, lon: 19.0402};
  }

  private jeloloFrissit(): void {
    if (!this.L || !this.map) {
      return;
    }

    if (this.lat == null || this.lon == null) {
      if (this.marker) {
        this.map.removeLayer(this.marker);
        this.marker = undefined;
      }
      return;
    }

    if (!this.marker) {
      this.marker = this.L.marker([this.lat, this.lon], {draggable: true}).addTo(this.map);
      this.marker.on('dragend', () => {
        const p = this.marker.getLatLng();
        this.valaszt(p.lat, p.lng);
      });
    } else {
      this.marker.setLatLng([this.lat, this.lon]);
    }
  }

  /**
   * A kiválasztott pont. Hat tizedesre kerekítünk: az ~11 cm a felszínen, tehát
   * bőven a szabadtéri alkalom pontossága alatt — a több jegy csak zajt vinne a
   * mezőbe, és a kézi beírással sem lenne összemérhető.
   */
  private valaszt(lat: number, lon: number): void {
    const kerekit = (n: number) => Math.round(n * 1e6) / 1e6;
    this.picked.emit({lat: kerekit(lat), lon: kerekit(lon)});
  }
}
