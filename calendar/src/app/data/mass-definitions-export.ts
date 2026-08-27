import { MASS_DEFINITIONS_DATA, MassDefinition, CategoryDefinition, RiteDefinition } from './mass-definitions';
import { Rite, RITE_DEFINITIONS } from '../enum/rites';
import { MassTitleCategory } from '../enum/mass-categories';

/**
 * Mass definition in the JSON export
 */
export interface MassDefinitionJsonExport {
  key: string;
  category: MassTitleCategory;
  rites: Rite[];
  defaultRite?: Rite;
  description: string;
  specialUsage?: 'EASTER' | 'CHRISTMAS' | null;
  aliases?: string[];
}

/**
 * #896: kategóriánként összefűzött aliasok — a szabad szöveges felismerés szótára.
 *
 * A PHP oldal ezt olvassa (`MassDefinitions::aliasesByCategory()`), és ugyanezt használja
 * az Angular is. Azért kategória szerint csoportosítva, mert a felismerés kimenete a
 * kategória; a definíció-kulcs ehhez nem kell.
 */
export interface AliasesByCategory {
  [category: string]: string[];
}

/**
 * Category definition in the JSON export
 */
export interface CategoryDefinitionJsonExport {
  key: MassTitleCategory;
  color: string;
}

/**
 * Rite definition in the JSON export
 */
export interface RiteDefinitionJsonExport {
  key: Rite;
  masstypes?: string[];
}

/**
 * Titles grouped by category
 */
export interface TitlesByCategory {
  [category: string]: string[];
}

/**
 * Titles grouped by rite
 */
export interface TitlesByRite {
  [rite: string]: string[];
}

/**
 * Complete JSON export structure for mass definitions
 */
export interface MassDefinitionsJsonOutput {
  _generator: string;
  _warning: string;
  categories: CategoryDefinitionJsonExport[];
  rites: RiteDefinitionJsonExport[];
  definitions: MassDefinitionJsonExport[];
  titlesByCategory: TitlesByCategory;
  titlesByRite: TitlesByRite;
  aliasesByCategory: AliasesByCategory;
}

/**
 * Generates a JSON export of mass definitions with pre-computed indexes
 * This function is Node.js compatible and can be used in build scripts
 * 
 * @returns MassDefinitionsJsonOutput - Complete JSON export with all metadata
 */
export function generateMassDefinitionsJson(): MassDefinitionsJsonOutput {
  const MASS_TITLE_PREFIX = 'MASS_TITLE.';
  
  // Initialize category index
  const titlesByCategory: TitlesByCategory = {};

  // #896: a szabad szöveges alakok ugyanígy, kategóriánként
  const aliasesByCategory: AliasesByCategory = {};
  
  // Initialize rite index
  const titlesByRite: TitlesByRite = {};
  
  // Build category index
  MASS_DEFINITIONS_DATA.categories.forEach((cat: CategoryDefinition) => {
    titlesByCategory[cat.key] = [];

    // #896: a kategória saját aliasai (keresztelő, esküvő, temetés) — ezekhez nincs
    // definíció, mert nem szabad felkínálni őket a naptárszerkesztőben.
    aliasesByCategory[cat.key] = cat.aliases ? [...cat.aliases] : [];
  });
  
  // Build rite index
  MASS_DEFINITIONS_DATA.rites.forEach((rite: RiteDefinition) => {
    titlesByRite[rite.key] = [];
  });
  
  // Process definitions and populate indexes
  MASS_DEFINITIONS_DATA.definitions.forEach((def: MassDefinition) => {
    const exportKey = MASS_TITLE_PREFIX + def.key;
    
    // Add to category index
    if (titlesByCategory[def.category]) {
      titlesByCategory[def.category].push(exportKey);
    }

    // #896: az aliasok kategóriánként, ismétlődés nélkül
    if (aliasesByCategory[def.category] && def.aliases) {
      def.aliases.forEach((alias) => {
        if (!aliasesByCategory[def.category].includes(alias)) {
          aliasesByCategory[def.category].push(alias);
        }
      });
    }
    
    // Add to rite index (for each rite this definition belongs to)
    def.rites.forEach((rite) => {
      if (titlesByRite[rite]) {
        titlesByRite[rite].push(exportKey);
      }
    });
  });
  
  // Build rites with masstypes from RITE_DEFINITIONS
  const ritesWithMasstypes: RiteDefinitionJsonExport[] = MASS_DEFINITIONS_DATA.rites.map((rite: RiteDefinition) => {
    const riteDefinition = RITE_DEFINITIONS.find((rd: any) => rd.key === rite.key);
    return {
      key: rite.key,
      masstypes: riteDefinition?.massTypes ? riteDefinition.massTypes.map((mt: string) => mt) : []
    };
  });
  
  // Return the complete JSON export structure with prefixed keys
  const exportDefinitions: MassDefinitionJsonExport[] = MASS_DEFINITIONS_DATA.definitions.map(def => ({
    ...def,
    key: def.key
  }));
  
  return {
    _generator: 'mass-definitions-export.ts',
    _warning: 'Auto-generated from calendar/src/app/data/mass-definitions.ts during Angular build',
    // A kategória-aliasok az `aliasesByCategory`-ban vannak; itt csak a kulcs és a szín,
    // hogy ugyanaz az adat ne szerepeljen két helyen a kimenetben.
    categories: MASS_DEFINITIONS_DATA.categories.map(({ key, color }) => ({ key, color })),
    rites: ritesWithMasstypes,
    definitions: exportDefinitions,
    titlesByCategory,
    titlesByRite,
    aliasesByCategory
  };
}
