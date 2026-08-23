<?php

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Azok az időzített feladatok, amiknek nincs saját helyük
 */
class Crons {

	/*
	 * #496 / #497 / #498: itt állt az archiveLocationOfChurchesWithoutCoordinates(),
	 * ami a koordináta nélküli templomok helyadatát mentette át a megjegyzés mezőbe.
	 *
	 * Kikerült, mert a ledobott oszlopokat olvasta volna — a CI ezt azonnal meg is
	 * fogta. Az archiválás ATTÓL nem maradt el: átkerült magába a migrációs SQL-be,
	 * közvetlenül a DROP elé. Így egyetlen fájlon belül atomi, és nem lehet rossz
	 * sorrendben futtatni.
	 *
	 * Lásd: docker/mysql/migrations/496-497-498-oszlopok-eldobasa.sql
	 */

	/**
	 * #496: újra sorba állítja azokat a templomokat, amiknek nincs administratív
	 * határuk, pedig már ellenőriztük őket.
	 *
	 * A `checkBoundaries()` sora `boundaries_checked_at` szerint halad, és a #570/#700
	 * óta helyesen megkülönbözteti a HIBÁT a "lekérdeztük, de nincs határ" esettől:
	 * hibánál nem bélyegez, tehát a templom a sor elején marad. A második eset viszont
	 * BÉLYEGET kap — és ott is marad, amíg a teljes sor körbe nem ér.
	 *
	 * Ez akkor fáj, ha a "nincs határ" nem az OSM valósága volt, hanem a mi oldalunk
	 * változott azóta. Pontosan ez történt Szlovákiában: a tárolt szintjeink elavultak
	 * (nálunk 8=okres/9=obec, az OSM ma 6=okres/8=obec), és a szlovák minta 23%-ának
	 * emiatt egyáltalán nincs határa. A #699 óta a lekérdezés a 4-es szintet is
	 * behúzza, de a régen bélyegzett templomok ettől még nem próbálkoznak újra.
	 *
	 * Ezért a bélyeget levesszük róluk — a következő futás így elöl veszi őket.
	 *
	 * A 30 napos korlát SZÁNDÉKOS: enélkül azok a templomok, amiknek tényleg nincs
	 * határuk (az OSM ott nem fed le semmit), minden futásban visszakerülnének a sor
	 * elejére, és kiszorítanák a valóban ellenőrizendőket. Így legfeljebb havonta
	 * egyszer próbálkozunk velük újra.
	 *
	 * @return int hány templomot állítottunk vissza a sorba
	 */
	public static function requeueChurchesWithoutBoundary(): int {
		$hatarido = date('Y-m-d H:i:s', strtotime('-30 days'));

		return DB::table('templomok')
			->where('ok', 'i')
			->whereNotNull('lat')->where('lat', '!=', 0)
			->whereNotNull('lon')->where('lon', '!=', 0)
			->whereNotNull('boundaries_checked_at')
			->where('boundaries_checked_at', '<', $hatarido)
			->whereNotExists(function ($q) {
				$q->select(DB::raw(1))
					->from('lookup_boundary_church')
					->join('boundaries', 'boundaries.id', '=', 'lookup_boundary_church.boundary_id')
					->whereColumn('lookup_boundary_church.church_id', 'templomok.id')
					->where('boundaries.boundary', 'administrative');
			})
			->update(['boundaries_checked_at' => null]);
	}

	/**
	 * #496: hány aktív, koordinátával rendelkező templomnak nincs administratív
	 * határa. A /health ezt írja ki, hogy a lefedettségi hiány LÁTSZÓDJON — eddig
	 * csak abból derült volna ki, hogy egy település alatt nem jön ki a templom.
	 */
	/**
	 * #842: bélyeget adunk annak, aminek MÁR MEGVAN a határa.
	 *
	 * A `boundaries_checked_at` oszlop utólagos migrációval került be (initdb.d/03),
	 * NULL alapértékkel. Ettől minden régi templom „soha nem ellenőrzött"-nek látszik,
	 * pedig a határa rég megvan: az éles /health 4950 ilyet mutat, MIKÖZBEN 5052/5052-nek
	 * van administratív határa. A `checkBoundaries()` ezért hónapok óta olyan adatot
	 * kérdez újra az Overpasstól, ami a saját táblánkban ott van.
	 *
	 * A bélyeg SZÁNDÉKOSAN szétszórt a frissességi ablakon belül. Ha mind a mai napot
	 * kapná, fél év múlva egyszerre járna le az összes, és a mai helyzet térne vissza egy
	 * csapásra. Így viszont naponta a töredékük válik esedékessé, egyenletesen.
	 *
	 * Idempotens: csak a bélyeg nélküli, határral rendelkező sorokat érinti. A határ
	 * NÉLKÜLI templomokat nem bántja — azokkal a `requeueChurchesWithoutBoundary()`
	 * foglalkozik, és nekik pont a NULL a jelzésük.
	 *
	 * @return int hány templom kapott bélyeget
	 */
	public static function backfillBoundaryCheckedAt(): int {
		$napok = max(1, (int) round((time() - strtotime('-' . \OSM::BOUNDARY_FRESHNESS)) / 86400));

		return DB::table('templomok')
			->whereNull('boundaries_checked_at')
			->whereExists(function ($q) {
				$q->select(DB::raw(1))
					->from('lookup_boundary_church')
					->join('boundaries', 'boundaries.id', '=', 'lookup_boundary_church.boundary_id')
					->whereColumn('lookup_boundary_church.church_id', 'templomok.id')
					->where('boundaries.boundary', 'administrative');
			})
			->update(['boundaries_checked_at' => DB::raw(
				'DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * ' . $napok . ') DAY)'
			)]);
	}

	public static function churchesWithoutBoundaryCount(): int {
		return DB::table('templomok')
			->where('ok', 'i')
			->whereNotNull('lat')->where('lat', '!=', 0)
			->whereNotNull('lon')->where('lon', '!=', 0)
			->whereNotExists(function ($q) {
				$q->select(DB::raw(1))
					->from('lookup_boundary_church')
					->join('boundaries', 'boundaries.id', '=', 'lookup_boundary_church.boundary_id')
					->whereColumn('lookup_boundary_church.church_id', 'templomok.id')
					->where('boundaries.boundary', 'administrative');
			})
			->count();
	}

	/**
	 * #497: a koordináta nélküli templomok kivétele a megjelenésből.
	 *
	 * borazslo kérése: „A koordináta nélküli templomokat lehet egyszerűen kiiktatjuk.
	 * Egyelőre »nem jelenhet meg« részre. Annak a pár templomnak aminek még az
	 * elhelyezkedését sem tudjuk, annak az adatait sem fogjuk tudni igazán frissen
	 * tartani."
	 *
	 * FIGYELEM: ez publikus tartalmat rejt el. Élesben 47 templomot érint.
	 *
	 * „Áttekintésre vár" (f) állapotba tesszük, nem „letiltva" (n) állapotba: az
	 * „egyelőre" szó ideiglenességre utal, és ezek a templomok nem szabályszegés
	 * miatt kerülnek ki, hanem mert hiányzik az adatuk. Ha mégis a végleges letiltás
	 * a szándék, ez egyetlen karakter a konstansban.
	 *
	 * @return int hány templomot vettünk ki
	 */
	public const KOORDINATA_NELKUL_ALLAPOT = 'f';

	public static function hideChurchesWithoutCoordinates(): int {
		return DB::table('templomok')
			->where('ok', 'i')
			// A lat/lon numerikus oszlop: üres sztringgel összehasonlítva a MySQL
			// "Truncated incorrect DECIMAL value" hibát dob. NULL és 0 elég.
			->where(function ($q) {
				$q->whereNull('lat')->orWhere('lat', 0)
				  ->orWhereNull('lon')->orWhere('lon', 0);
			})
			->update([
				'ok' => self::KOORDINATA_NELKUL_ALLAPOT,
				'adminmegj' => DB::raw(
					"CONCAT(COALESCE(adminmegj, ''), "
					. "IF(COALESCE(adminmegj, '') = '', '', '\n'), "
					. "'[#497] Koordináta hiányában kivéve a megjelenésből.')"
				),
			]);
	}

	/**
	 * #351: a stats_externalapi tábla nő a legnagyobbra. Elég az utolsó 30 nap
	 * megőrzése, a régebbi statisztika-sorokat naponta töröljük. A `date` oszlopra
	 * szűrünk (DATE típus). Cron-ként a crons.sql-ben 41-es id-vel regisztrálva.
	 *
	 * (A #351 nearby.log része tárgytalan: a #724-gyel maga a napló szűnt meg.)
	 */
	public static function cleanExternalApiStats(): void {
		$cutoff = date('Y-m-d', strtotime('-30 days'));
		DB::table('stats_externalapi')->where('date', '<', $cutoff)->delete();
	}

	/**
	 * #724: a használati statisztika napi sorai. Nem személyes adat (nincs benne se IP,
	 * se süti, se User-Agent), tehát a megőrzést nem jogi határidő szabja, hanem az,
	 * hogy meddig érdemes összehasonlítani — két év elég az éves trendhez.
	 */
	public static function cleanUsageStats(): void {
		$cutoff = date('Y-m-d', strtotime('-' . \Stats::MEGORZES));
		DB::table('stats_pageviews')->where('date', '<', $cutoff)->delete();
		DB::table('stats_searches')->where('date', '<', $cutoff)->delete();
	}

	/**
	 * #351: az emails tábla reflex tájékoztató/értesítő leveleit takarítjuk (90 napnál
	 * régebbieket). Az észrevételeket kísérő levelezést (remark_*, remarkfeedback*) ÉS
	 * minden más, itt nem listázott típust MEGTARTJUK — csak a lenti explicit "reflex"
	 * típusokat töröljük, hogy semmi értékes ne vesszen el. A lista a maintainer által
	 * bővíthető, ha többet is takarítana. Cron: crons.sql 42-es id.
	 */
	public static function cleanNotificationEmails(): void {
		$deletableTypes = [
			'user_pleaseupdate',   // "frissítsd az adataidat" reflex emlékeztető
			'user_pleaselogin',    // "jelentkezz be" reflex értesítő
			'churchholders_allowed_user',
			'churchholders_asked_admin',
			'image_admin', 'image_diocese', 'image_responsible',
		];
		/*
		 * #823: a HIBÁS leveleket megtartjuk.
		 *
		 * A `User::isUndeliverable()` pontosan ezekből a sorokból tudja, hogy egy címre
		 * már háromszor nem sikerült kézbesíteni — az az egyetlen bizonyíték. Ha 90 nap
		 * után kitöröljük, a bizonyíték eltűnik, és az értesítő cron újrakezdi a
		 * próbálkozást: három kísérlet, majd megint 90 nap, megint három. Örökre.
		 *
		 * Nem nő el: a harmadik hiba után nem küldünk többet, tehát címenként és
		 * típusonként legfeljebb három ilyen sor keletkezik. Ha a cím később mégis
		 * működni kezd, a sikeres levél időbélyege érvényteleníti a korábbi hibákat
		 * (az `isUndeliverable()` csak az utolsó siker UTÁNI hibákat számolja).
		 */
		$cutoff = date('Y-m-d H:i:s', strtotime('-90 days'));
		DB::table('emails')
			->where('created_at', '<', $cutoff)
			->whereIn('type', $deletableTypes)
			// A NULL státuszt is töröljük: az nem hiba-bizonyíték, csak régi, státusz
			// nélküli sor — a puszta `<> 'error'` viszont az SQL NULL-logikája miatt
			// kihagyná (NULL <> 'error' eredménye NULL, nem igaz).
			->where(function ($q) {
				$q->whereNull('status')->orWhere('status', '<>', 'error');
			})
			->delete();
	}

	/**
	 * #306: a cal_periods alapján gördíti a cal_periods_year-t. Minden FÜGGETLEN
	 * időszakhoz (nincs start/end period_id ÉS nincs start/end month_day) beszúrja a
	 * hiányzó period-év sort a [tavaly, idén, jövőre] tartományra. Idempotens: csak a
	 * hiányzó sorokat pótolja, meglévő sort vagy dátumot NEM módosít.
	 *
	 * Így az évek gördülnek előre, és a szerkesztőnek (periodyeareditor / savePeriodYears)
	 * mindig van mire dátumot beállítania. A dátum-beállítás — különösen éveken átnyúló
	 * időszakoknál — továbbra is KÉZI, ahogy a jegy is jelzi ("évközben lehet rá szükség").
	 * Ezt a cron nem helyettesíti, csak a sorokat készíti elő. Cron: crons.sql 43-as id.
	 */
	public static function rollPeriodYears(): void {
		$now = \Carbon\Carbon::now();
		$years = [$now->year - 1, $now->year, $now->year + 1];

		$existing = \Eloquent\CalPeriodYear::whereIn('start_year', $years)->get()
			->map(fn($py) => $py->period_id . '-' . $py->start_year)->toArray();

		$independentPeriods = \Eloquent\CalPeriod::whereNull('start_period_id')
			->whereNull('end_period_id')
			->whereNull('start_month_day')
			->whereNull('end_month_day')
			->get();

		$toInsert = [];
		foreach ($years as $year) {
			foreach ($independentPeriods as $period) {
				$key = $period->id . '-' . $year;
				if (!in_array($key, $existing, true)) {
					$toInsert[] = [
						'period_id'  => $period->id,
						'start_year' => $year,
						'created_at' => $now,
						'updated_at' => $now,
					];
				}
			}
		}

		if (!empty($toInsert)) {
			\Eloquent\CalPeriodYear::insert($toInsert);
		}
	}

}
