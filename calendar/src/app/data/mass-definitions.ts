import { MassTitleCategory, MASS_CATEGORY_DEFINITIONS, type MassTitleCategory as MassTitleCategoryType } from '../enum/mass-categories';
import { Rite } from '../enum/rites';

/**
 * Egy MASS_TITLE egyedi definíciója
 * Az összes metaadat egy helyen
 */
export interface MassDefinition {
  /** i18n key (pl. "HOLY_MASS") */
  key: string;

  /** Kategória */
  category: MassTitleCategory;

  /** Melyik rite-okhoz tartozik */
  rites: Rite[];

  /** Default rite (ha csak egyre jellemző) */
  defaultRite?: Rite;

  /** Leírás (comment) */
  description: string;

  /** Speciális felhasználás (pl. Easter, Christmas) */
  specialUsage?: 'EASTER' | 'CHRISTMAS' | null;
}

/**
 * Kategória-cím párosítás (TypeScript verzió - titleKeys NÉLKÜL)
 * A titleKeys az export-ban lesz benne, de a TS-ben nem szükséges (kiszámítható)
 */
export interface CategoryDefinition {
  key: MassTitleCategory;
  color: string;
}

/**
 * Kategóriák automatikus generálása a MASTER SOURCE OF TRUTH-ból
 * MASS_CATEGORY_DEFINITIONS az egyetlen hely az értékek definiálásához!
 */
const generateCategories = (): CategoryDefinition[] => {
  return MASS_CATEGORY_DEFINITIONS.map(({ key, color }) => ({
    key: key as MassTitleCategory,
    color
  }));
};

/**
 * Rite-cím párosítás (TypeScript verzió - titleKeys NÉLKÜL)
 * A titleKeys az export-ban lesz benne, de a TS-ben nem szükséges (kiszámítható)
 */
export interface RiteDefinition {
  key: Rite;
}

/**
 * A teljes centralizált adatstruktúra (TypeScript)
 */
export interface MassDefinitionsData {
  version: string;
  lastUpdated: string;
  definitions: MassDefinition[];
  categories: CategoryDefinition[];
  rites: RiteDefinition[];
}

/**
 * Központi adatstruktúra az összes MASS_TITLE definícióval
 * Ez a SINGLE SOURCE OF TRUTH az összes misekategória és rite adatra
 */
export const MASS_DEFINITIONS_DATA: MassDefinitionsData = {
  version: '1.0.1',
  lastUpdated: '2026-05-27',
  definitions: [
    // ============ MASS kategória ============

    {
      key: 'HOLY_MASS',
      category: MassTitleCategory.MASS,
      rites: [Rite.ROMAN_CATHOLIC],
      description: 'Roman Catholic Sunday/weekday Mass',
      specialUsage: null
    },

    {
      key: 'LITURGY_OF_THE_WORD',
      category: MassTitleCategory.MASS,
      rites: [Rite.ROMAN_CATHOLIC],
      description: 'Liturgy of the Word',
      specialUsage: null
    },

    {
      key: 'DIVINE_LITURGY',
      category: MassTitleCategory.MASS,
      rites: [Rite.GREEK_CATHOLIC],
      defaultRite: Rite.GREEK_CATHOLIC,
      description: 'Greek Catholic Divine Liturgy',
      specialUsage: null
    },

    {
      key: 'LITURGY_OF_THE_PRESANCTIFIED_GIFTS',
      category: MassTitleCategory.MASS,
      rites: [Rite.GREEK_CATHOLIC],
      description: 'Greek Catholic Liturgy of Presanctified Gifts',
      specialUsage: null
    },

    {
      key: 'MASS_OF_THE_LORD_S_SUPPER',
      category: MassTitleCategory.MASS,
      rites: [
        Rite.ROMAN_CATHOLIC,
        Rite.TRADITIONAL
      ],
      description: 'Mass of the Lord\'s Supper (Holy Thursday)',
      specialUsage: 'EASTER'
    },

    {
      key: 'GOOD_FRIDAY_LITURGY',
      category: MassTitleCategory.MASS,
      rites: [
        Rite.ROMAN_CATHOLIC,
        Rite.TRADITIONAL
      ],
      description: 'Good Friday Liturgy of the Passion',
      specialUsage: 'EASTER'
    },

    {
      key: 'EASTER_VIGIL',
      category: MassTitleCategory.MASS,
      rites: [
        Rite.ROMAN_CATHOLIC,
        Rite.TRADITIONAL
      ],
      description: 'Easter Vigil Mass',
      specialUsage: 'EASTER'
    },

    {
      key: 'TRADITIONAL_LATIN_MASS',
      category: MassTitleCategory.MASS,
      rites: [Rite.TRADITIONAL],
      defaultRite: Rite.TRADITIONAL,
      description: 'Traditional Latin Mass (Tridentine Rite)',
      specialUsage: null
    },
    

    // ============ ADORATION kategória ============

    {
      key: 'ADORATION',
      category: MassTitleCategory.ADORATION,
      rites: [Rite.ROMAN_CATHOLIC],
      description: 'Eucharistic Adoration',
      specialUsage: null
    },

    // ============ CONFESSION kategória ============

    {
      key: 'CONFESSION',
      category: MassTitleCategory.CONFESSION,
      rites: [
        Rite.ROMAN_CATHOLIC,
        Rite.GREEK_CATHOLIC
      ],
      description: 'Confession/Penance service',
      specialUsage: null
    },

    // ============ OTHER kategória ============

    {
      key: 'BREVIARY',
      category: MassTitleCategory.OTHER,
      rites: [Rite.ROMAN_CATHOLIC],
      description: 'Liturgy of the Hours (Breviary)',
      specialUsage: null
    },

    {
      key: 'ROSARY',
      category: MassTitleCategory.OTHER,
      rites: [Rite.ROMAN_CATHOLIC],
      description: 'Rosary recitation',
      specialUsage: null
    },

    {
      key: 'LITANY',
      category: MassTitleCategory.OTHER,
      rites: [Rite.ROMAN_CATHOLIC],
      description: 'Litany service',
      specialUsage: null
    },

    {
      // #593: keresztút. A bejelentésben a kenyérmegáldás is szerepelt, azt borazslo
      // elvetette ("Jó lenne minél lejjebb tartani a szertartástípusok számát"), a
      // keresztutat viszont jóváhagyta.
      key: 'STATIONS_OF_THE_CROSS',
      category: MassTitleCategory.OTHER,
      rites: [Rite.ROMAN_CATHOLIC],
      description: 'Stations of the Cross (Way of the Cross) devotion',
      specialUsage: null
    },

    {
      key: 'MATINS',
      category: MassTitleCategory.OTHER,
      rites: [Rite.GREEK_CATHOLIC],
      description: 'Greek Catholic Matins service',
      specialUsage: null
    },

    {
      key: 'VESPRES',
      category: MassTitleCategory.OTHER,
      rites: [Rite.GREEK_CATHOLIC],
      description: 'Greek Catholic Vespres service',
      specialUsage: null
    }
  ],

  categories: generateCategories(),

  rites: [
    {
      key: Rite.ROMAN_CATHOLIC
    },
    {
      key: Rite.GREEK_CATHOLIC
    },
    {
      key: Rite.TRADITIONAL
    }
  ]
};

/**
 * Segéd osztály a centralizált MASS_TITLE adatok eléréséhez
 */
export class MassDefinitionsHelper {
   private static readonly MASS_TITLE_PREFIX = 'MASS_TITLE.';
   
   /**
    * Összes title-definíció lekérése rite alapján szűrve
    */
   static getTitlesByRite(rite: Rite): MassDefinition[] {
     return MASS_DEFINITIONS_DATA.definitions.filter(
       def => def.rites.some(rm => rm === rite)
     );
   }
   
   /**
    * Összes title-definíció lekérése kategória alapján
    */
   static getTitlesByCategory(category: MassTitleCategory): MassDefinition[] {
     return MASS_DEFINITIONS_DATA.definitions.filter(
       def => def.category === category
     );
   }
   
   /**
    * Title-definíció lekérése i18n key alapján
    */
   static getDefinitionByKey(key: string): MassDefinition | undefined {
     return MASS_DEFINITIONS_DATA.definitions.find(def => def.key === key);
   }
   
   /**
    * Kategória szín lekérése
    */
   static getCategoryColor(category: MassTitleCategory): string {
     return MASS_DEFINITIONS_DATA.categories.find(c => c.key === category)?.color || '#A8B8D0';
   }
   
   /**
    * I18n key lista rite alapján (MASS_TITLE. prefixszel)
    */
   static getTitleKeysByRite(rite: Rite): string[] {
     return this.getTitlesByRite(rite).map(def => this.MASS_TITLE_PREFIX + def.key);
   }
   
   /**
    * Default title lekérése rite alapján
    */
   static getDefaultTitleByRite(rite: Rite): string {
     const titles = this.getTitlesByRite(rite);
     const defaultTitle = titles.find(def => def.defaultRite === rite);
     return defaultTitle?.key || titles[0]?.key || 'HOLY_MASS';
   }
   
   /**
    * Összes kategória lekérése
    */
   static getAllCategories(): MassTitleCategory[] {
     return MASS_DEFINITIONS_DATA.categories.map(c => c.key);
   }

   /**
    * Összes Húsvét-specifikus title key (MASS_TITLE. prefixszel)
    */
   static getEasterSpecificTitleKeys(): string[] {
     return MASS_DEFINITIONS_DATA.definitions
       .filter(def => def.specialUsage === 'EASTER')
       .map(def => this.MASS_TITLE_PREFIX + def.key);
   }

   /**
    * Összes Karácsony-specifikus title key (MASS_TITLE. prefixszel)
    */
   static getChristmasSpecificTitleKeys(): string[] {
     return MASS_DEFINITIONS_DATA.definitions
       .filter(def => def.specialUsage === 'CHRISTMAS')
       .map(def => this.MASS_TITLE_PREFIX + def.key);
   }

   /**
    * Get i18n key with MASS_TITLE. prefix
    */
   static getPrefixedKey(key: string): string {
     return this.MASS_TITLE_PREFIX + key;
   }

   /**
    * Get unprefixed key (remove MASS_TITLE. prefix if present)
    */
   static getUnprefixedKey(key: string): string {
     if (key.startsWith(this.MASS_TITLE_PREFIX)) {
       return key.substring(this.MASS_TITLE_PREFIX.length);
     }
     return key;
   }
}
