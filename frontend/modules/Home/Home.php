<?php
/**
 * BF2Statistics ASP Framework
 *
 * Author:       Steven Wilson
 * Copyright:    Copyright (c) 2006-2017, BF2statistics.com
 * License:      GNU GPL v3
 *
 */
use System\Controller;
use System\Config;
use System\Database;
use System\IO\Directory;
use System\IO\Path;
use System\View;

class Home extends Controller
{
    /**
     * @protocol    ANY
     * @request     /ASP/[?:home/]
     * @output      html
     */
	public function index()
	{
	    // Require database connection
        $this->requireDatabase();
        $pdo = Database::GetConnection('stats');

	    // Create view
        $view = new View('index', 'home');
        $view->set('php_version', PHP_VERSION);
        $view->set('server_name', php_uname('s'));
        $view->set('server_version', apache_get_version() );
        $view->set('db_version', $pdo->query('SELECT version()')->fetchColumn(0));
        $view->set('last_login', date('F jS, Y g:i A T', Config::Get('admin_last_login')));

	    // Get database size
        $size = 0;
        $q = $pdo->query("SHOW TABLE STATUS");
        while ($row = $q->fetch())
        {
            $size += $row["Data_length"] + $row["Index_length"];
        }
        $view->set('db_size', number_format($size / (1024*1024), 2));

        // Games Processed
        $rounds = $pdo->query('SELECT COUNT(id) FROM round_history')->fetchColumn();
        $view->set('num_rounds', number_format($rounds));

        // Failed count
        $path = Path::Combine(SYSTEM_PATH, 'snapshots', 'failed');
        $count = count(Directory::GetFiles($path, '^(.*)\.txt$'));
        $view->set('failed_snapshots', number_format($count));

        // Number of players
        $result = $pdo->query("SELECT COUNT(id) FROM player");
        $view->set('num_players', number_format($result->fetchColumn(0)));

        $oneWeekAgo = time() - (86400 * 7);
        $twoWeekAgo = time() - (86400 * 14);

        // Number of Active players
        $result = $pdo->query("SELECT COUNT(id) FROM player WHERE lastonline > ". $oneWeekAgo);
        $active = (int)$result->fetchColumn(0);
        $view->set('num_active_players', number_format($active));

        // Set arrow direction (with leading space) for active player count
        $result = $pdo->query("SELECT COUNT(id) FROM player WHERE lastonline BETWEEN $twoWeekAgo AND $oneWeekAgo");
        $inactive = (int)$result->fetchColumn(0);
        $view->set('active_player_raise', ($inactive == $active) ? '' : (($inactive > $active) ? ' down' : ' up'));

        // Number of Active servers
        $result = $pdo->query("SELECT COUNT(id) FROM server WHERE lastupdate > ". $oneWeekAgo);
        $active = (int)$result->fetchColumn(0);
        $view->set('num_active_servers', number_format($active));

        // Number of Active players
        $result = $pdo->query("SELECT COUNT(id) FROM server WHERE lastupdate BETWEEN $twoWeekAgo AND $oneWeekAgo");
        $inactive = (int)$result->fetchColumn(0);
        $view->set('active_server_raise', ($inactive == $active) ? '' : (($inactive > $active) ? ' down' : ' up'));

        // Attach chart plotting scripts
        $view->attachScript("./frontend/js/flot/jquery.flot.min.js");
        $view->attachScript("./frontend/js/flot/plugins/jquery.flot.tooltip.js");
        $view->attachScript("./frontend/modules/home/js/chart.js");

        // Attach stylesheets
        $view->attachStylesheet("/ASP/frontend/css/icons/icol32.css");

        // Draw View
		$view->render();
	}

    /**
     * @protocol    GET
     * @request     /ASP/home/chartData
     * @output      json
     */
    public function getChartData()
    {
        // Require database connection
        $pdo = Database::GetConnection('stats');

        $output = array(
            'week' => ['y' =>[], 'x' => []],
            'month' => ['y' =>[], 'x' => []],
            'year' => ['y' =>[], 'x' => []]
        );

        /* -------------------------------------------------------
         * WEEK
         * -------------------------------------------------------
         */
        $todayStart = new DateTime('6 days ago midnight');
        $timestamp = $todayStart->getTimestamp();

        $temp = [];

        // Build array
        for ($iDay = 6; $iDay >= 0; $iDay--)
        {
            $key  = date('l (m/d)', time() - ($iDay * 86400));
            $temp[$key] = 0;
        }

        $query = "SELECT `imported` FROM round_history WHERE `imported` > $timestamp";
        $result = $pdo->query($query);

        while ($row = $result->fetch())
        {
            $key = date("l (m/d)", (int)$row['imported']);
            $temp[$key] += 1;
        }

        $i = 0;
        foreach ($temp as $key => $value)
        {
            $output['week']['y'][] = array($i, $value);
            $output['week']['x'][] = array($i++, $key);
        }

        /* -------------------------------------------------------
         * MONTH
         * -------------------------------------------------------
         */

        $temp = [];

        $start = new DateTime('6 weeks ago');
        $end = new DateTime('now');
        $interval = DateInterval::createFromDateString('1 week');

        $period = new DatePeriod($start, $interval, $end);
        $prev = null;
        $timeArrays = [];

        foreach ($period as $p)
        {
            // Start
            $p->modify('+1 minute');
            $key1 = $p->format('M d');
            $timestamp = $p->getTimestamp();

            // End
            $p->modify('+7 days');
            $key2 = $p->format('M d');

            // Append
            $timeArrays[$timestamp] = $p->getTimestamp();
            $temp[] = $key1 .' - '. $key2;
        }

        $i = 0;
        foreach ($timeArrays as $start => $finish)
        {
            $query = "SELECT COUNT(*) FROM round_history WHERE `imported` BETWEEN $start AND $finish";
            $result = (int)$pdo->query($query)->fetchColumn(0);

            $output['month']['y'][] = array($i, $result);
            $output['month']['x'][] = array($i, $temp[$i]);
            $i++;
        }

        /* -------------------------------------------------------
         * YEAR
         * -------------------------------------------------------
         */

        $temp = [];

        $start = new DateTime('12 months ago');
        $end = new DateTime('now');
        $interval = DateInterval::createFromDateString('1 month');

        $period = new DatePeriod($start, $interval, $end);
        $prev = null;
        $timeArrays = [];

        foreach ($period as $p)
        {
            // Start
            $key1 = $p->format('M Y');
            $timestamp = $p->getTimestamp();

            // End
            $p->modify('+1 month');

            // Append
            $timeArrays[$timestamp] = $p->getTimestamp();
            $temp[] = $key1;
        }

        $i = 0;
        foreach ($timeArrays as $start => $finish)
        {
            $query = "SELECT COUNT(*) FROM round_history WHERE `imported` BETWEEN $start AND $finish";
            $result = (int)$pdo->query($query)->fetchColumn(0);

            $output['year']['y'][] = array($i, $result);
            $output['year']['x'][] = array($i, $temp[$i]);
            $i++;
        }

        // Output
        echo json_encode($output); die;
    }

    protected function getApacheVersion()
    {
        if (function_exists('apache_get_version'))
        {
            if (preg_match('|Apache\/(\d+)\.(\d+)\.(\d+)|', apache_get_version(), $version))
            {
                return $version[1].'.'.$version[2].'.'.$version[3];
            }
        }
        elseif (isset($_SERVER['SERVER_SOFTWARE']))
        {
            if (preg_match('|Apache\/(\d+)\.(\d+)\.(\d+)|', $_SERVER['SERVER_SOFTWARE'], $version))
            {
                return $version[1].'.'.$version[2].'.'.$version[3];
            }
        }

        return '(unknown)';
    }
}