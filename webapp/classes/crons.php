<?php

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Azok az időzített feladatok, amiknek nincs saját helyük
 */
class Crons {

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
		$cutoff = date('Y-m-d H:i:s', strtotime('-90 days'));
		DB::table('emails')
			->where('created_at', '<', $cutoff)
			->whereIn('type', $deletableTypes)
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
