<?php
/**
 * BF2Statistics ASP Framework
 *
 * Author:       Steven Wilson
 * Copyright:    Copyright (c) 2006-2017, BF2statistics.com
 * License:      GNU GPL v3
 *
 */
use System\DataTables;
use System\TimeHelper;

/**
 * Player Ajax Model
 *
 * This model is used to fetch player data for the dataTables javascript library
 *
 * @package Models
 * @subpackage Players
 */
class PlayerAjaxModel
{
    /**
     * @var \System\Database\DbConnection The stats database connection
     */
    protected $pdo;

    /**
     * @var int Time stamp of one week ago
     */
    private static $OneWeekAgo = 0;

    /**
     * @var int Time stamp of two weeks ago
     */
    private static $TwoWeekAgo = 0;

    /**
     * PlayerAjaxModel constructor.
     */
    public function __construct()
    {
        // Fetch database connection
        $this->pdo = System\Database::GetConnection('stats');

        // Set static vars
        if (self::$OneWeekAgo == 0)
        {
            self::$OneWeekAgo = time() - (86400 * 7);
            self::$TwoWeekAgo = time() - (86400 * 14);
        }
    }

    /**
     * This method retrieves the player list for DataTables
     *
     * @param array $data The GET or POSTS data for DataTables
     *
     * @return array
     */
    public function getPlayerList($data)
    {
        $columns = [
            ['db' => 'id', 'dt' => 'id'],
            ['db' => 'name', 'dt' => 'name'],
            ['db' => 'rank', 'dt' => 'rank',
                'formatter' => function( $d, $row ) {
                    return "<img class='center' src=\"/ASP/frontend/images/ranks/rank_{$d}.gif\">";
                }
            ],
            ['db' => 'score', 'dt' => 'score',
                'formatter' => function( $d, $row ) {
                    return number_format($d);
                }
            ],
            ['db' => 'country', 'dt' => 'country'],
            ['db' => 'joined', 'dt' => 'joined',
                'formatter' => function( $d, $row ) {
                    $i = (int)$d;
                    return date('d M Y', $i);
                }
            ],
            ['db' => 'lastonline', 'dt' => 'online',
                'formatter' => function( $d, $row ) {
                    $i = (int)$d;
                    return TimeHelper::FormatDifference($i, time());
                }
            ],
            ['db' => 'permban', 'dt' => 'permban',
                'formatter' => function( $d, $row ) {
                    $id = $row['id'];
                    $badge = (!$d) ? 'success' : 'important';
                    $text = (!$d) ? 'Active' : 'Banned';
                    $last = (int)$row['lastonline'];
                    $time = time();
                    $timestamp = 86400 * 30;

                    if (($time - $last) > $timestamp)
                    {
                        $badge = 'inactive';
                        $text = 'Inactive';
                    }

                    return '<span id="tr-status-'. $id .'" class="badge badge-'. $badge .'">'. $text .'</span>';
                }
            ],
            ['db' => 'kicked', 'dt' => 'actions',
                'formatter' => function( $d, $row ) {
                    $id = $row['id'];
                    $banned = ($row['permban'] == 1) ? '' : ' style="display: none"';
                    $nbanned = ($row['permban'] == 0) ? '' : ' style="display: none"';

                    return '<span class="btn-group">
                            <a id="go-'. $id .'" href="/ASP/players/view/'. $id .'"  rel="tooltip" title="View Player" class="btn btn-small"><i class="icon-eye-open"></i></i></a>
                            <a id="edit-btn-'. $id .'" href="#"  rel="tooltip" title="Edit Player" class="btn btn-small"><i class="icon-pencil"></i></a>
                            <a id="ban-btn-'. $id .'" href="#" rel="tooltip" title="Ban Player" class="btn btn-small"'. $nbanned .'><i class="icon-flag"></i></a>
                            <a id="unban-btn-'. $id .'" href="#" rel="tooltip" title="Unban Player" class="btn btn-small"'.$banned.'><i class="icon-ok"></i></a>
                            <a id="delete-btn-'. $id .'" href="#" rel="tooltip" title="Delete Player" class="btn btn-small"><i class="icon-trash"></i></a>
                        </span>';
                }
            ],
        ];

        // Use the DataTables library class
        $applyFilter = ((int)$_POST['showBots']) == 0;
        $filter = ($applyFilter) ? "`password` != ''" : '';
        return DataTables::FetchData($data, $this->pdo, 'player', 'id', $columns, $filter);
    }

    /**
     * This method retrieves the player history list for DataTables
     *
     * @param int $id The player id
     * @param array $data The GET or POSTS data for DataTables
     *
     * @return array
     */
    public function fetchPlayerRoundHistory($id, $data)
    {
        $columns = [
            ['db' => 'player_id', 'dt' => 'id'],
            ['db' => 'round_id', 'dt' => 'rid'],
            ['db' => 'name', 'dt' => 'server'],
            ['db' => 'mapname', 'dt' => 'map'],
            ['db' => 'score', 'dt' => 'score',
                'formatter' => function( $d, $row ) {
                    return number_format($d);
                }
            ],
            ['db' => 'kills', 'dt' => 'kills',
                'formatter' => function( $d, $row ) {
                    return number_format($d);
                }
            ],
            ['db' => 'deaths', 'dt' => 'deaths',
                'formatter' => function( $d, $row ) {
                    return number_format($d);
                }
            ],
            ['db' => 'time', 'dt' => 'time',
                'formatter' => function( $d, $row ) {
                    $i = (int)$d;
                    return TimeHelper::SecondsToHms($i);
                }
            ],
            ['db' => 'team', 'dt' => 'team',
                'formatter' => function( $d, $row ) {
                    return "<img class='center' src=\"/ASP/frontend/images/armies/small/{$d}.png\">";
                }
            ],
            ['db' => 'time_end', 'dt' => 'timestamp',
                'formatter' => function( $d, $row ) {
                    $i = (int)$d;
                    return date('F jS, Y g:i A T', $i);
                }
            ],
            ['db' => 'rank', 'dt' => 'actions',
                'formatter' => function( $d, $row ) {
                    $id = (int)$row['player_id'];
                    $rid = (int)$row['round_id'];

                    return '<span class="btn-group">
                            <a href="/ASP/players/history/'. $id .'/'. $rid .'"  rel="tooltip" title="View Round Details" class="btn btn-small">
                                <i class="icon-eye-open"></i>
                            </a>
                        </span>';
                }
            ],
        ];

        // Fetch data
        $filter = "`player_id` = ". $id;
        return DataTables::FetchData($data, $this->pdo, 'player_history_view', 'player_id', $columns, $filter);
    }

    /**
     * Fetches the chart data for the Home page
     *
     * @param int $playerId
     *
     * @return array
     */
    public function getTimePlayedChartData($playerId)
    {
        // sanitize
        $playerId = (int)$playerId;

        // prepare output
        $output = array(
            'week' => ['y' => [], 'x' => []],
            'month' => ['y' => [], 'x' => []],
            'year' => ['y' => [], 'x' => []]
        );

        /* -------------------------------------------------------
         * WEEK
         * -------------------------------------------------------
         */
        $todayStart = new DateTime('6 days ago midnight');
        $timestamp = $todayStart->getTimestamp();

        // Build array
        $temp = [];
        for ($iDay = 6; $iDay >= 0; $iDay--)
        {
            $key = date('l (m/d)', time() - ($iDay * 86400));
            $temp[$key] = 0;
        }

        $query = "SELECT `imported` FROM player_round_history AS h LEFT JOIN round AS r ON h.round_id = r.id WHERE h.player_id = $playerId AND `imported` > $timestamp";
        $result = $this->pdo->query($query);
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
            $temp[] = $key1 . ' - ' . $key2;
        }

        $i = 0;
        foreach ($timeArrays as $start => $finish)
        {
            $query = "SELECT COUNT(`player_id`) FROM player_round_history AS h LEFT JOIN round AS r ON h.round_id = r.id WHERE h.player_id = $playerId AND `imported` BETWEEN $start AND $finish";
            $result = (int)$this->pdo->query($query)->fetchColumn(0);

            $output['month']['y'][] = array($i, $result);
            $output['month']['x'][] = array($i, $temp[$i]);
            $i++;
        }

        /* -------------------------------------------------------
         * YEAR
         * -------------------------------------------------------
         */

        $temp = [];

        // Yep, php DateTime using strings is BadAss!!
        $start = new DateTime('first day of 11 months ago');
        $end = new DateTime('last day of this month');
        $interval = DateInterval::createFromDateString('1 month');

        $period = new DatePeriod($start, $interval, $end);
        $prev = null;
        $timeArrays = [];

        foreach ($period as $p)
        {
            // Start
            $temp[] = $p->format('M Y');
            $timestamp = $p->getTimestamp();

            // End
            $p->modify('+1 month');

            // Append
            $timeArrays[$timestamp] = $p->getTimestamp();
        }

        $i = 0;
        foreach ($timeArrays as $start => $finish)
        {
            $query = "SELECT COUNT(`player_id`) FROM player_round_history AS h LEFT JOIN round AS r ON h.round_id = r.id WHERE h.player_id = $playerId AND `imported` BETWEEN $start AND $finish";
            $result = (int)$this->pdo->query($query)->fetchColumn(0);

            $output['year']['y'][] = array($i, $result);
            $output['year']['x'][] = array($i, $temp[$i]);
            $i++;
        }

        // return chart data
        return $output;
    }
}