<?php
namespace App\Controllers;

use App\Config;
use App\Hash;
use App\Token;

use App\Models\Core;
use App\Models\Room;
use App\Models\Player;

use Library\HotelApi;
use Library\Json;

use Core\Session;
use Core\Locale;
use \Selly as Selly;

class Api
{
    public $callback = array();
    public $settings;
    public $krewsList;
  
    public function __construct()
    {
        $this->settings = Core::settings();
    }
  
    public function welcome()
    {
        $this->callback = [
                "message" => "hello world"
        ];
      
        return response()->json($this->callback);
    }
  
    public function ssotoken()
    {
        if(empty($_SESSION['auth_ticket'])){
            http_response_code(401);
            return response()->json(['message' => 'no token']);
        }
      
        $this->callback = [
                "ssoToken" => $_SESSION['auth_ticket']
        ];
      
        return response()->json($this->callback);
    }
  
    public function avatars() 
    {
        if(empty($_SESSION['auth_ticket'])) {
            http_response_code(401);
            return response()->json(['message' => 'no token']);
        }
      
        $player = Player::getDataByAuthToken($_SESSION['auth_ticket']);
        if(!$player) {
            http_response_code(401);
            return response()->json(['message' => 'no token']);
        }
      
        $this->callback = [
            [
                "uniqueId" => $_SESSION['auth_ticket'],
                "name" => $player->username,
                "figureString" => $player->look,
                "motto" => $player->motto,
                "habboClubMember" => true,
                "lastWebAccess" => "2020-11-19T11:05:03.000+0000",
                "creationTime" => "2013-12-16T09:57:30.000+0000",
                "banned" => false
            ]
        ];
      
        response()->json($this->callback);
    }
  
    public function select()
    {
        if(empty($_SESSION['auth_ticket'])) {
            http_response_code(401);
            return response()->json(['message' => 'no token']);
        }
      
        $player = Player::getDataByAuthToken($_SESSION['auth_ticket']);
        if(!$player) {
            http_response_code(401);
            return response()->json(['message' => 'no token']);
        }
      
        $this->callback = [
            "ip" => $player->ip_current,
            "uniqueId" => $player->auth_ticket,
            'figureString' => $player->look,
            "motto" => $player->motto,
            "buildersClubMember" => false,
            "habboClubMember" => true,
            "lastWebAccess" => "2020-11-19T11:05:03.000+0000",
            "creationTime" => "2013-12-16T09:57:30.000+0000"
        ];
      
        response()->json($this->callback);
    }
  
    public function login()
    {
        $_POST = json_decode(file_get_contents('php://input'), true);
      
        $player = Player::getDataByEmail($_POST['email']);
        if ($player == null || !Hash::verify($_POST['password'], $player->password)) {
            http_response_code(401);
            return response()->json(['message' => 'wrong password']);
        }
      
        $auth_ticket = Token::authTicket($player->id);
        Player::update($player->id, ["auth_ticket" => $auth_ticket]);
        $_SESSION['auth_ticket'] = $auth_ticket;
      
        $this->callback = [
          [
          'uniqueId' => $auth_ticket,
          'name'  => $player->username,
          'figureString' => $player->look,
          'motto' => $player->motto,
          'buildersClubMember' => false,
          'habboClubMember' => true,
          'lastWebAccess' => "2020-11-19T11:05:03.000+0000",
          'creationTime' => "2013-12-16T09:57:30.000+0000",
          'sessionLogId' => 1337,
          'loginLogId' => 7331,
          'email' => $player->mail,
          'identityId' => 1,
          'emailVerified' => true,
          'identityVerified' => true,
          'identityType' => "HABBO",
          'trusted' => true,
          'force' => ["NONE"],
          'accountId' => $player->id,
          'country' => "nl",
          'traits' => ["USER"],
          'partner' => "NO_PARTNER"
            ]
        ];

        response()->json($this->callback);
    }
  
    public function vote()
    {
        if(isset($this->settings->krews_api_hotel_slug) && isset(request()->player->id))
        {
            if(!isset($_COOKIE['expires_at_seconds']) || $_COOKIE['expires_at_seconds'] < time()) 
            {
                $this->return_to = "https://list.krews.org";
              
                $this->krewsList = json_decode(@file_get_contents($this->return_to . "/api/votes/". $this->settings->krews_api_hotel_slug . "/validate?ip=" . request()->getIp()));
                $this->api_param = $this->settings->krews_api_hotel_slug . "?username=" . request()->player->username;

                if($this->krewsList) {
                    if($this->krewsList->status == 0 && !request()->isAjax()) {
                        redirect($this->return_to . "/vote/" . $this->api_param);
                    }

                    if($this->krewsList->status == 0 && request()->isAjax()) {
                        $this->callback = [
                            'krews_list' => $this->krewsList,
                            'krews_api'  => $this->return_to . "/vote/" . $this->api_param
                        ];
                    }

                    if($this->krewsList->status == 1) {
                        setcookie('expires_at_seconds', $this->krewsList->expires_at_seconds + time(), '/');
                    }
                } else {
                    $this->callback == "not configurated";
                }
            }
        }
        response()->json($this->callback);
    }
  
    public function krews()
    {
        if (strpos(request()->getUserAgent(), $this->settings->krews_api_useragent) !== false) {
          
            if($this->settings->krews_api_advanced_stats) {
                $statistics = [
                    'catalog_pages_count' => Core::getCatalogPages(),
                    'catalog_items_count' => Core::getCatalogItems(),
                    'users_registered'    => Core::getRegisteredUsers(),
                    'items_count'         => Core::getItems(),
                    'online'              => Core::getOnlineCount()
                ];
            } else {
                $statistics = [
                    'online'              => Core::getOnlineCount()
                ];
            }
            
            response()->json($statistics);
        } else {
            $this->noMatch();
        }
    }
  
    public function noMatch()
    {
        response()->json([
            'error' => 'User Agent does not Match',
            'user_agent' => request()->getUserAgent()
        ]);
    }
  
    public function room($callback, $roomId)
    {
        if (!request()->player->online) {
            response()->json(["status" => "success",  "replacepage" => "hotel?room=" . $roomId]);
        }

        $room = \App\Models\Room::getById($roomId);
        if ($room == null) {
            response()->json(["status" => "error", "message" => Locale::get('core/notification/room_not_exists')]);
        }

        HotelApi::execute('forwarduser', array('user_id' => request()->player->id, 'room_id' => $roomId));
        response()->json(["status" => "success",  "replacepage" => "hotel"]);
      
    }

    public function user($callback, $username)
    {
        if($username == 'avatars') {
            $this->avatars();
        }
      
        $user = Player::getDataByUsername($username);
        if(!$user) {
            response()->json([
                'error' => 'User not found'
            ]);
        }

        $response = [
            'username'  => $user->username,
            'motto'     => $user->motto,
            'credits'   => $user->credits,
            'look'      => $user->look,
            'duckets'   => Player::getUserCurrencys($user->id, 0)->amount,
            'diamonds'  => Player::getUserCurrencys($user->id, 5)->amount
        ];

        response()->json($response);
    }
  
    public function online()
    {
        echo Core::getOnlineCount();
    }
  
    public function currencys() 
    {
        response()->json(Core::getCurrencys());
    }

    public static function version()
    {
        $version_cosmic = @file_get_contents("https://raw.githubusercontent.com/devraizer/Cosmic/master/Cosmic/public/version.txt");
        $version = @file_get_contents("version.txt");
        return ($version_cosmic != $version) ? true : false;
    }
}
