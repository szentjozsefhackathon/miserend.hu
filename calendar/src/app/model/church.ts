import {Mass} from './mass';
import {Rite} from '../enum/rites';
import {SensorEvent} from './sensor-event';

/**
 * #506: a templom „családja" — a plébánia és a fíliái.
 *
 * A szerver a `?family=1` kérésre adja vissza. Minden családtag benne van, mert a
 * miserend nyilvános adat és a plébánia rendjét együtt látni akkor is hasznos, ha nem
 * mindegyikhez van jogod; hogy melyikbe LEHET írni, azt a `writable` mondja meg. A
 * mentés a szerveren úgyis templomonként ellenőrzi a jogosultságot.
 */
export interface ChurchFamilyMember {
  id: number;
  name: string;
  city: string;
  writable: boolean;
  isCurrent: boolean;
  masses: Mass[];
}

export interface Church {
  id: number;
  name: string;
  rite: Rite;
  timeZone: string;
  masses: Mass[];
  eventsFromSensor?: SensorEvent[];
  country?: string;
  family?: ChurchFamilyMember[];
}
