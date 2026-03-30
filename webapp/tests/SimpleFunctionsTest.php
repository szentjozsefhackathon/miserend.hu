<?php

use PHPUnit\Framework\TestCase;

class SimpleFunctionsTest extends TestCase {

    public function testSanitizeTrimsAndKeepsAllowedTags() {
        $input = "  <b>Árvíztűrő</b>\n<script>alert('x')</script><a href='/ok'>link</a>  ";

        $result = sanitize($input);

        $this->assertEquals("<b>Árvíztűrő</b><br/>alert('x')<a href='/ok'>link</a>", $result);
    }

    public function testSanitizeWorksRecursivelyOnArrays() {
        $input = array(
            'title' => "  <strong>Cím</strong>  ",
            'body' => "sor1\nsor2"
        );

        $result = sanitize($input);

        $this->assertEquals('<strong>Cím</strong>', $result['title']);
        $this->assertEquals('sor1<br/>sor2', $result['body']);
    }

    public function testGetWeekInMonthForFirstOccurrence() {
        $this->assertEquals(1, getWeekInMonth('2026-03-01'));
    }

    public function testGetWeekInMonthCountsWeeksBackwardsWithinMonth() {
        $this->assertEquals(3, getWeekInMonth('2026-03-15'));
    }

    public function testGetWeekInMonthCountsWeeksForwardWithinMonthWhenOrderIsMinus() {
        $this->assertEquals(-3, getWeekInMonth('2026-03-15', '-'));
    }

    public function testGetWeekInMonthReturnsZeroForUnknownOrder() {
        $this->assertEquals(0, getWeekInMonth('2026-03-15', 'unknown'));
    }

    public function testTwigHungarianDateFormatForTodayWithoutTime() {
        $result = twig_hungarian_date_format(date('Y-m-d H:i:s'), '');

        $this->assertEquals('ma', $result);
    }

    public function testTwigHungarianDateFormatForTodayWithDefaultTime() {
        $timestamp = time();

        $result = twig_hungarian_date_format($timestamp);

        $this->assertStringStartsWith('ma ', $result);
        $this->assertMatchesRegularExpression('/^ma \d{2}:\d{2}$/', $result);
    }

    public function testTwigHungarianDateFormatForYesterday() {
        $timestamp = strtotime('-1 day');

        $result = twig_hungarian_date_format($timestamp, '');

        $this->assertStringStartsWith('tegnap, ', $result);
    }

    public function testTwigHungarianDateFormatForYesterdayWithTime() {
        $timestamp = strtotime('-1 day');

        $result = twig_hungarian_date_format($timestamp, 'H:i');

        $this->assertStringStartsWith('tegnap, ', $result);
        $this->assertMatchesRegularExpression('/ \d{2}:\d{2}$/', $result);
    }

    public function testTwigHungarianDateFormatForTomorrow() {
        $timestamp = strtotime('+1 day');

        $result = twig_hungarian_date_format($timestamp, '');

        $this->assertStringStartsWith('holnap, ', $result);
    }

    public function testTwigHungarianDateFormatForTomorrowWithTime() {
        $timestamp = strtotime('+1 day');

        $result = twig_hungarian_date_format($timestamp, 'H:i');

        $this->assertStringStartsWith('holnap, ', $result);
        $this->assertMatchesRegularExpression('/ \d{2}:\d{2}$/', $result);
    }

    // Ha az aktuális héten van (előző vasárnaptól következő vasárnapig) és nem tegnap/ma/holnap
    public function testTwigHungarianDateFormatForDateInCurrentWeekButNotTodayYesterdayTomorrow() {    
        $lastSunday = strtotime('last sunday');
        $todayMidnight = strtotime(date('Y-m-d'));
        $daysBetween = (int)(($todayMidnight - $lastSunday) / 86400);
        if ($daysBetween > 3) {
            // later code's "-3 days" will be inside the current week — keep the default behavior
            $candidate = strtotime('last monday', $todayMidnight);
        } else {
            // early in the week: pick a day inside this week (e.g. Monday) so it's not today/yesterday/tomorrow
            $candidate = strtotime('next saturday', $lastSunday);
        }

        //Ez sajnos kell mert a twig_extras keresi a $_honapok, de nem kapja meg, mert a load.php nélkül került meghívásra innen.
        global $_honapok;
        $monthNumber = (int)date('n', $candidate);
        if (!isset($_honapok[$monthNumber][0]) || $_honapok[$monthNumber][0] === '') {
            $_honapok[$monthNumber] = ['mon', 'month'];
        }
                
        $result = twig_hungarian_date_format($candidate, '');

        $this->assertMatchesRegularExpression('/\d{1,2}\.(,)?/u', $result);
    }

    public function testTwigHungarianDateFormatForDateInCurrentWeekIncludesTimeWhenRequested() {
        $lastSunday = strtotime('last sunday');
        $todayMidnight = strtotime(date('Y-m-d'));
        $daysBetween = (int)(($todayMidnight - $lastSunday) / 86400);
        if ($daysBetween > 3) {
            // later code's "-3 days" will be inside the current week — keep the default behavior
            $candidate = strtotime('last monday', $todayMidnight);
        } else {
            // early in the week: pick a day inside this week (e.g. Monday) so it's not today/yesterday/tomorrow
            $candidate = strtotime('next saturday', $lastSunday);
        }

        //Ez sajnos kell mert a twig_extras keresi a $_honapok, de nem kapja meg, mert a load.php nélkül került meghívásra innen.
        global $_honapok;
        $monthNumber = (int)date('n', $candidate);
        if (!isset($_honapok[$monthNumber][0]) || $_honapok[$monthNumber][0] === '') {
            $_honapok[$monthNumber] = ['mon', 'month'];
        }

        $result = twig_hungarian_date_format($candidate, 'H:i');

        $this->assertMatchesRegularExpression('/\d{1,2}\.(,)?/u', $result);
        $this->assertMatchesRegularExpression('/\s\d{2}:\d{2}$/', $result);
    }

    public function testTwigHungarianDateFormatForOlderDateUsesMonthDayAndWeekday() {
        $timestamp = strtotime('-10 days');

        $result = twig_hungarian_date_format($timestamp, '');

        $this->assertStringContainsString(', ', $result);
        $this->assertStringContainsString('.', $result);
    }

    public function testTwigHungarianDateFormatForOlderDateIncludesTimeWhenRequested() {
        $timestamp = strtotime('-10 days');

        $result = twig_hungarian_date_format($timestamp, 'H:i');

        $this->assertMatchesRegularExpression('/ \d{2}:\d{2}$/', $result);
    }
}
