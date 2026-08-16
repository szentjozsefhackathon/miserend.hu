import {Duration} from './mass';
import {Rite} from '../enum/rites';
import {MassType} from '../enum/types';
import {LanguageCode} from '../enum/language-code';
import {Renum} from '../enum/recurrence';
import {Day} from '../enum/day';
import {GeneratedPeriod} from './generated-period';
import {ChristmasDay} from "../enum/christmas-day";
import {EasterDay} from "../enum/easter-day";

export interface DialogEvent {
  /**
   * Az esemény periódusa (liturgikus időszaka).
   * Ha van, ez határozza meg a kezdeti és a végdátumot, melyben ismétlődik az esemény.
   */
  period: GeneratedPeriod | null;
  rite: Rite;
  types: MassType[];
  title: string;
  start: Date;
  duration: Duration;
  /** #334: egy mise több nyelvű is lehet (szlovák-latin, német-magyar). */
  language: LanguageCode[];
  renum: Renum;
  selectedDays: Day[];
  selectedChristmasDay?: ChristmasDay | null;
  selectedEasterDay?: EasterDay | null;
  comment: string;
  editOne: boolean;
  exdate?: string[] | null;
  experiod?: number[] | null;
  // #428: a felhasználó által kézzel beállított kivétel-időszakok
  manualExperiod?: number[] | null;

  /**
   * #431: az alkalom saját helyszíne, ha nem a templomban van.
   * Üresen hagyva a mise a templomban marad.
   */
  locationLat?: number | null;
  locationLon?: number | null;
  locationName?: string | null;
}
