<?php
/**
 * BF2Statistics ASP Framework
 *
 * Author:       Steven Wilson
 * Copyright:    Copyright (c) 2006-2021, BF2statistics.com
 * License:      GNU GPL v3
 *
 */
use System\Controller;
use System\Config;
use System\View;

/**
 * Home Module Controller
 *
 * @package Modules
 */
class Home extends Controller
{
    /**
     * @var HomeModel
     */
    protected $homeModel;

    /**
     * @protocol    ANY
     * @request     /ASP/[?:home/]
     * @output      html
     */
    public function index()
    {
        // Require database connection
        $this->requireDatabase();

        // Load model
        $this->loadModel('HomeModel', 'home');

        // check to see if the function apache_get_version exists
        // If it doesn’t, then we create a custom function that returns whatever string is stored in the SERVER_SOFTWARE superglobals variable.
        if(!function_exists('apache_get_version')){
            function apache_get_version(){
                if(!isset($_SERVER['SERVER_SOFTWARE']) || strlen($_SERVER['SERVER_SOFTWARE']) == 0){
                    return false;
                }
                return $_SERVER["SERVER_SOFTWARE"];
            }
        }
        // Create view
        $view = new View('index', 'home');
        $view->set('php_version', PHP_VERSION);
        $view->set('server_name', php_uname('s'));
        $view->set('server_version', apache_get_version());
        $view->set('last_login', date('F jS, Y g:i A T', Config::Get('admin_last_login')));

        // Get database version, and size. Convert size to MB
        $view->set('db_version', $this->homeModel->getDatabaseVersion());
        $view->set('db_size', number_format($this->homeModel->getStatsDataSize() / (1024 * 1024), 2));

        // Games Processed
        $rounds = $this->homeModel->getNumGamesProcessed();
        $view->set('num_rounds', number_format($rounds));

        // Failed count
        $count = $this->homeModel->getNumGamesFailed();
        $view->set('failed_snapshots', number_format($count));

        // Number of players
        $result = $this->homeModel->getNumPlayers();
        $view->set('num_players', number_format($result));

        // Number of Active players
        $active = $this->homeModel->getNumActivePlayersThisWeek();
        $view->set('num_active_players', number_format($active));

        // Set arrow direction (with leading space) for active player count
        $inactive = $this->homeModel->getNumActivePlayersLastWeek();
        $view->set('active_player_raise', ($inactive == $active) ? '' : (($inactive > $active) ? ' down' : ' up'));

        // Number of new players
        $active = $this->homeModel->getNumNewPlayersThisWeek();
        $view->set('num_new_players', number_format($active));

        // Number of new players last week
        $inactive = $this->homeModel->getNumNewPlayersLastWeek();
        $view->set('new_player_raise', ($inactive == $active) ? '' : (($inactive > $active) ? ' down' : ' up'));

        // Number of Active servers
        $active = $this->homeModel->getNumActiveServersThisWeek();
        $view->set('num_active_servers', number_format($active));

        // Number of Active players
        $inactive = $this->homeModel->getNumActiveServersLastWeek();
        $view->set('active_server_raise', ($inactive == $active) ? '' : (($inactive > $active) ? ' down' : ' up'));

        // Attach chart plotting scripts
        $view->attachScript("./frontend/js/flot/jquery.flot.min.js");
        $view->attachScript("./frontend/js/flot/plugins/jquery.flot.tooltip.js");
        $view->attachScript("./frontend/modules/home/js/index.js");

        // Attach stylesheets
        $view->attachStylesheet("/ASP/frontend/css/icons/icol32.css");
        $view->attachStylesheet("/ASP/frontend/modules/home//css/index.css");

        // Draw View
        $view->render();
    }

    /**
     * @protocol    GET
     * @request     /ASP/home/gamesChartData
     * @output      json
     */
    public function getGamesChartData()
    {
        // Hide errors. Specifically, Daylight Savings errors
        ini_set("display_errors", "0");

        // Require database connection
        $this->requireDatabase(true);

        // Load model
        $this->loadModel('HomeModel', 'home');

        // Use our model to do all the hard work
        $data = $this->homeModel->getGamesPlayedChartData();
        echo json_encode($data);
    }

    /**
     * @protocol    GET
     * @request     /ASP/home/rankChartData
     * @output      json
     */
    public function getRankChartData()
    {
        // Hide errors. Specifically, Daylight Savings errors
        ini_set("display_errors", "0");

        // Require database connection
        $this->requireDatabase(true);

        // Load model
        $this->loadModel('HomeModel', 'home');

        // Use our model to do all the hard work
        $data = $this->homeModel->getRankDistChartData();
        echo json_encode($data);
    }
}