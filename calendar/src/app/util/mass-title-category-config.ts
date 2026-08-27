import { MassTitleCategory } from '../enum/mass-categories';
import { TranslateService } from '@ngx-translate/core';
import { MASS_DEFINITIONS_DATA, MassDefinitionsHelper } from '../data/mass-definitions';

/**
 * Kategória-szín és kategória-cím párosítások konfigurációja
 * Az adatok a centralizált MASS_DEFINITIONS_DATA-ból származnak
 * Biztosítja az egyházilag illő halványabb árnyalatokat
 */
export class MassTitleCategoryConfig {
  /**
   * Kategória színek: a MASS_DEFINITIONS_DATA-ból lekérdezve
   * Minden szín a centralizált adatforrásból származik, nincs fallback
   */
  static get CATEGORY_COLORS(): Record<MassTitleCategory, string> {
    const colors: Record<MassTitleCategory, string> = {} as Record<MassTitleCategory, string>;

    // Kategóriák és színek dinamikusan a MASS_DEFINITIONS_DATA-ból
    for (const categoryDef of MASS_DEFINITIONS_DATA.categories) {
      const key = categoryDef.key as MassTitleCategory;
      colors[key] = categoryDef.color;
    }

    return colors;
  }

  /**
   * Kategória-cím párosítások (i18n key-ek) a MASS_DEFINITIONS_DATA-ból
   * Dinamikusan generálva, kategóriák a MASS_DEFINITIONS_DATA-ból
   */
  static get CATEGORY_TITLES(): Record<MassTitleCategory, string[]> {
    // Inicializáljuk az összes kategóriát dinamikusan
    const titles: Record<MassTitleCategory, string[]> = {} as Record<MassTitleCategory, string[]>;
    
    for (const categoryDef of MASS_DEFINITIONS_DATA.categories) {
      const key = categoryDef.key as MassTitleCategory;
      titles[key] = [];
    }

    // Felépítjük a kategóriák alapján a MASS_DEFINITIONS_DATA-ból
    for (const definition of MASS_DEFINITIONS_DATA.definitions) {
      const category = definition.category as MassTitleCategory;
      if (titles[category]) {
        titles[category].push(definition.key);
      }
    }

    return titles;
  }

  /**
   * #896: kategóriánkénti szabad szöveges alakok — ugyanaz az adat, amit a PHP olvas.
   *
   * A szótár a definíciók mellett él (`mass-definitions.ts` -> `aliases`), az Angular
   * build pedig ugyanezt exportálja a `webapp/mass-definitions.json`-ba. Egy forrás,
   * két olvasó.
   */
  static get CATEGORY_ALIASES(): Record<MassTitleCategory, string[]> {
    const aliases: Record<MassTitleCategory, string[]> = {} as Record<MassTitleCategory, string[]>;

    for (const categoryDef of MASS_DEFINITIONS_DATA.categories) {
      // #896: a kategória saját aliasai (keresztelő, esküvő, temetés) — ezekhez nincs
      // definíció, mert a naptárszerkesztőben nem szabad megjelenniük.
      aliases[categoryDef.key as MassTitleCategory] = categoryDef.aliases ? [...categoryDef.aliases] : [];
    }

    for (const definition of MASS_DEFINITIONS_DATA.definitions) {
      const category = definition.category as MassTitleCategory;
      if (!aliases[category] || !definition.aliases) continue;

      for (const alias of definition.aliases) {
        if (!aliases[category].includes(alias)) {
          aliases[category].push(alias);
        }
      }
    }

    return aliases;
  }

  /**
   * #896: a címben LEGKORÁBBAN előforduló alias kategóriája.
   *
   * A magyar naptárcímek a főeseményt írják előre, ezért a SZÖVEG sorrendje dönt, nem a
   * kategóriáké. Ez a különbség nem elméleti: a „Gyóntatás a szentmise előtt" a régi,
   * kategória-sorrendes illesztéssel MASS lett, a PHP szerint viszont CONFESSION — a
   * naptár tehát mást színezett, mint amit a kereső talált.
   *
   * Azonos pozíciónál a hosszabb alias nyer (az a szűkebb találat), így az eredmény nem
   * függ a kategóriák felsorolási sorrendjétől. Ugyanez a szabály fut a PHP oldalon
   * (`MassDefinitions::categoryForTitle()`).
   */
  private static matchByAliases(lowerTitle: string): MassTitleCategory | null {
    let nyertes: MassTitleCategory | null = null;
    let hol = -1;
    let hossz = 0;

    for (const [category, aliases] of Object.entries(this.CATEGORY_ALIASES)) {
      for (const alias of aliases as string[]) {
        const pozicio = this.aliasPozicio(lowerTitle, alias);
        if (pozicio === -1) continue;

        if (hol === -1 || pozicio < hol || (pozicio === hol && alias.length > hossz)) {
          hol = pozicio;
          hossz = alias.length;
          nyertes = category as MassTitleCategory;
        }
      }
    }

    return nyertes;
  }

  /**
   * #896: az alias első olyan előfordulása, ami SZÓ ELEJÉN áll.
   *
   * Szóhatár nélkül az „UnknownMassTitle"-ből a `mass` alias misét csinálna. Szó VÉGÉT
   * nem kötünk ki: az aliasok egy része szándékosan tő („szentségimád"), hogy a ragozott
   * alakok is illeszkedjenek. Ugyanez a szabály fut a PHP oldalon
   * (`MassDefinitions::aliasPozicio()`).
   */
  private static aliasPozicio(cim: string, alias: string): number {
    let tol = 0;

    for (;;) {
      const pozicio = cim.indexOf(alias, tol);
      if (pozicio === -1) return -1;

      const elozo = pozicio === 0 ? '' : cim.charAt(pozicio - 1);
      if (elozo === '' || !/\p{L}/u.test(elozo)) return pozicio;

      tol = pozicio + 1;
    }
  }

  // Lefordított szövegek a kategóriákhoz - dinamikusan generálva az i18n JSON alapján
  private static _translatedValuesCache: Record<MassTitleCategory, string[]> | null = null;

  /**
   * Lefordított szövegek a kategóriákhoz (támogatás a fordított értékekhez)
   * Dinamikusan generálódik az i18n JSON fájlokból
   * @param translate A TranslateService az i18n értékek lekéréséhez
   */
  static getTranslatedValues(translate: TranslateService): Record<MassTitleCategory, string[]> {
    // Inicializáljuk az összes kategóriát dinamikusan
    const translatedValues: Record<MassTitleCategory, string[]> = {} as Record<MassTitleCategory, string[]>;
    
    for (const categoryDef of MASS_DEFINITIONS_DATA.categories) {
      const key = categoryDef.key as MassTitleCategory;
      translatedValues[key] = [];
    }

    // Végigmegyünk az összes kategórián és i18n kulcson
    for (const [category, titleKeys] of Object.entries(this.CATEGORY_TITLES)) {
      const values = new Set<string>();
      
      for (const titleKey of titleKeys) {
        // Try with MASS_TITLE. prefix first
        let translated = translate.instant('MASS_TITLE.' + titleKey);
        
        // If that didn't work, try without prefix
        if (translated === 'MASS_TITLE.' + titleKey) {
          translated = translate.instant(titleKey);
        }
        
        // Csak akkor adjuk hozzá, ha sikerült lefordítani (nem az i18n key marad meg)
        if (translated && translated !== titleKey && translated !== 'MASS_TITLE.' + titleKey) {
          values.add(translated);
        }
      }
      
      translatedValues[category as MassTitleCategory] = Array.from(values);
    }

    return translatedValues;
  }

  

  /**
   * Szín lekérése kategória alapján
   */
  static getColorByCategory(category: MassTitleCategory): string {
    return this.CATEGORY_COLORS[category];
  }

  /**
   * Kategória lekérése title alapján
   * Támogatja az i18n key-eket és a lefordított szövegeket is (case-insensitive)
   * @param title A keresendő cím
   * @param translate Opcionális TranslateService az i18n értékekhez (ha nincs, fallback értékeket használunk)
   */
  static getCategoryByTitle(title: string, translate?: TranslateService): MassTitleCategory {
    if (!title) {
      // Default kategória az MASS_DEFINITIONS_DATA-ból
      return MASS_DEFINITIONS_DATA.categories[MASS_DEFINITIONS_DATA.categories.length - 1].key as MassTitleCategory;
    }

    // Először próbáljuk meg az i18n key alapján (pl. "MASS_TITLE.ADORATION")
    for (const [category, titles] of Object.entries(this.CATEGORY_TITLES)) {
      if (titles.includes(title)) {
        return category as MassTitleCategory;
      }
    }

    const lowerTitleForAliases = title.toLowerCase();

    // #896: a közös szótár. Ez fut a TranslateService nélkül is — eddig ilyenkor minden
    // cím a default kategóriába esett, holott a felismeréshez a fordítás nem is kell.
    const aliasTalalat = this.matchByAliases(lowerTitleForAliases);
    if (aliasTalalat) {
      return aliasTalalat;
    }

    // Lekérjük a lefordított értékeket
    if (!translate) {
      // Default kategória az MASS_DEFINITIONS_DATA-ból
      return MASS_DEFINITIONS_DATA.categories[MASS_DEFINITIONS_DATA.categories.length - 1].key as MassTitleCategory;
    }

    const translatedValuesToUse = this.getTranslatedValues(translate);

    // Majd próbáljuk a lefordított szövegeket (case-insensitive)
    const lowerTitle = title.toLowerCase();
    for (const [category, translatedValues] of Object.entries(translatedValuesToUse)) {
      if (translatedValues.some((val: string) => val.toLowerCase() === lowerTitle)) {
        return category as MassTitleCategory;
      }
    }

    // Végül fuzzy match: ha a title tartalmazza valamelyik ismert szöveget
    for (const [category, translatedValues] of Object.entries(translatedValuesToUse)) {
      for (const val of translatedValues) {
        if (lowerTitle.includes((val as string).toLowerCase()) || (val as string).toLowerCase().includes(lowerTitle)) {
          return category as MassTitleCategory;
        }
      }
    }

    // Default kategória az MASS_DEFINITIONS_DATA-ból
    return MASS_DEFINITIONS_DATA.categories[MASS_DEFINITIONS_DATA.categories.length - 1].key as MassTitleCategory;
  }

  /**
   * Összes kategória lista
   */
  static getAllCategories(): MassTitleCategory[] {
    return Object.values(MassTitleCategory);
  }
}
