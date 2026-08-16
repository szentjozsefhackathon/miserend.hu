import {Mass} from './mass';
import {Rite} from '../enum/rites';
import {SensorEvent} from './sensor-event';

export interface Church {
  id: number;
  name: string;
  rite: Rite;
  timeZone: string;
  masses: Mass[];
  eventsFromSensor?: SensorEvent[];
  country?: string;
  /** #816: a templom koordinátája — a térképes helyszínválasztó innen indul. */
  lat?: number;
  lon?: number;
}
