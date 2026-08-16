export interface ReadableMass {
  period?: string;
  days?: string;
  christmas?: string;
  easter?: string;
  week?: string;
  month?: string;
  time: string;
  startDate: string;
  mDates?: string[];
  massId: number;
  title?: string;
  types?: string;
  rite?: string;
  duration?: string;
  lang: string;
  comment?: string;
  experiod?: string[];
  /**
   * #431: az alkalom saját helyszíne, olvasható alakban („Röszkei puszta" vagy a
   * koordináta). Üresen hagyva a mise a templomban van — a javaslat-összefoglalóban
   * ez a különbség épp olyan fontos, mint az időpont.
   */
  ownLocation?: string;
}
