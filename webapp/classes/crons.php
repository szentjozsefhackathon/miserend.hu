<?php

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Azok az időzített feladatok, amiknek nincs saját helyük
 */
class Crons {

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
