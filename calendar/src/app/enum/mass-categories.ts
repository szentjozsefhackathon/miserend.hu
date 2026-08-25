/**
 * Kategória definíciók - MASTER SOURCE OF TRUTH
 * EZ az egyetlen hely, ahol a kategóriákat kell definiálni!
 * 
 * Új kategória hozzáadása:
 * 1. Ide vesz fel egy új elemet a listára (key és color)
 * 2. Kész! Az enum, a type és az összes definíció automatikusan generálódik
 */
export const MASS_CATEGORY_DEFINITIONS = [
  {
    key: 'MASS',
    color: '#0A0A0A',
  },
  {
    key: 'ADORATION',
    color: '#C4B5A0',
  },
  {
    key: 'CONFESSION',
    color: '#7A4D9A',
  },
  {
    key: 'OTHER',
    color: '#A8B8D0',

    /*
     * #896: olyan alkalmak, amiket FEL kell ismerni, de NEM szabad felkínálni.
     *
     * borazslo döntése: a keresztelő, az esküvő és a temetés csak importból kerül be
     * („a szerkesztő felületen keresztül nem akarunk ilyet állítani"), a keresőben
     * viszont az „egyéb" jó nekik — a teljes elrejtésük már átverés lenne.
     *
     * Ezért KATEGÓRIA-szintű aliasok, nem definíciók: egy új definíció (BAPTISM,
     * WEDDING, …) megjelenne a naptárszerkesztő cím-választójában is, tehát pont azt
     * csinálná, amit nem akarunk. Így viszont a felismerés tud róluk, a szerkesztő nem.
     */
    aliases: [
      'keresztel',            // keresztelő, keresztelõ, keresztelője, keresztelés
      'esküvő', 'eskuvo',
      'temetés', 'temetes',
      'requiem',
      'gyászszertartás', 'gyaszszertartas',
    ],
  }
] as const;

/**
 * Auto-generált type az enum helyett
 * Csak a MASS_CATEGORY_DEFINITIONS-ből származik
 */
export type MassTitleCategory = typeof MASS_CATEGORY_DEFINITIONS[number]['key'];

/**
 * Konstans object az enum helyett - értékek elérésére
 * Ezt a MASS_CATEGORY_DEFINITIONS-ből generáljuk
 */
export const MassTitleCategory = Object.fromEntries(
  MASS_CATEGORY_DEFINITIONS.map(({ key }) => [key, key])
) as Record<MassTitleCategory, MassTitleCategory>;

/**
 * Szín lookup objektum az egyszerűbb hozzáféréshez
 */
export const MASS_CATEGORY_COLORS = Object.fromEntries(
  MASS_CATEGORY_DEFINITIONS.map(({ key, color }) => [key, color])
) as Record<MassTitleCategory, string>;
