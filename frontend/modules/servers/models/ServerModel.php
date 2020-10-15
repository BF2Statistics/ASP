<?php
/**
 * BF2Statistics ASP Framework
 *
 * Author:       Steven Wilson
 * Copyright:    Copyright (c) 2006-2019, BF2statistics.com
 * License:      GNU GPL v3
 *
 */
use GameQ\GameQ;
use System\BF2\Player;
use System\Collections\Dictionary;
use System\IO\Path;
use System\Net\IPAddress;
use System\TimeHelper;

/**
 * Server Model
 *
 * @package Models
 * @subpackage Servers
 */
class ServerModel
{
    /**
     * @var \System\Database\DbConnection The stats database connection
     */
    public $pdo;

    /**
     * @var int Time stamp of one week ago
     */
    private static $OneWeekAgo = 0;

    /**
     * @var int Time stamp of two weeks ago
     */
    private static $TwoWeekAgo = 0;

    /** @var Dictionary */
    private static $Abbreviations = null;

    /**
     * ServerModel constructor.
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
     * Fetches a list of all the servers from the server table, including the
     * number of snapshots they have submitted.
     *
     * @return array
     */
    public function fetchServers()
    {
        $query = <<<SQL
SELECT s.*, p.name AS provider_name, p.authorized, p.plasma, COUNT(r2.id) AS `snapshots`
FROM `server` AS s
  LEFT JOIN stats_provider AS p on s.provider_id = p.id
  LEFT JOIN round AS r2 on s.id = r2.server_id
GROUP BY s.id
ORDER BY s.id ASC
SQL;

        $servers = $this->pdo->query($query)->fetchAll();
        if (empty($servers))
            return [];

        // Get real authorization
        $providers = new \System\Collections\Dictionary();
        $query = "SELECT * FROM `stats_provider_auth_ip`";
        $rows = $this->pdo->query($query)->fetchAll();

        foreach ($rows as $row)
        {
            $id = (int)$row['provider_id'];
            if ($providers->containsKey($id))
            {
                if (!isset($providers[$id]))
                    $providers[$id][] = $row['address'];
                else
                    $providers->add($id, array($row['address']));
            }
            else
            {
                $providers->add($id, array($row['address']));
            }
        }

        // Add authorized tag per server
        for ($i = 0; $i < count($servers); $i++)
        {
            $serverIp = IPAddress::Parse($servers[$i]['ip']);
            $providerId = (int)$servers[$i]['provider_id'];
            $auth = false;

            foreach ($providers[$providerId] as $address)
            {
                if ($serverIp->isInCidr($address))
                {
                    $auth = true;
                    break;
                }
            }


            $servers[$i]['authorized'] = ($auth) ? 1 : 0;
        }

        return $servers;
    }

    /**
     * Fetches a server record from the database by id
     *
     * @param int $id The server id
     *
     * @return bool|array false if the server id does not exist, otherwise
     *  the server row is returned
     */
    public function fetchServerById($id)
    {
        $id = (int)$id;
        $query = <<<SQL
SELECT s.*, p.name AS provider_name, p.authorized AS provider_authorized, p.plasma, COUNT(r.id) AS `snapshots`
FROM `server` AS s
  LEFT JOIN stats_provider AS p on s.provider_id = p.id
  LEFT JOIN round AS r on s.id = r.server_id
WHERE s.id={$id}
SQL;
        $server = $this->pdo->query($query)->fetch();
        if (empty($server))
            return false;

        // Get real authorization
        $providerId = (int)$server['provider_id'];
        $addresses = [];
        $query = "SELECT `address` FROM `stats_provider_auth_ip` WHERE `provider_id`={$providerId}";
        $rows = $this->pdo->query($query)->fetchAll();

        foreach ($rows as $row)
        {
            $addresses[] = $row['address'];
        }

        // Add authorized tag per server
        $serverIp = IPAddress::Parse($server['ip']);
        $auth = false;

        foreach ($addresses as $address)
        {
            if ($serverIp->isInCidr($address))
            {
                $auth = true;
                break;
            }
        }

        $server['server_authorized'] = ($auth) ? 1 : 0;
        return $server;
    }

    /**
     * Adds a new server record in the server table
     *
     * @param int $provider_id The stats provider this server falls under
     * @param string $name The server name, no longer than 100 characters.
     * @param string $ip The server's ip address
     * @param int $port The game port
     * @param int $queryPort The gamespy port
     *
     * @return int the server id, or 0 on error
     *
     * @throws ArgumentException
     */
    public function addServer($provider_id, $name, $ip, $port, $queryPort)
    {
        /**
         * Check for valid IP address
         * @var \System\Net\iIPAddress $address
         */
        if (!IPAddress::TryParse(trim($ip), $address))
            throw new ArgumentException('Invalid IpAddress passed.', 'ip');

        // Check length of server name
        $name = preg_replace("/[^". Player::NAME_REGEX ."]/", '', trim($name));
        if (empty($name))
            throw new ArgumentException('Empty or illegal server name passed', 'name');

        // Name Length check
        if (strlen($name) > 100)
            throw new ArgumentException('Server name cannot be longer than 100 characters!', 'name');

        // Sanitize port number
        $port = abs((int)$port);
        if ($port > 65535)
            throw new ArgumentException('Port number is Invalid!', 'port');

        // Sanitize Query Port
        $queryPort = abs((int)$queryPort);
        if ($queryPort > 65535)
            throw new ArgumentException('Port number is Invalid!', 'queryPort');

        // Prepare statement
        $this->pdo->insert('server', [
            'provider_id' => $provider_id,
            'name' => $name,
            'ip' => $address->toString(),
            'gameport' => $port,
            'queryport' => $queryPort,
        ]);
        $serverId = (int)$this->pdo->lastInsertId('id');

        // Return server ID
        return $serverId;
    }

    /**
     * updates a server record in the server table
     *
     * @param int $id The server id
     * @param string $name The server name, no longer than 100 characters.
     * @param string $ip The server's ip address
     * @param int $port The game port
     * @param int $queryPort The gamespy port
     *  false otherwise
     *
     * @return bool true on success, false otherwise
     *
     * @throws ArgumentException
     */
    public function updateServerById($id, $name, $ip, $port, $queryPort)
    {
        /**
         * Check for valid IP address
         * @var \System\Net\iIPAddress $address
         */
        if (!IPAddress::TryParse(trim($ip), $address))
            throw new ArgumentException('Invalid IpAddress passed.', 'ip');

        // Check length of server name
        $name = preg_replace("/[^". Player::NAME_REGEX ."]/", '', trim($name));
        if (empty($name))
            throw new ArgumentException('Empty or illegal server name passed', 'name');

        // Name Length check
        if (strlen($name) > 100)
            throw new ArgumentException('Server name cannot be longer than 100 characters!', 'name');

        // Sanitize port number
        $port = abs((int)$port);
        if ($port > 65535)
            throw new ArgumentException('Port number is Invalid!', 'port');

        // Sanitize Query Port
        $queryPort = abs((int)$queryPort);
        if ($queryPort > 65535)
            throw new ArgumentException('Port number is Invalid!', 'queryPort');

        // Prepare statement
        return $this->pdo->update('server', [
            'name' => $name,
            'ip' => $address->toString(),
            'gameport' => $port,
            'queryport' => $queryPort,
        ], ['id' => (int)$id]);
    }

    /**
     * Deletes a list of servers by id
     *
     * @param int[] $ids A list of server ids to perform the action on
     *
     * @throws Exception thrown if a server has a game processed,
     *          or there is an error in the SQL statement
     */
    public function deleteServers($ids)
    {
        $count = count($ids);

        // Prepared statement!
        try
        {
            // Transaction if more than 2 servers
            if ($count > 2)
                $this->pdo->beginTransaction();

            // Prepare statement
            $stmt = $this->pdo->prepare("DELETE FROM server WHERE id=:id");
            foreach ($ids as $serverId)
            {
                // Ignore the all!
                if ($serverId == 'all') continue;

                // Ensure a game has not been played!
                $query = "SELECT COUNT(`id`) FROM round WHERE server_id=". (int)$serverId;
                $games = (int)$this->pdo->query($query)->fetchColumn(0);
                if ($games > 0)
                    throw new Exception("Cannot delete Server (ID: {$serverId}) because it has saved stats in the database");

                // Bind value and run query
                $stmt->bindValue(':id', (int)$serverId, PDO::PARAM_INT);
                $stmt->execute();
            }

            // Commit?
            if ($count > 2)
                $this->pdo->commit();
        }
        catch (Exception $e)
        {
            // Rollback?
            if ($count > 2)
                $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Queries a BF2 server for its current round information
     *
     * @param string $ip The servers IP address
     * @param int $port The servers Game Query Port
     *
     * @return array|false an array of server details, or false if
     *  the server is offline
     */
    public function queryServer($ip, $port)
    {
        // Include the AutoLoader for GameQ
        require_once SYSTEM_PATH . DS . 'framework' . DS . 'GameQ' . DS . 'Autoloader.php';

        // Query the server
        $key = $ip . ':'. $port;
        $GameQ = new GameQ();
        $GameQ->addServer(['type' => 'bf2', 'host' => $key, 'options' => ['query_port' => $port]]);
        $GameQ->setOption('timeout', 5); // seconds
        $results = $GameQ->process();

        // Is the server offline?
        $results = $results[$key];
        if (!$results['gq_online'])
            return false;

        // Prepare return values
        $return = [
            'server' => [],
            'team1' => [],
            'team2' => []
        ];

        // Format the return array
        foreach ($results as $key => $value)
        {
            if ($key == "players")
            {
                // Separate players by team
                foreach ($value as $player)
                {
                    // For some reason, GameQ adds the teams as a player too
                    if (!isset($player['team']))
                        continue;

                    // Add player Rank
                    $this->addPlayerRank($player);
                    $return['team'. $player['team']][] = $player;
                }
            }
            else
            {
                $return['server'][$key] = $value;
            }
        }

        // Send return array
        unset($results);
        return $return;
    }

    /**
     * Converts an army name from a server response to it's full name representation.
     *
     * @param string $name [Reference Variable] Returns the army full name if we can.
     * @param int $flag [Reference Variable] Returns the flag ID for this army, or -1
     *
     * @return bool
     */
    public function getArmyFromAbbreviation(&$name, &$flag)
    {
        // Load abbreviations if we haven't already
        if (self::$Abbreviations == null)
        {
            $abvs = include Path::Combine(SYSTEM_PATH, 'config', 'armyAbbreviationMap.php');
            self::$Abbreviations = new Dictionary(true, $abvs);
        }

        // lowercase name
        $name = strtolower($name);

        // Check if abbreviation is mapped
        if (self::$Abbreviations->containsKey($name))
        {
            $data = self::$Abbreviations[$name];
            $name = $data['name'];
            $flag = $data['flag'];
            return true;
        }
        else
        {
            $flag = -1;
            $name = "Unknown";
            return false;
        }
    }

    /**
     * Formats the values of a servers response
     *
     * @param array $rules
     *
     * @return array
     */
    public function formatRules($rules)
    {
        $return = [];

        foreach ($rules as $key => $value)
        {
            switch ($key)
            {
                case 'password':
                case 'bf2_ranked':
                case 'bf2_bots':
                case 'bf2_dedicated':
                    $return[$key] = (((int)$value) == 1) ? "True" : "False";
                    break;
                case 'bf2_anticheat':
                case 'bf2_friendlyfire':
                case 'bf2_globalunlocks':
                case 'bf2_autobalanced':
                    $return[$key] = (((int)$value) == 1) ? "Enabled" : "Disabled";
                    break;
                case 'bf2_novehicles':
                    $return[$key] = (((int)$value) == 0) ? "Enabled" : "Disabled";
                    break;
                case 'roundtime':
                case 'timelimit':
                    $return[$key] = TimeHelper::SecondsToHms((int)$value);
                    break;
                case 'bf2_teamratio':
                    $return[$key] = (int)$value;
                    break;
                case 'teams':
                    $return['team1score'] = 0;
                    $return['team2score'] = 0;
                    if (is_array($value) && count($value) == 2)
                    {
                        $return['team1score'] = $value[0]['score'];
                        $return['team2score'] = $value[1]['score'];
                    }
                    break;
                default:
                    $return[$key] = $value;
                    break;
            }
        }

        return $return;
    }

    /**
     * Adds the players rank to each player from a server response
     *
     * @param array $player
     *
     * @return void
     */
    protected function addPlayerRank(&$player)
    {
        $id = (int)$player['pid'];
        if ($id == 0)
        {
            $player['rank'] = 0;
        }
        else
        {
            $rows = $this->pdo->query("SELECT name, rank_id FROM player WHERE id={$id} LIMIT 1");
            if ($row = $rows->fetch())
            {
                $sn = strtolower(trim($player['player']));
                $dn = strtolower(trim($row['name']));
                $player['rank'] = ($dn == $sn) ? (int)$row['rank_id'] : 0;
            }
            else
            {
                $player['rank'] = 0;
            }
        }
    }

    /**
     * Fetches the chart data for the Home page
     *
     * @param int $serverId
     *
     * @return array
     */
    public function getServerChartData($serverId)
    {
        // sanitize
        $serverId = (int)$serverId;

        // prepare output
        $output = array(
            'week' => ['y' => ['server' => [], 'total' => []], 'x' => ['server' => [], 'total' => []]],
            'month' => ['y' => ['server' => [], 'total' => []], 'x' => ['server' => [], 'total' => []]],
            'year' => ['y' => ['server' => [], 'total' => []], 'x' => ['server' => [], 'total' => []]]
        );

        /* -------------------------------------------------------
         * WEEK
         * -------------------------------------------------------
         */
        $todayStart = new DateTime('6 days ago midnight');
        $timestamp = $todayStart->getTimestamp();

        // Build array
        $serverCounts = [];
        $totalCounts = [];
        for ($iDay = 6; $iDay >= 0; $iDay--)
        {
            $key = date('l (m/d)', time() - ($iDay * 86400));
            $serverCounts[$key] = 0;
            $totalCounts[$key] = 0;
        }

        $query = "SELECT `time_imported`, `server_id` FROM round WHERE `time_imported` > $timestamp";
        $result = $this->pdo->query($query);
        while ($row = $result->fetch())
        {
            $key = date("l (m/d)", (int)$row['time_imported']);
            $totalCounts[$key] += 1;

            if ($row['server_id'] == $serverId)
                $serverCounts[$key] += 1;
        }

        $i = 0;
        foreach ($serverCounts as $key => $value)
        {
            $output['week']['y']['server'][] = array($i, $value);
            $output['week']['x']['server'][] = array($i++, $key);
        }

        $i = 0;
        foreach ($totalCounts as $key => $value)
        {
            $output['week']['y']['total'][] = array($i, $value);
            $output['week']['x']['total'][] = array($i++, $key);
        }

        /* -------------------------------------------------------
         * MONTH
         * -------------------------------------------------------
         */

        $serverCounts = [];

        $start = new DateTime('6 weeks ago');
        $end = new DateTime('now');
        $interval = DateInterval::createFromDateString('1 week');

        $period = new DatePeriod($start, $interval, $end);
        $prev = null;
        $timeArrays = [];

        foreach ($period as $p)
        {
            // Start
            /* @var $p DateTime */
            $p->modify('+1 minute');
            $key1 = $p->format('M d');
            $timestamp = $p->getTimestamp();

            // End
            $p->modify('+7 days');
            $key2 = $p->format('M d');

            // Append
            $timeArrays[$timestamp] = $p->getTimestamp();
            $serverCounts[] = $key1 . ' - ' . $key2;
        }

        $i = 0;
        foreach ($timeArrays as $start => $finish)
        {
            // Server
            $query = "SELECT COUNT(`time_imported`) FROM round WHERE server_id = $serverId AND `time_imported` BETWEEN $start AND $finish";
            $result = (int)$this->pdo->query($query)->fetchColumn(0);

            $output['month']['y']['server'][] = array($i, $result);
            $output['month']['x']['server'][] = array($i, $serverCounts[$i]);

            // Total
            $query = "SELECT COUNT(`time_imported`) FROM round WHERE `time_imported` BETWEEN $start AND $finish";
            $result = (int)$this->pdo->query($query)->fetchColumn(0);

            $output['month']['y']['total'][] = array($i, $result);
            $output['month']['x']['total'][] = array($i, $serverCounts[$i]);
            $i++;
        }

        /* -------------------------------------------------------
         * YEAR
         * -------------------------------------------------------
         */

        $serverCounts = [];

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
            $serverCounts[] = $p->format('M Y');
            $timestamp = $p->getTimestamp();

            // End
            $p->modify('+1 month');

            // Append
            $timeArrays[$timestamp] = $p->getTimestamp();
        }

        $i = 0;
        foreach ($timeArrays as $start => $finish)
        {
            // Server
            $query = "SELECT COUNT(`time_imported`) FROM round WHERE server_id = $serverId AND `time_imported` BETWEEN $start AND $finish";
            $result = (int)$this->pdo->query($query)->fetchColumn(0);

            $output['year']['y']['server'][] = array($i, $result);
            $output['year']['x']['server'][] = array($i, $serverCounts[$i]);

            // Total
            $query = "SELECT COUNT(`time_imported`) FROM round WHERE `time_imported` BETWEEN $start AND $finish";
            $result = (int)$this->pdo->query($query)->fetchColumn(0);

            $output['year']['y']['total'][] = array($i, $result);
            $output['year']['x']['total'][] = array($i, $serverCounts[$i]);
            $i++;
        }

        // return chart data
        return $output;
    }
}
