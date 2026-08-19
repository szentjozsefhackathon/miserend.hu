import { MassTitleCategory } from '../../enum/mass-categories';

export interface ExtendedProps {
  massId?: number;
  sensorEventId?: string;
  isSensorEvent?: boolean;
  recentExDates?: string[];
  recentModifiedDates?: string[];
  massTitleCategory?: MassTitleCategory;

  /**
   * #506: család módban melyik templomé ez az esemény.
   *
   * Csak a ROKON templomok eseményein van kitöltve — a saját templom eseményein nem,
   * hogy az egy-templomos szerkesztő adata változatlan maradjon.
   */
  churchId?: number;
  churchName?: string;

  //Ha a naptárba akarunk majd megjeleníteni infókat, azt ide érdemes felvenni.
}
