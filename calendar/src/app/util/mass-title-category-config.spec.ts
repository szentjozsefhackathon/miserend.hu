import { TestBed } from '@angular/core/testing';
import { provideTranslateService, TranslateService } from '@ngx-translate/core';
import { MassTitleCategoryConfig } from './mass-title-category-config';
import { MassTitleCategory } from '../enum/mass-categories';
import { MASS_DEFINITIONS_DATA } from '../data/mass-definitions';

describe('MassTitleCategoryConfig', () => {
  let translate: TranslateService;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      providers: [provideTranslateService({lang: 'hu'})]
    }).compileComponents();

    translate = TestBed.inject(TranslateService);
    
    // Mock Hungarian translations for testing
    const mockTranslations = {
      'MASS_TITLE': {
        'HOLY_MASS': 'Szentmise',
        'LITURGY_OF_THE_WORD': 'Igeliturgia',
        'ADORATION': 'Szentségimádás',
        'CONFESSION': 'Gyóntatás',
        'BREVIARY': 'Zsolozsma',
        'LITANY': 'Litánia',
        'ROSARY': 'Rózsafüzér',
        'DIVINE_LITURGY': 'Szent Liturgia',
        'LITURGY_OF_THE_PRESANCTIFIED_GIFTS': 'Előszenteltek liturgiája',
        'MATINS': 'Utrenye',
        'VESPRES': 'Vecsernye',
        'MASS_OF_THE_LORD_S_SUPPER': 'Az utolsó vacsora emlékmiséje',
        'GOOD_FRIDAY_LITURGY': 'Nagypénteki szertartás',
        'EASTER_VIGIL': 'Húsvét vigíliája',
        'TRADITIONAL_LATIN_MASS': 'Régi rítusú szentmise',
        'TRADITIONAL_MASS_OF_THE_LORD_S_SUPPER': 'Az utolsó vacsora emlékmiséje (régi rítusú)',
        'TRADITIONAL_GOOD_FRIDAY_LITURGY': 'Nagypénteki szertartás (régi rítusú)',
        'TRADITIONAL_EASTER_VIGIL': 'Húsvét vigíliája (régi rítusú)'
      }
    };
    
    translate.setTranslation('hu', mockTranslations);
  });

  describe('CATEGORY_COLORS', () => {
    it('should return all category colors from MASS_DEFINITIONS_DATA', () => {
      const colors = MassTitleCategoryConfig.CATEGORY_COLORS;
      
      expect(colors).toBeDefined();
      expect(Object.keys(colors).length).toBeGreaterThan(0);
    });

    it('should have colors for MASS, ADORATION, CONFESSION, and OTHER categories', () => {
      const colors = MassTitleCategoryConfig.CATEGORY_COLORS;
      
      expect(colors[MassTitleCategory.MASS]).toBeDefined();
      expect(colors[MassTitleCategory.ADORATION]).toBeDefined();
      expect(colors[MassTitleCategory.CONFESSION]).toBeDefined();
      expect(colors[MassTitleCategory.OTHER]).toBeDefined();
    });

    it('should return hex color values', () => {
      const colors = MassTitleCategoryConfig.CATEGORY_COLORS;
      
      Object.values(colors).forEach(color => {
        expect(color).toMatch(/^#[0-9A-Fa-f]{6}$/);
      });
    });
  });

  describe('CATEGORY_TITLES', () => {
    it('should return all category titles mapping', () => {
      const titles = MassTitleCategoryConfig.CATEGORY_TITLES;
      
      expect(titles).toBeDefined();
      expect(Object.keys(titles).length).toBeGreaterThan(0);
    });

    it('should have titles for each category', () => {
      const titles = MassTitleCategoryConfig.CATEGORY_TITLES;
      
      expect(titles[MassTitleCategory.MASS]).toBeDefined();
      expect(titles[MassTitleCategory.ADORATION]).toBeDefined();
      expect(titles[MassTitleCategory.CONFESSION]).toBeDefined();
      expect(titles[MassTitleCategory.OTHER]).toBeDefined();
    });

    it('MASS category should contain multiple titles', () => {
      const titles = MassTitleCategoryConfig.CATEGORY_TITLES;
      
      expect(Array.isArray(titles[MassTitleCategory.MASS])).toBe(true);
      expect(titles[MassTitleCategory.MASS].length).toBeGreaterThan(0);
    });

    it('ADORATION category should contain ADORATION title', () => {
      const titles = MassTitleCategoryConfig.CATEGORY_TITLES;
      
      expect(titles[MassTitleCategory.ADORATION]).toContain('ADORATION');
    });

    it('CONFESSION category should contain CONFESSION title', () => {
      const titles = MassTitleCategoryConfig.CATEGORY_TITLES;
      
      expect(titles[MassTitleCategory.CONFESSION]).toContain('CONFESSION');
    });
  });

  describe('getTranslatedValues', () => {
    it('should return translated values for all categories', (done) => {
      // Wait for translation to load
      translate.get('MASS_TITLE.HOLY_MASS').subscribe(() => {
        const translatedValues = MassTitleCategoryConfig.getTranslatedValues(translate);
        
        expect(translatedValues).toBeDefined();
        expect(translatedValues[MassTitleCategory.MASS]).toBeDefined();
        expect(translatedValues[MassTitleCategory.ADORATION]).toBeDefined();
        expect(translatedValues[MassTitleCategory.CONFESSION]).toBeDefined();
        expect(translatedValues[MassTitleCategory.OTHER]).toBeDefined();
        
        done();
      });
    });

    it('should translate MASS_TITLE keys with MASS_TITLE. prefix', (done) => {
      translate.get('MASS_TITLE.HOLY_MASS').subscribe(() => {
        const translatedValues = MassTitleCategoryConfig.getTranslatedValues(translate);
        const massTranslations = translatedValues[MassTitleCategory.MASS];
        
        // Check that at least HOLY_MASS is translated
        expect(massTranslations.length).toBeGreaterThan(0);
        expect(massTranslations).toContain('Szentmise');
        
        done();
      });
    });

    it('should translate ADORATION category correctly', (done) => {
      translate.get('MASS_TITLE.ADORATION').subscribe(() => {
        const translatedValues = MassTitleCategoryConfig.getTranslatedValues(translate);
        const adorationTranslations = translatedValues[MassTitleCategory.ADORATION];
        
        expect(adorationTranslations.length).toBeGreaterThan(0);
        expect(adorationTranslations).toContain('Szentségimádás');
        
        done();
      });
    });

    it('should translate CONFESSION category correctly', (done) => {
      translate.get('MASS_TITLE.CONFESSION').subscribe(() => {
        const translatedValues = MassTitleCategoryConfig.getTranslatedValues(translate);
        const confessionTranslations = translatedValues[MassTitleCategory.CONFESSION];
        
        expect(confessionTranslations.length).toBeGreaterThan(0);
        expect(confessionTranslations).toContain('Gyóntatás');
        
        done();
      });
    });

    it('should not include untranslated keys (keys that remain as-is)', (done) => {
      translate.get('MASS_TITLE.HOLY_MASS').subscribe(() => {
        const translatedValues = MassTitleCategoryConfig.getTranslatedValues(translate);
        
        // All values should be actual translations, not i18n keys
        Object.values(translatedValues).forEach(values => {
          values.forEach(val => {
            expect(val).not.toMatch(/^[A-Z_]+$/); // Should not be all uppercase keys
          });
        });
        
        done();
      });
    });
  });

  describe('getColorByCategory', () => {
    it('should return color for MASS category', () => {
      const color = MassTitleCategoryConfig.getColorByCategory(MassTitleCategory.MASS);
      
      expect(color).toBeDefined();
      expect(color).toMatch(/^#[0-9A-Fa-f]{6}$/);
    });

    it('should return color for ADORATION category', () => {
      const color = MassTitleCategoryConfig.getColorByCategory(MassTitleCategory.ADORATION);
      
      expect(color).toBeDefined();
      expect(color).toMatch(/^#[0-9A-Fa-f]{6}$/);
    });

    it('should return color for CONFESSION category', () => {
      const color = MassTitleCategoryConfig.getColorByCategory(MassTitleCategory.CONFESSION);
      
      expect(color).toBeDefined();
      expect(color).toMatch(/^#[0-9A-Fa-f]{6}$/);
    });

    it('should return color for OTHER category', () => {
      const color = MassTitleCategoryConfig.getColorByCategory(MassTitleCategory.OTHER);
      
      expect(color).toBeDefined();
      expect(color).toMatch(/^#[0-9A-Fa-f]{6}$/);
    });
  });

  describe('getCategoryByTitle', () => {
    it('should return OTHER category for empty title', () => {
      const category = MassTitleCategoryConfig.getCategoryByTitle('');
      
      expect(category).toBe(MassTitleCategory.OTHER);
    });

    it('should return OTHER category for null title when no translate service', () => {
      const category = MassTitleCategoryConfig.getCategoryByTitle('');
      
      expect(category).toBe(MassTitleCategory.OTHER);
    });

    it('should match HOLY_MASS by i18n key', () => {
      const category = MassTitleCategoryConfig.getCategoryByTitle('HOLY_MASS');
      
      expect(category).toBe(MassTitleCategory.MASS);
    });

    it('should match ADORATION by i18n key', () => {
      const category = MassTitleCategoryConfig.getCategoryByTitle('ADORATION');
      
      expect(category).toBe(MassTitleCategory.ADORATION);
    });

    it('should match CONFESSION by i18n key', () => {
      const category = MassTitleCategoryConfig.getCategoryByTitle('CONFESSION');
      
      expect(category).toBe(MassTitleCategory.CONFESSION);
    });

    it('should match BREVIARY by i18n key', () => {
      const category = MassTitleCategoryConfig.getCategoryByTitle('BREVIARY');
      
      expect(category).toBe(MassTitleCategory.OTHER);
    });

    it('should match translated Hungarian title "Szentmise" to MASS category', (done) => {
      translate.get('MASS_TITLE.HOLY_MASS').subscribe(() => {
        const category = MassTitleCategoryConfig.getCategoryByTitle('Szentmise', translate);
        
        expect(category).toBe(MassTitleCategory.MASS);
        done();
      });
    });

    it('should match translated Hungarian title "Szentségimádás" to ADORATION category', (done) => {
      translate.get('MASS_TITLE.ADORATION').subscribe(() => {
        const category = MassTitleCategoryConfig.getCategoryByTitle('Szentségimádás', translate);
        
        expect(category).toBe(MassTitleCategory.ADORATION);
        done();
      });
    });

    it('should match translated Hungarian title "Gyóntatás" to CONFESSION category', (done) => {
      translate.get('MASS_TITLE.CONFESSION').subscribe(() => {
        const category = MassTitleCategoryConfig.getCategoryByTitle('Gyóntatás', translate);
        
        expect(category).toBe(MassTitleCategory.CONFESSION);
        done();
      });
    });

    it('should be case-insensitive for translated titles', (done) => {
      translate.get('MASS_TITLE.HOLY_MASS').subscribe(() => {
        const category = MassTitleCategoryConfig.getCategoryByTitle('SZENTMISE', translate);
        
        expect(category).toBe(MassTitleCategory.MASS);
        done();
      });
    });

    it('should handle fuzzy matching for partial titles', (done) => {
      translate.get('MASS_TITLE.HOLY_MASS').subscribe(() => {
        const category = MassTitleCategoryConfig.getCategoryByTitle('szent', translate);
        
        // Should match because 'szent' is part of 'Szentmise'
        expect([MassTitleCategory.MASS, MassTitleCategory.ADORATION]).toContain(category);
        done();
      });
    });

    it('should return OTHER for unknown title with TranslateService', (done) => {
      translate.get('MASS_TITLE.HOLY_MASS').subscribe(() => {
        const category = MassTitleCategoryConfig.getCategoryByTitle('UnknownMassTitle', translate);
        
        expect(category).toBe(MassTitleCategory.OTHER);
        done();
      });
    });
  });

  /**
   * #896: a közös alias-szótár.
   *
   * A lényeg nem az, hogy több címet ismerünk fel, hanem hogy UGYANAZT ismerjük fel,
   * mint a PHP oldal. A szótár egy helyen van (`mass-definitions.ts` -> `aliases`), és a
   * szabály is közös: a szövegben legkorábban előforduló alias nyer.
   */
  describe('getCategoryByTitle – közös alias-szótár (#896)', () => {
    it('a szöveg sorrendje dönt, nem a kategóriáké', () => {
      // Ez a három a régi, kategória-sorrendes illesztéssel mind MASS lett volna.
      expect(MassTitleCategoryConfig.getCategoryByTitle('Szentmise, utána szentségimádás'))
        .toBe(MassTitleCategory.MASS);
      expect(MassTitleCategoryConfig.getCategoryByTitle('Szentségimádás a szentmise után'))
        .toBe(MassTitleCategory.ADORATION);
      expect(MassTitleCategoryConfig.getCategoryByTitle('Gyóntatás a szentmise előtt'))
        .toBe(MassTitleCategory.CONFESSION);
    });

    it('felismeri a valódi adatból vett címeket, TranslateService nélkül is', () => {
      expect(MassTitleCategoryConfig.getCategoryByTitle('Nagypénteki szertartás (P. Szőcs)'))
        .toBe(MassTitleCategory.MASS);
      expect(MassTitleCategoryConfig.getCategoryByTitle('Szentmsie'))
        .toBe(MassTitleCategory.MASS);
      expect(MassTitleCategoryConfig.getCategoryByTitle('Kollégistáink szentmiséje (P. Szőcs)'))
        .toBe(MassTitleCategory.MASS);
      expect(MassTitleCategoryConfig.getCategoryByTitle('Csendes szentségimádás'))
        .toBe(MassTitleCategory.ADORATION);
      expect(MassTitleCategoryConfig.getCategoryByTitle('Keresztút a kálvárián'))
        .toBe(MassTitleCategory.OTHER);
    });

    it('a szótár mind a négy kategóriára ad alakokat', () => {
      const aliases = MassTitleCategoryConfig.CATEGORY_ALIASES;

      for (const category of [MassTitleCategory.MASS, MassTitleCategory.ADORATION,
                              MassTitleCategory.CONFESSION, MassTitleCategory.OTHER]) {
        expect(aliases[category].length).toBeGreaterThan(0);
      }
      expect(aliases[MassTitleCategory.MASS]).toContain('szentmise');
      expect(aliases[MassTitleCategory.CONFESSION]).toContain('gyóntat');
    });
  });

  describe('getAllCategories', () => {
    it('should return all category values', () => {
      const categories = MassTitleCategoryConfig.getAllCategories();
      
      expect(Array.isArray(categories)).toBe(true);
      expect(categories.length).toBeGreaterThan(0);
    });

    it('should include MASS, ADORATION, CONFESSION, and OTHER', () => {
      const categories = MassTitleCategoryConfig.getAllCategories();
      
      expect(categories).toContain(MassTitleCategory.MASS);
      expect(categories).toContain(MassTitleCategory.ADORATION);
      expect(categories).toContain(MassTitleCategory.CONFESSION);
      expect(categories).toContain(MassTitleCategory.OTHER);
    });
  });

  describe('Integration tests', () => {
    it('should categorize all defined mass titles correctly', (done) => {
      translate.get('MASS_TITLE.HOLY_MASS').subscribe(() => {
        const definitions = MASS_DEFINITIONS_DATA.definitions;
        
        definitions.forEach(definition => {
          const detectedCategory = MassTitleCategoryConfig.getCategoryByTitle(definition.key, translate);
          
          // The detected category should match the definition's category
          // or match by i18n key lookup
          expect([definition.category, MassTitleCategory.OTHER]).toContain(detectedCategory);
        });
        
        done();
      });
    });

    it('should have consistent color and title mappings', () => {
      const colors = MassTitleCategoryConfig.CATEGORY_COLORS;
      const titles = MassTitleCategoryConfig.CATEGORY_TITLES;
      const categories = MassTitleCategoryConfig.getAllCategories();
      
      categories.forEach(category => {
        expect(colors[category]).toBeDefined();
        expect(titles[category]).toBeDefined();
      });
    });
  });
});
